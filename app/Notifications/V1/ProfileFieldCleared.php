<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Log;

class ProfileFieldCleared extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $field
    ) {}

    public function via($notifiable): array
    {
        $channels = ['database'];

        // ФИКС: Безопасная проверка настроек (защита от TypeError)
        $emailSettings = $notifiable->email_settings ?? [];

        if ($notifiable->email_enabled && ($emailSettings['on_event'] ?? true)) {
            $channels[] = 'mail';
        }

        if ($notifiable->push_enabled) {
            $channels[] = 'broadcast';
        }

        return $channels;
    }

    public function toDatabase($notifiable): array
    {
        $fieldNames = [
            'headline' => 'Заголовок анкеты',
            'bio' => 'Поле «О себе»',
            'looking_for' => 'Поле «Кого я ищу»'
        ];
        $fieldName = $fieldNames[$this->field] ?? 'Поле анкеты';

        return [
            'type' => 'moderation',
            'title' => 'Анкета отредактирована модератором',
            'message' => "Ваше поле «{$fieldName}» было очищено модератором за нарушение правил сервиса. Пожалуйста, заполните его корректно.",
            'action_url' => url('/profile/edit'),
            'data' => [
                'field' => $this->field
            ]
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Ваш профиль был отредактирован')
            ->greeting("Здравствуйте, {$notifiable->name}!")
            ->line('Модератор очистил одно из полей вашей анкеты за нарушение правил.')
            ->line('Пожалуйста, заполните его корректно.')
            ->action('Редактировать анкету', url('/profile/edit'));
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        // ФИКС: Переиспользуем toDatabase (DRY), чтобы не дублировать текст
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
        Log::error("Не удалось отправить ProfileFieldCleared (Field: {$this->field}): " . $exception->getMessage());
    }
}
