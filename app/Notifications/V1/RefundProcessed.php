<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Log;

class RefundProcessed extends Notification implements ShouldQueue
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
            ->subject('Возврат средств по транзакции #' . $this->transactionId)
            ->greeting("Здравствуйте, {$notifiable->name}!")
            ->line("Мы инициировали возврат средств на сумму {$this->amount} руб. по вашей заявке.")
            ->line("Деньги вернутся на вашу карту в течение 3-х рабочих дней.")
            ->line("Причина возврата: {$this->reason}");
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'refund_processed',
            'title' => '💳 Возврат средств',
            'message' => "Возврат {$this->amount} руб. одобрен. Деньги вернутся в течение 3 дней.",
            'action_url' => url('/settings/finance'),
            'data' => ['transaction_id' => $this->transactionId, 'amount' => $this->amount]
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage(array_merge($this->toDatabase($notifiable), ['timestamp' => now()->toDateTimeString()]));
    }
}