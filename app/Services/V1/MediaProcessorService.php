<?php

namespace App\Services;

use App\Enums\MediaCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Encoders\WebpEncoder;

// Установить на серваке
// sudo apt-get update
// sudo apt-get install php-imagick
// sudo service php8.2-fpm restart  # (или service apache2 restart, смотря какой веб-сервер)

class MediaProcessorService
{
    public function process(string $relativeTempPath, MediaCollection $collection): array
    {
        $config = config("media.collections.{$collection->value}");

        if (!$config || !isset($config['variants'])) {
            throw new \Exception("Конфигурация для коллекции {$collection->value} не найдена.");
        }

        $disk = Storage::disk('public');

        if (!$disk->exists($relativeTempPath)) {
            throw new \Exception("Временный файл не найден: {$relativeTempPath}");
        }

        $absolutePath = $disk->path($relativeTempPath);
        $mimeType = mime_content_type($absolutePath);

        if ($mimeType === false) {
            $mimeType = 'application/octet-stream';
        }

        if (!str_starts_with($mimeType, 'image/')) {
            throw new \Exception("Неподдерживаемый тип файла: {$mimeType}. Разрешены только изображения.");
        }

        // ФИКС: Imagick экономит в 5 раз больше памяти и работает быстрее! Если он установлен на сервере, берем его.
        if (extension_loaded('imagick')) {
            $manager = new ImageManager(new ImagickDriver());
        } else {
            $manager = new ImageManager(new GdDriver()); // Fallback на GD
        }

        $baseName = Str::random(40);
        $dirPath = "media/{$collection->value}";
        $variants = [];

        foreach ($config['variants'] as $key => $variantConfig) {
            // ✅ v4: decodePath() вместо read()
            $image = $manager->decodePath($absolutePath);

            [$width, $height] = $this->parseSize($variantConfig['size']);
            $fit = $variantConfig['fit'];
            $format = $variantConfig['format'];
            $quality = $variantConfig['quality'] ?? 80;

            if ($fit === 'cover' && $width && $height) {
                $image->cover($width, $height);
            } elseif (($fit === 'contain' || $fit === 'inside') && $width && $height) {
                $image->contain($width, $height);
            } elseif ($width && !$height) {
                if ($image->width() > $width) {
                    $image->scale(width: $width);
                }
            }

            // ✅ v4: Encoder классы вместо toWebp/toJpeg/toPng
            $encoded = match ($format) {
                'webp' => (string) $image->encode(new WebpEncoder(quality: $quality)),
                'jpeg' => (string) $image->encode(new JpegEncoder(quality: $quality)),
                'png'  => (string) $image->encode(new PngEncoder()),
                default => (string) $image->encode(new WebpEncoder(quality: $quality))
            };

            $ext = $format === 'jpeg' ? 'jpg' : $format;
            $fileName = "{$baseName}_{$key}.{$ext}";
            $path = "{$dirPath}/{$fileName}";

            $disk->put($path, $encoded);
            $variants[$key] = $path;

            unset($image, $encoded);
            gc_collect_cycles();
        }

        // ФИКС: Ищем 'lg' для главного пути. Если его нет — берем первый вариант.
        $mainPath = $variants['lg'] ?? $variants['large'] ?? reset($variants);

        if ($config['keep_original'] ?? false) {
            $ext = pathinfo($relativeTempPath, PATHINFO_EXTENSION);
            $fileName = "{$baseName}_orig.{$ext}";
            $origPath = "{$dirPath}/{$fileName}";
            $disk->copy($relativeTempPath, $origPath);
            $variants['orig'] = $origPath;
        }

        return [
            'main_path' => $mainPath,
            'variants' => $variants,
            'mime_type' => $mimeType
        ];
    }

    private function parseSize(string $size): array
    {
        $size = strtolower(trim($size));

        if (str_ends_with($size, 'w')) {
            return [(int) rtrim($size, 'w'), null];
        }

        $parts = explode('x', $size);
        $width = (int) ($parts[0] ?? 0);
        $height = (int) ($parts[1] ?? 0);

        return [$width, $height];
    }
}
