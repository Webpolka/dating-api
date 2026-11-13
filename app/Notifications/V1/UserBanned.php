<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Log;

class UserBanned extends Notification implements ShouldQueue
{
    use Queueable;

    // ФИКС: Добавлен второй аргумент $reason, чтобы передать причину бана
    public function __construct(
        protected bool $isBanned,
        protected ?string $reason = null
    ) {}

    public function via($notifiable): array
    {
        $channels = ['database'];

        // ФИКС: Безопасная проверка настроек (защита от TypeError если email_settings = null)
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
        if ($this->isBanned) {
            $mail = (new MailMessage)
                ->subject('Ваш аккаунт был заблокирован')
                ->greeting('Здравствуйте, ' . $notifiable->name . '!')
                ->line('Ваш аккаунт был заблокирован администрацией сайта.');
            
            // ФИКС: Выводим причину, если она есть
            if ($this->reason) {
                $mail->line("Причина: {$this->reason}");
            }
            
            return $mail->line('Если вы считаете, что это ошибка, пожалуйста, свяжитесь с поддержкой.')
                        ->action('Связаться с поддержкой', url('/support'));
        }

        $mail = (new MailMessage)
            ->subject('Ваш аккаунт был разблокирован')
            ->greeting('Здравствуйте, ' . $notifiable->name . '!')
            ->line('Ваш аккаунт был успешно разблокирован.');

        if ($this->reason) {
            $mail->line($this->reason);
        }

        return $mail->line('Теперь вы снова можете пользоваться всеми функциями сайта.')
                    ->action('Перейти на сайт', url('/'));
    }

    public function toDatabase($notifiable): array
    {
        if ($this->isBanned) {
            $message = 'Ваш аккаунт был заблокирован администрацией. Обратитесь в поддержку.';
            if ($this->reason) {
                $message .= " Причина: {$this->reason}";
            }

            return [
                'type' => 'user_banned',
                'title' => '🔒 Аккаунт заблокирован',
                'message' => $message,
                'action_url' => url('/support'),
                'data' => [
                    'is_banned' => $this->isBanned,
                    'reason' => $this->reason
                ]
            ];
        }

        return [
            'type' => 'user_unbanned',
            'title' => '✅ Аккаунт разблокирован',
            'message' => $this->reason ?? 'Ваш аккаунт был успешно разблокирован. Добро пожаловать!',
            'action_url' => url('/'),
            'data' => [
                'is_banned' => $this->isBanned,
                'reason' => $this->reason
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
        $status = $this->isBanned ? 'Banned' : 'Unbanned';
        Log::error("Не удалось отправить UserBanned (Status: {$status}, Reason: {$this->reason}): " . $exception->getMessage());
    }
}