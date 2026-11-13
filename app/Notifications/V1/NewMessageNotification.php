<?php

namespace App\Notifications\V1;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// Как использовать в вебе ?
//  $receiver->notify(new NewMessageNotification(
//     chatId: $message->chat_id,
//     messageId: $message->id,
//     senderId: $message->sender_id,
//     // Заранее берем имя, чтобы воркер не делал N+1 запрос к базе
//     senderName: $message->sender?->name, 
//     messageType: $message->type,
//     messageBody: $message->body
// ));

class NewMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    // ФИКС: Передаем ТОЛЬКО скаляры! Никаких моделей в Redis.
    public function __construct(
        protected int $chatId,
        protected int $messageId,
        protected ?int $senderId,
        protected ?string $senderName,
        protected string $messageType,
        protected ?string $messageBody
    ) {}

    public function via($notifiable): array
    {
        // КРИТИЧНО: загружаем preferences, иначе в очереди вернутся дефолты!
        if (!$notifiable->relationLoaded('preferences')) {
            $notifiable->load('preferences');
        }

        $channels = ['database']; // В базу (колокольчик) пишем ВСЕГДА

        $emailSettings = $notifiable->email_settings ?? [];

        if ($notifiable->push_enabled) {
            $channels[] = 'broadcast';
        }

        if ($notifiable->email_enabled && ($emailSettings['on_message'] ?? true)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        $senderName = $this->senderName ?? 'Удаленный пользователь';
        $preview = $this->getMessagePreview();

        return (new MailMessage)
            ->subject('Вам пришло новое сообщение')
            ->greeting("Здравствуйте, {$notifiable->name}!")
            ->line("Пользователь {$senderName} отправил вам сообщение:")
            ->line("\"{$preview}\"")
            ->action('Прочитать в чате', url('/chats/' . $this->chatId));
    }

    public function toDatabase($notifiable): array
    {
        $senderName = $this->senderName ?? 'Удаленный пользователь';
        $preview = $this->getMessagePreview();

        return [
            'type' => 'new_message',
            'title' => '💬 Новое сообщение',
            'message' => "{$senderName}: {$preview}",
            'action_url' => url('/chats/' . $this->chatId),
            'data' => [
                'chat_id' => $this->chatId,
                'message_id' => $this->messageId,
                'sender_id' => $this->senderId,
                'sender_name' => $senderName,
            ]
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        $dbData = $this->toDatabase($notifiable);

        return new BroadcastMessage(array_merge($dbData, [
            'timestamp' => now()->toDateTimeString(),
        ]));
    }

    private function getMessagePreview(): string
    {
        if ($this->messageType === 'text') {
            return Str::limit($this->messageBody, 50);
        }

        return match ($this->messageType) {
            'image' => '📷 Фотография',
            'gift'  => '🎁 Подарок',
            default => 'Новое сообщение',
        };
    }

    public function failed(\Throwable $exception): void
    {
        // ФИКС: Логируем по ID, так как самой модели тут нет
        Log::error("Не удалось отправить NewMessageNotification (Message ID: {$this->messageId}): " . $exception->getMessage());
    }
}
