<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Realtime-уведомение админки о новом сообщении в чате поддержки.
 *
 * Стреляется из MessageObserver::created() когда chat type='support'.
 *
 * Канал: private-admin.support (все staff).
 * Фронт (useSupportChatsList) слушает .message.received →
 *   invalidateQueries → refetch списка (unread, last message, сортировка).
 *
 * ShouldBroadcastNow (не ShouldBroadcast) — отправляется СРАЗУ в Reverb,
 * минуя очередь. Не зависит от queue:work. afterCommit = true — ждёт
 * коммита транзакции (чтобы фронт при refetch увидел новое сообщение).
 */
class SupportMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public int $chatId,
        public int $senderId,
        public string $senderName,
        public bool $senderIsStaff,
        public string $messageType,
        public ?string $messageBody,
        public ?string $createdAt
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.support'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id'         => $this->chatId,
            'sender_id'       => $this->senderId,
            'sender_name'     => $this->senderName,
            'sender_is_staff' => $this->senderIsStaff,
            'message_type'    => $this->messageType,
            'message_body'    => $this->messageBody ? \Illuminate\Support\Str::limit($this->messageBody, 120) : null,
            'created_at'      => $this->createdAt,
        ];
    }
}
