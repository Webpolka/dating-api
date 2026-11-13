<?php

namespace App\Events;

use App\Models\Broadcast;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Realtime-обновление статуса рассылки через Reverb (WebSocket).
 *
 * Стреляется из:
 *  - SendBroadcastJob::handle() — при markAsSending() (статус 'sending', прогресс 0%)
 *  - SendBroadcastJob::handle() catch — при markAsFailed() (статус 'failed')
 *  - SendBroadcastChunkJob::handle() — после каждого чанка (throttled 3 сек)
 *  - SendBroadcastChunkJob::handle() — при завершении (статус 'sent', БЕЗ throttle)
 *
 * Канал: private-admin.broadcasts (доступ: только staff, см. routes/channels.php).
 * Фронт (useBroadcastsList) слушает .status.updated → invalidateQueries + toast.
 *
 * afterCommit = true — событие уйдёт в Reverb ТОЛЬКО после коммита БД-транзакции.
 * Это гарантирует, что фронт получит актуальные sent_count/failed_count.
 */
class BroadcastStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public int $broadcastId
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.broadcasts'),
        ];
    }

    public function broadcastWith(): array
    {
        // Загружаем свежие данные из БД (после коммита транзакции).
        $broadcast = Broadcast::find($this->broadcastId);
        if (!$broadcast) {
            return [];
        }

        $total = (int) $broadcast->total_recipients;
        $sent = (int) $broadcast->sent_count;
        $failed = (int) $broadcast->failed_count;
        $processed = $sent + $failed;
        $progress = $total > 0 ? min(100, (int) round(($processed / $total) * 100)) : 0;

        return [
            'broadcast_id'      => $broadcast->id,
            'status'            => $broadcast->status,
            'progress'          => $progress,
            'sent_count'        => $sent,
            'failed_count'      => $failed,
            'total_recipients'  => $total,
            'started_at'        => $broadcast->started_at?->toIso8601String(),
            'sent_at'           => $broadcast->sent_at?->toIso8601String(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'status.updated';
    }
}
