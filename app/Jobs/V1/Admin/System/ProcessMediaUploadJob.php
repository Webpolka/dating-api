<?php

namespace App\Jobs;

use App\Enums\MediaCollection;
use App\Models\Media;
use App\Services\MediaProcessorService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class ProcessMediaUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120; // Ставим жесткий таймаут на обработку, чтобы воркеры не зависали

    public function __construct(
        public int $mediaId,
        public string $tempPath,
        public MediaCollection $collection,
        public string $originalFileName
    ) {}

    public function handle(MediaProcessorService $processor): void
    {
        $startTime = microtime(true);

        // ФИКС: Поднимаем лимит памяти безопасно
        $originalMemoryLimit = ini_get('memory_limit');
        ini_set('memory_limit', '512M');

        $media = Media::find($this->mediaId);
        if (!$media) {
            $this->cleanupTempFile();
            return;
        }

        try {
            $result = $processor->process($this->tempPath, $this->collection);

            $media->update([
                'disk_path' => $result['main_path'],
                'url' => Storage::url($result['main_path']),
                'type' => 'image',
                'mime_type' => $result['mime_type'],
                'variants' => $result['variants'],
            ]);

            // ФИКС: Логируем успешную обработку с таймингом для мониторинга
            $elapsed = round(microtime(true) - $startTime, 2);
            Log::info("Media processed: ID {$this->mediaId}, Collection: {$this->collection->value}, Time: {$elapsed}s");
        } catch (\Exception $e) {
            Log::error("Media Upload Failed: " . $e->getMessage(), ['media_id' => $this->mediaId, 'trace' => $e->getTraceAsString()]);
            $media->delete();
        } finally {
            ini_set('memory_limit', $originalMemoryLimit);
            $this->cleanupTempFile();
        }
    }

    private function cleanupTempFile(): void
    {
        if (Storage::disk('public')->exists($this->tempPath)) {
            Storage::disk('public')->delete($this->tempPath);
        }
    }
}
