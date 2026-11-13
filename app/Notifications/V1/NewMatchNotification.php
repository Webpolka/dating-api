<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Log;

// Как использовать ?
//  $user->notify(new NewMatchNotification(
//     partnerId: $partner->id,
//     partnerName: $partner->name,
//     chatId: $chatId
// ));

class NewMatchNotification extends Notification implements ShouldQueue
{
    use Queueable;

    // ФИКС: Передаем ТОЛЬКО скаляры! Никаких моделей в Redis.
    public function __construct(
        protected int $partnerId,
        protected string $partnerName,
        protected int $chatId
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

        if ($notifiable->email_enabled && ($emailSettings['on_like'] ?? true)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        // ФИКС: Используем скаляр $this->partnerName
        $partnerName = $this->partnerName ?: 'Пользователь';

        return (new MailMessage)
            ->subject('У вас взаимная симпатия! ❤️')
            ->greeting("Здравствуйте, {$notifiable->name}!")
            ->line("Вы понравились друг другу! Вы и {$partnerName} выразили взаимную симпатию.")
            ->line('Не упустите шанс начать общение!')
            ->action('Написать сообщение', url('/chats/' . $this->chatId));
    }

    public function toDatabase($notifiable): array
    {
        $partnerName = $this->partnerName ?: 'Пользователь';

        return [
            'type' => 'new_match',
            'title' => '❤️ Взаимная симпатия!',
            'message' => "Вы понравились друг другу! Начните общение с {$partnerName}.",
            'action_url' => url('/chats/' . $this->chatId),
            'data' => [
                'partner_id' => $this->partnerId,
                'partner_name' => $partnerName,
                'chat_id' => $this->chatId,
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

    /**
     * ЗАЩИТА ОЧЕРЕДИ
     */
    public function failed(\Throwable $exception): void
    {
        // ФИКС: Логируем по ID, так как самой модели тут нет
        Log::error("Не удалось отправить NewMatchNotification (Partner ID: {$this->partnerId}, Chat ID: {$this->chatId}): " . $exception->getMessage());
    }
}
