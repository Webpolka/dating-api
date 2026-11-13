<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Log;

class SubscriptionExpiringSoonNotification extends Notification implements ShouldQueue
{
    use Queueable;

    // ФИКС: Передаем только скаляры, чтобы не сериализовать модель в Redis
    public function __construct(
        protected int $subscriptionId,
        protected string $planName,
        protected ?string $price, // Например: "999.00 RUB"
        protected string $endsAt, // Например: "25.10.2023 12:00"
        protected bool $isAutoRenew
    ) {}

    public function via($notifiable): array
    {
        $channels = ['database'];

        // ФИКС: Безопасная проверка настроек (защита от TypeError)
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
        $mail = (new MailMessage)
            ->subject('Ваша подписка скоро истекает ⏳')
            ->greeting("Здравствуйте, {$notifiable->name}!")
            ->line("Срок действия вашей подписки «{$this->planName}» истекает {$this->endsAt}.");

        if ($this->isAutoRenew) {
            $mail->line("Автопродление включено. В ближайшее время с вашего счета будет списано {$this->price} для продления подписки.")
                 ->action('Управление подпиской', url('/settings/subscriptions'));
        } else {
            $mail->line("Автопродление отключено. Чтобы не потерять привилегии (безлимит лайков, приоритет в выдаче и др.), продлите подписку.")
                 ->action('Продлить подписку', url('/pricing'));
        }

        return $mail;
    }

    public function toDatabase($notifiable): array
    {
        if ($this->isAutoRenew) {
            $message = "Подписка «{$this->planName}» истекает {$this->endsAt}. Списание произойдет автоматически.";
            $actionUrl = url('/settings/subscriptions');
        } else {
            $message = "Подписка «{$this->planName}» истекает {$this->endsAt}. Не забудьте продлить!";
            $actionUrl = url('/pricing');
        }

        return [
            'type' => 'sub_expiring',
            'title' => '⏳ Подписка истекает',
            'message' => $message,
            'action_url' => $actionUrl,
            'data' => [
                'subscription_id' => $this->subscriptionId,
                'plan_name' => $this->planName,
                'ends_at' => $this->endsAt,
                'is_auto_renew' => $this->isAutoRenew,
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
        Log::error("Не удалось отправить SubscriptionExpiringSoonNotification (Sub ID: {$this->subscriptionId}): " . $exception->getMessage());
    }
}