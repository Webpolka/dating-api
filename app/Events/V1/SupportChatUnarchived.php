<?php

namespace App\Events\V1;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Realtime-уведомение админки: тикет возвращён из архива.
 *
 * Стреляется из SupportChatController::unarchive().
 * Также из sendMessage() при auto-unarchive (когда админ ответил в архивный тикет).
 * toOthers() — не получает отправитель.
 *
 * Канал: private-admin.support (все staff).
 * Фронт слушает .chat.unarchived →
 *   обновляет is_archived=false в list item
 *   если filter='archived' — убирает тикет из списка
 */
class SupportChatUnarchived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $chatId,
        public int $unarchivedById,
        public string $unarchivedByName,
        public string $unarchivedAt,
        public ?string $reason = null
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.support'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.unarchived';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id'            => $this->chatId,
            'unarchived_by_id'   => $this->unarchivedById,
            'unarchived_by_name' => $this->unarchivedByName,
            'unarchived_at'      => $this->unarchivedAt,
            'reason'             => $this->reason,
        ];
    }
}
