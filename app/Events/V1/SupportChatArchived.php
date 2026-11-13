<?php

namespace App\Events\V1;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Realtime-уведомение админки: тикет поддержки заархивирован.
 *
 * Стреляется из SupportChatController::archive().
 * Также из sendMessage() при auto-unarchive — НЕТ, для unarchive отдельный event.
 * toOthers() — не получает отправитель.
 *
 * Канал: private-admin.support (все staff).
 * Фронт слушает .chat.archived →
 *   если filter='archived' — добавляет тикет в список
 *   если filter != 'archived' — убирает тикет из списка (или просто обновляет is_archived=true)
 */
class SupportChatArchived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $chatId,
        public int $archivedById,
        public string $archivedByName,
        public string $archivedAt
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.support'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.archived';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id'          => $this->chatId,
            'archived_by_id'   => $this->archivedById,
            'archived_by_name' => $this->archivedByName,
            'archived_at'      => $this->archivedAt,
        ];
    }
}
