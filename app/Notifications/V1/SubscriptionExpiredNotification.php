<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Log;

class SubscriptionExpiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected ?string $planName = 'Подписка'
    ) {}

    public function via($notifiable): array
    {
        $channels = ['database'];

        // ФИКС: Безопасная проверка настроек (защита от TypeError если email_settings = null)
        // ФИКС: Убрали $notifiable->load('preferences'), т.к. аксессоры User сами безопасно отдают дефолт
        $emailSettings = $notifiable->email_settings ?? [];

        if ($notifiable->push_enabled) {
            $channels[] = 'broadcast';
        }

        if ($notifiable->email_enabled && ($emailSettings['on_event'] ?? true)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Ваша подписка истекла ❌')
            ->greeting("Здравствуйте, {$notifiable->name}!")
            ->line("Срок действия вашей подписки «{$this->planName}» закончился.")
            ->line('Вы лишились премиум-возможностей: расширенных лимитов, приоритетной выдачи и других привилегий.')
            ->action('Вернуть подписку', url('/pricing'));
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'sub_expired',
            'title' => '❌ Подписка истекла',
            'message' => "Подписка «{$this->planName}» закончилась. Продлите, чтобы вернуть привилегии!",
            'action_url' => url('/pricing'),
            'data' => [
                'plan_name' => $this->planName,
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

    public function failed(\Throwable $exception): void
    {
        Log::error("Не удалось отправить SubscriptionExpiredNotification (Plan: {$this->planName}): " . $exception->getMessage());
    }
}