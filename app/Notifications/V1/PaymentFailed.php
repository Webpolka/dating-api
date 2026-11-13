<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Log;

class PaymentFailed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected int $transactionId,
        protected float $amount,
        protected string $reason
    ) {}

    public function via($notifiable): array
    {
        $channels = ['database'];
        $emailSettings = $notifiable->email_settings ?? [];
        if ($notifiable->email_enabled && ($emailSettings['on_event'] ?? true)) {
            $channels[] = 'mail';
        }
        if ($notifiable->push_enabled) {
            $channels[] = 'broadcast';
        }
        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Ошибка платежа #' . $this->transactionId)
            ->greeting("Здравствуйте, {$notifiable->name}!")
            ->line("К сожалению, ваш платеж на сумму {$this->amount} руб. не прошел.")
            ->line("Причина: {$this->reason}")
            ->action('Попробовать снова', url('/pricing'));
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'payment_failed',
            'title' => '❌ Платеж не прошел',
            'message' => "Платеж на {$this->amount} руб. отклонен банком. Причина: {$this->reason}",
            'action_url' => url('/pricing'),
            'data' => ['transaction_id' => $this->transactionId, 'reason' => $this->reason]
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage(array_merge($this->toDatabase($notifiable), ['timestamp' => now()->toDateTimeString()]));
    }
}