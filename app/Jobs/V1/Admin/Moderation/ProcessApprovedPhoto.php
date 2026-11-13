<?php

namespace App\Jobs\V1\Admin\Moderation;

use App\Models\Photo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\WebpEncoder;

/**
 * Асинхронная обработка одобренного фото: генерация вариантов (original/large/medium/thumb) в WebP.
 *
 * Запускается из ModeratePhotoAction::approve() и approveAllForUser().
 * Очередь: heavy (тяжёлые задачи ресайза).
 * Попыток: 3, таймаут: 120 сек.
 *
 * Production-ready: Cache Lock против race condition, поддержка S3 через
 * временный файл, проверка размеров, удаление EXIF, декодирование оригинала
 * 1 раз с последующим клонированием.
 */
class ProcessApprovedPhoto implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    /** Максимальное разрешение исходника (50 МП). Больше — reject. */
    private const MAX_MEGAPIXELS = 50_000_000;

    public function __construct(public int $photoId)
    {
        $this->onQueue('heavy');
    }

    private function getStoragePath(int $userId, string $photoType, string $size, string $fileId): string
    {
        $hash = substr(md5((string) $userId), 0, 3);
        return "photos/{$photoType}/{$hash}/{$userId}/{$size}_{$fileId}.webp";
    }

    public function handle(): void
    {
        $originalMemoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', '512M');

        $startTime = microtime(true);
        $paths = [];
        $tempFile = null;
        $lock = null;

        // === 1. Cache Lock против параллельных Job'ов ===
        $lock = Cache::lock("photo_processing_{$this->photoId}", 300);

        if (!$lock->get()) {
            Log::info('Фото уже обрабатывается другой Job', ['photo_id' => $this->photoId]);
            return;
        }

        try {
            $photo = Photo::find($this->photoId);
            if (!$photo) {
                Log::warning('Фото не найдено', ['photo_id' => $this->photoId]);
                return;
            }

            // Идемпотентность
            if ($photo->path_large) {
                Log::info('Фото уже обработано', ['photo_id' => $this->photoId]);
                return;
            }

            $userId = $photo->user_id;
            $photoType = $photo->type;
            $originalDbPath = $photo->path_original;

            $disk = Storage::disk('public');

            if (!$disk->exists($originalDbPath)) {
                Log::warning('Оригинальный файл не найден', ['path' => $originalDbPath]);
                $photo->markAsRejected(null, 'file_missing');
                return;
            }

            // === 2. Совместимость с S3: скачиваем во временный файл ===
            $tempFile = tempnam(sys_get_temp_dir(), 'photo_');
            $stream = $disk->readStream($originalDbPath);
            $dest = fopen($tempFile, 'w');
            stream_copy_to_stream($stream, $dest);
            fclose($stream);
            fclose($dest);

            // === 3. Выбор драйвера с проверкой WebP поддержки ===
            $manager = $this->createImageManager();

            // === 4. Декодирование ОДИН раз ===
            $sourceImage = $manager->decodePath($tempFile);

            // === 5. Проверка размеров ===
            $imageWidth = $sourceImage->width();
            $imageHeight = $sourceImage->height();

            if ($imageWidth * $imageHeight > self::MAX_MEGAPIXELS) {
                Log::warning('Фото слишком большое', [
                    'photo_id' => $this->photoId,
                    'dimensions' => "{$imageWidth}x{$imageHeight}",
                ]);
                $photo->markAsRejected(null, 'too_large');
                return;
            }

            $fileId = Str::random(20);

            $sizes = [
                'original' => ['width' => null, 'quality' => 90],
                'large'    => ['width' => 1600, 'quality' => 85],
                'medium'   => ['width' => 820,  'quality' => 80],
                'thumb'    => ['width' => 200,  'quality' => 70, 'cover' => true],
            ];

            foreach ($sizes as $sizeName => $config) {
                $fullPath = $this->getStoragePath($userId, $photoType, $sizeName, $fileId);

                // Клонируем источник — без повторного чтения с диска
                $frame = clone $sourceImage;

                if (($config['cover'] ?? false) && $config['width']) {
                    $frame->cover($config['width'], $config['width']);
                } elseif ($config['width'] && $frame->width() > $config['width']) {
                    $frame->scale(width: $config['width']);
                }

                // Удаляем EXIF/метаданные (приватность — GPS, модель камеры и т.д.)
                $this->stripMetadata($frame);

                $encoded = (string) $frame->encode(new WebpEncoder(quality: $config['quality']));

                $disk->put($fullPath, $encoded);
                $paths[$sizeName] = $fullPath;

                unset($frame, $encoded);
                gc_collect_cycles();
            }

            unset($sourceImage);
            gc_collect_cycles();

            // === 6. Транзакция: update БД + удаление оригинала ===
            DB::transaction(function () use ($photo, $paths, $disk, $originalDbPath) {
                $photo->update([
                    'path_original' => $paths['original'],
                    'path_large'    => $paths['large'],
                    'path_medium'   => $paths['medium'],
                    'path_thumb'    => $paths['thumb'],
                    // moderated_at НЕ трогаем — его поставил markAsApproved() в момент клика модератора.
                    // Раньше тут было now() — перетирало timestamp модератора временем завершения job'ы.
                ]);

                // Удаляем исходный загруженный файл только после успешного апдейта
                $disk->delete($originalDbPath);
            });

            $duration = round((microtime(true) - $startTime) * 1000);
            $peakMb = round(memory_get_peak_usage(true) / 1024 / 1024);

            Log::info('Фото успешно обработано', [
                'photo_id'    => $this->photoId,
                'user_id'     => $userId,
                'duration_ms' => $duration,
                'memory_peak' => "{$peakMb} MB",
                'source'      => "{$imageWidth}x{$imageHeight}",
            ]);
        } catch (\Throwable $e) {
            // catch (\Throwable) а не (\Exception) — TypeError extends Error (не Exception).
            // Раньше catch (\Exception) не ловил TypeError от markAsRejected(null, ...)
            // при повторном вызове в catch-блоке — job умирала ungracefully.
            Log::error('Ошибка обработки фото', [
                'photo_id' => $this->photoId,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);

            // Чистим недособранные webp-файлы
            $disk = Storage::disk('public');
            foreach ($paths as $failedPath) {
                try {
                    $disk->delete($failedPath);
                } catch (\Throwable $ignore) {
                }
            }

            if ($this->attempts() >= $this->tries) {
                $photo = Photo::find($this->photoId);
                if ($photo && $photo->status === 'approved') {
                    $photo->markAsRejected(null, 'processing_error');
                }
                $this->fail($e);
            } else {
                $this->release(60 * 5);
            }
        } finally {
            // Чистим временный файл
            if ($tempFile !== null && file_exists($tempFile)) {
                @unlink($tempFile);
            }

            // Освобождаем lock
            if ($lock !== null) {
                $lock->release();
            }

            // Возвращаем лимит памяти
            ini_set('memory_limit', $originalMemoryLimit);
        }
    }

    /**
     * Создаёт ImageManager с проверкой поддержки WebP в Imagick.
     * Если Imagick есть, но без WebP делегата — fallback на GD (если доступен).
     */
    private function createImageManager(): ImageManager
    {
        if (extension_loaded('imagick')) {
            $webpSupported = !empty(\Imagick::queryFormats('WEBP'));
            if ($webpSupported) {
                return new ImageManager(new ImagickDriver());
            }
            Log::warning('Imagick установлен без WebP делегата, fallback на GD');
        }

        return new ImageManager(new GdDriver());
    }

    /**
     * Удаляет EXIF и другие метаданные с изображения.
     * Для Imagick — через нативный Imagick::stripImage() (если доступен).
     * Для GD — WebpEncoder по умолчанию не сохраняет метаданные.
     */
    private function stripMetadata($image): void
    {
        try {
            // В Intervention v4 можно удалить EXIF через native Imagemagick object
            if (extension_loaded('imagick') && method_exists($image, 'core')) {
                $core = $image->core();
                if ($core instanceof \Imagick) {
                    $core->stripImage();
                }
            }
        } catch (\Throwable $e) {
            // Не критично — WebpEncoder всё равно не сохраняет метаданные по умолчанию
            Log::debug('stripImage не сработал (не критично)', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
