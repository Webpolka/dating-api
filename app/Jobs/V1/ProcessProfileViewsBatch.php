<?php

namespace App\Jobs\V1;

use App\Services\V1\ProfileViewService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessProfileViewsBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Таймаут в секундах (после которого job убивается).
     * 60 сек — больше чем достаточно для bulk upsert 1000 строк.
     */
    public $timeout = 60;

    /**
     * Количество попыток выполнения.
     * 3 — если упал 3 раза, отправляем в failed jobs.
     */
    public $tries = 3;

    /**
     * Задержка между попытками (в секундах).
     * 30 сек — даём БД время восстановиться если была временная проблема.
     */
    public $backoff = 30;

    public function __construct()
    {
        // Нет входных данных — Job работает с Redis-буфером
    }

    /**
     * Execute the job.
     */
    public function handle(ProfileViewService $service): void
    {
        Log::info('🔄 [Job] ProcessProfileViewsBatch started');

        $startTime = microtime(true);

        try {
            $processed = $service->processBatch();

            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            if ($processed > 0) {
                Log::info('✅ [Job] ProcessProfileViewsBatch completed', [
                    'processed' => $processed,
                    'duration_ms' => $durationMs,
                ]);
            } else {
                Log::debug('✅ [Job] ProcessProfileViewsBatch — buffer was empty', [
                    'duration_ms' => $durationMs,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('❌ [Job] ProcessProfileViewsBatch FAILED', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Вызывается когда Job окончательно упал (после всех retry).
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('🚨 [Job] ProcessProfileViewsBatch FAILED permanently', [
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);
    }
}
