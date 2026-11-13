<?php

namespace App\Jobs\V1\Admin\System;

use App\Events\V1\BroadcastStatusUpdated;
use App\Models\Broadcast;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendBroadcastChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 120;

    public function __construct(
        public int $broadcastId,
        public array $userIds
    ) {
        $this->onQueue('broadcasts');
    }

    public function handle(): void
    {
        $broadcast = Broadcast::find($this->broadcastId);
        if (!$broadcast) return;

        $users = User::with('preferences')->whereIn('id', $this->userIds)->get();

        // Notification::send автоматически раскидает уведомления по очередям (ShouldQueue)
        try {
            Notification::send($users, new \App\Notifications\V1\BroadcastNotification(
                broadcastId: $broadcast->id,
                type: $broadcast->type,
                title: $broadcast->title,
                message: $broadcast->message,
                emailBody: $broadcast->email_body,
                actionUrl: $broadcast->data['action_url'] ?? null
            ));

            $successCount = $users->count();
            $failCount = 0;
        } catch (\Exception $e) {
            $successCount = 0;
            $failCount = $users->count();
            Log::error("Ошибка отправки чанка рассылки", [
                'broadcast_id' => $broadcast->id,
                'error' => $e->getMessage()
            ]);
        }

        // Инкрементим счетчики в БД
        if ($successCount > 0) {
            DB::table('broadcasts')->where('id', $broadcast->id)->increment('sent_count', $successCount);
        }
        if ($failCount > 0) {
            DB::table('broadcasts')->where('id', $broadcast->id)->increment('failed_count', $failCount);
        }

        // Проверка завершения
        $stats = DB::table('broadcasts')->where('id', $this->broadcastId)
            ->select(['sent_count', 'failed_count', 'total_recipients', 'status'])
            ->first();

        $isCompleted = false;

        if ($stats && $stats->status === 'sending' && $stats->total_recipients > 0) {
            if ($stats->sent_count + $stats->failed_count >= $stats->total_recipients) {
                // ВАЖНО: добавляем 'updated_at' => now() — иначе raw update
                // не сдвинет updated_at, и рассылка не всплывёт наверх
                // в списке "recently updated first".
                DB::table('broadcasts')
                    ->where('id', $this->broadcastId)
                    ->where('status', 'sending')
                    ->update([
                        'status' => 'sent',
                        'sent_at' => now(),
                        'updated_at' => now(),
                    ]);

                Log::info("Рассылка #{$this->broadcastId} успешно завершена.", [
                    'sent' => $stats->sent_count,
                    'failed' => $stats->failed_count
                ]);

                $isCompleted = true;
            }
        }

        // ============================================
        // REALTIME через Reverb (WebSocket)
        // ============================================
        // Прогресс стреляем с throttle (не чаще раза в 3 сек на рассылку) —
        // иначе при 1000 чанков получим 1000 WebSocket-событий, фронт захлебнётся.
        // Финальное событие (status='sent') стреляем БЕЗ throttle — это важно.
        if ($isCompleted) {
            event(new BroadcastStatusUpdated($broadcast->id));
        } else {
            // Throttled progress event.
            // Cache::lock пытается взять лок на 3 сек. Если лок уже занят
            // (предыдущий чанк уже стрельнул < 3 сек назад) — пропускаем.
            // Лок автоматически снимется через 3 сек — не нужно release().
            $lock = Cache::lock("broadcast-progress-{$broadcast->id}", 3);
            if ($lock->get()) {
                event(new BroadcastStatusUpdated($broadcast->id));
            }
        }
    }
}
