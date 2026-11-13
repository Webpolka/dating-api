<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Log;

class PaymentSuccessful extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected int $transactionId,
        protected float $amount,
        protected string $type // subscription или credits
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
        $typeLabel = $this->type === 'subscription' ? 'Подписка' : 'Кредиты';
        return (new MailMessage)
            ->subject('Чек об оплате #' . $this->transactionId)
            ->greeting("Здравствуйте, {$notifiable->name}!")
            ->line("Ваш платеж на сумму {$this->amount} руб. успешно проведен.")
            ->line("Тип операции: {$typeLabel}.")
            ->action('История платежей', url('/settings/finance'));
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'payment_success',
            'title' => '✅ Платеж прошел',
            'message' => "Списано {$this->amount} руб. за " . ($this->type === 'subscription' ? 'подписку' : 'кредиты') . ".",
            'action_url' => url('/settings/finance'),
            'data' => ['transaction_id' => $this->transactionId, 'amount' => $this->amount]
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage(array_merge($this->toDatabase($notifiable), ['timestamp' => now()->toDateTimeString()]));
    }
}