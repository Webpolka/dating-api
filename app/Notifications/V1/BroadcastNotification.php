<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Log;

class BroadcastNotification extends Notification implements ShouldQueue
{
    use Queueable;

    // ФИКС: Передаем только скаляры, чтобы не сериализовать модель в очередь
    public function __construct(
        protected int $broadcastId,
        protected string $type,
        protected string $title,
        protected string $message,
        protected ?string $emailBody = null,
        protected ?string $actionUrl = null
    ) {}

    public function via($notifiable): array
    {
        // КРИТИЧНО: загружаем preferences
        if (!$notifiable->relationLoaded('preferences')) {
            $notifiable->load('preferences');
        }

        $channels = [];
        $emailSettings = $notifiable->email_settings ?? [];

        if ($this->type === 'in_app') {
            return ['database'];
        }

        if ($this->type === 'email' && $notifiable->email_enabled && ($emailSettings['on_broadcast'] ?? true)) {
            $channels[] = 'database';
            $channels[] = 'mail';
        }

        if ($this->type === 'push' && $notifiable->push_enabled) {
            $channels[] = 'database';
            $channels[] = 'broadcast';
        }

        return $channels;
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'broadcast',
            'title' => $this->title,
            'message' => $this->message,
            'action_url' => $this->actionUrl ?? url('/profile'),
            'data' => [
                'broadcast_id' => $this->broadcastId,
                'broadcast_type' => $this->type,
            ]
        ];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->greeting("Здравствуйте, {$notifiable->name}!");

        if (!empty($this->emailBody)) {
            $mail->view('emails.broadcast_html', [
                'content' => $this->emailBody
            ]);
        } else {
            $mail->line($this->message);
        }

        if (!empty($this->actionUrl)) {
            $mail->action('Перейти на сайт', $this->actionUrl);
        }

        return $mail;
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'type' => 'broadcast',
            'title' => $this->title,
            'message' => $this->message,
            'action_url' => $this->actionUrl ?? url('/profile'),
            'timestamp' => now()->toDateTimeString(),
            'data' => [
                'broadcast_id' => $this->broadcastId,
                'broadcast_type' => $this->type,
            ]
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        // ФИКС: Логируем по ID, так как самой модели тут нет
        Log::error("Не удалось отправить BroadcastNotification (ID: {$this->broadcastId}): " . $exception->getMessage());
    }
}
