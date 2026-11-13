<?php

namespace App\Events\V1;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Realtime-уведомение админки: тикет поддержки прочитан одним из staff.
 *
 * Стреляется из SupportChatController::markAsRead().
 * toOthers() — не получает отправитель (он и так знает что прочитал).
 *
 * Канал: private-admin.support (все staff).
 * Фронт (useSupportChatsList) слушает .chat.read →
 *   обновляет list item: убирает красный бейдж unread, пишет «Прочитал {name}».
 *
 * ShouldBroadcastNow — отправляется СРАЗУ в Reverb, минуя очередь.
 */
class SupportChatRead implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $chatId,
        public int $readById,
        public string $readByName,
        public string $readAt
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.support'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.read';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id'      => $this->chatId,
            'read_by_id'   => $this->readById,
            'read_by_name' => $this->readByName,
            'read_at'      => $this->readAt,
        ];
    }
}
