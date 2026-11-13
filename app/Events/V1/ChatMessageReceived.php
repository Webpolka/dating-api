<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Realtime-уведомение админки: новое сообщение в private-чате юзеров.
 *
 * Стреляется из MessageObserver::created() когда chat type='private'
 * (т.е. user↔user чат, не support).
 *
 * Канал: private-admin.chats (все staff с доступом к /admin/chats).
 * Фронт (useChatsList) слушает .message.received →
 *   invalidateQueries → refetch списка (last_message, last_message_at, сортировка).
 *
 * ShouldBroadcastNow (не ShouldBroadcast) — отправляется СРАЗУ в Reverb,
 * минуя очередь. Не зависит от queue:work. afterCommit = true — ждёт
 * коммита транзакции (чтобы фронт при refetch увидел новое сообщение).
 *
 * ВАЖНО про нагрузку: этот event стреляет на КАЖДОЕ сообщение в КАЖДОМ
 * private чате. Если 1000 чатов активно и каждый шлёт 1 msg/sec —
 * 1000 broadcasts/sec. Reverb это выдержит (он designed для этого).
 * Если станет узким местом — можно:
 *   - throttle broadcasts (не стрелять чаще 1/sec на chat)
 *   - batching (группировать несколько сообщений в один event)
 * Пока не нужно — оставляем simple.
 */
class ChatMessageReceived implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public int $chatId,
        public int $senderId,
        public string $senderName,
        public string $messageType,
        public ?string $messageBody,
        public ?string $createdAt
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.chats'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id'      => $this->chatId,
            'sender_id'    => $this->senderId,
            'sender_name'  => $this->senderName,
            'message_type' => $this->messageType,
            // Snippet до 120 символов — для preview в списке чатов.
            // Полное body фронт получит через refetch detail.
            'message_body' => $this->messageBody ? \Illuminate\Support\Str::limit($this->messageBody, 120) : null,
            'created_at'   => $this->createdAt,
        ];
    }
}
