<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Log;

class UserDeleted extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Уведомление об удалении аккаунта отправляется ТОЛЬКО на почту.
     * В базу (колокольчик) не пишем, так как юзера больше не существует 
     * и сработают foreign key constraints.
     * Настройки email_enabled не проверяем, т.к. UserPreference уже удалены по каскаду,
     * и это юридически важное уведомление.
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Ваш аккаунт был удален')
            ->greeting('Здравствуйте, ' . $notifiable->name . '!')
            ->line('Ваш аккаунт на нашем сайте был безвозвратно удален администрацией.')
            ->line('Все ваши данные, фотографии и комментарии были удалены.')
            ->line('Если вы считаете, что это ошибка, вы можете связаться с поддержкой, ответив на это письмо или указав данный email.')
            ->action('Связаться с поддержкой', url('/support'));
    }

    /**
     * ЗАЩИТА ОЧЕРЕДИ
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Не удалось отправить UserDeleted: " . $exception->getMessage());
    }
}