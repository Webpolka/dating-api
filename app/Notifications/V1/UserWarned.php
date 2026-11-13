<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Log;

class UserWarned extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $reason
    ) {}

    public function via($notifiable): array
    {
        $channels = ['database'];
        
        // ФИКС: Безопасная проверка настроек (защита от TypeError если email_settings = null)
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
        return [
            'type' => 'moderation',
            'title' => '⚠️ Вам вынесено предупреждение',
            'message' => "Вы нарушили правила сервиса (Причина: {$this->reason}). Пожалуйста, будьте взаимовежливы. Повторное нарушение приведет к блокировке аккаунта.",
            'action_url' => url('/profile'),
            'data' => [
                'reason' => $this->reason
            ]
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Вам вынесено предупреждение')
            ->greeting("Здравствуйте, {$notifiable->name}!")
            ->line('Модератор вынес вам предупреждение за нарушение правил сервиса.')
            ->line("Причина: {$this->reason}")
            ->line('Пожалуйста, ознакомьтесь с правилами сайта. Повторные нарушения приведут к блокировке.');
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
        Log::error("Не удалось отправить UserWarned (Reason: {$this->reason}): " . $exception->getMessage());
    }
}