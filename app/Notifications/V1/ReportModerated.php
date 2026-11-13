<?php

namespace App\Notifications\V1;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Log;

class ReportModerated extends Notification implements ShouldQueue
{
    use Queueable;

    // ФИКС: Передаем ТОЛЬКО скаляры! Никаких моделей в Redis.
    public function __construct(
        protected ?int $reportId = null,
        protected ?string $reportableType = null,
        protected ?int $reportableId = null,
        protected ?string $reportedName = null,
        protected ?string $reason = null,
        protected string $action = 'resolved',
        protected ?string $additionalInfo = null
    ) {}

    public function via($notifiable): array
    {
        $channels = ['database'];

        $emailSettings = $notifiable->email_settings ?? [];

        if ($notifiable->push_enabled && in_array($this->action, ['resolved', 'rejected', 'user_banned', 'photo_deleted'])) {
            $channels[] = 'broadcast';
        }

        if ($notifiable->email_enabled && ($emailSettings['on_event'] ?? true)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        $messages = $this->getMessages();

        $mail = (new MailMessage)
            ->subject($messages['subject'])
            ->greeting('Здравствуйте, ' . $notifiable->name . '!')
            ->line($messages['body']);

        // ФИКС: Используем скаляры вместо связей модели
        if ($this->reportableType === 'App\Models\User') {
            $mail->line('Жалоба на пользователя: ' . ($this->reportedName ?? 'Удален'));
            $mail->line('Причина: "' . ($this->reason ?? 'не указана') . '"');
        } elseif ($this->reportableType === 'App\Models\Photo') {
            $mail->line('Жалоба на фото #' . $this->reportableId);
            $mail->line('Причина: "' . ($this->reason ?? 'не указана') . '"');
        }

        if ($this->action === 'resolved') {
            $mail->line('Модератор рассмотрел вашу жалобу и принял меры.');
        } elseif ($this->action === 'rejected') {
            $mail->line('К сожалению, ваша жалоба не была подтверждена.');
        } elseif ($this->action === 'user_banned') {
            $mail->line('Пользователь ' . ($this->reportedName ?? 'нарушитель') . ' забанен.');
        } elseif ($this->action === 'photo_deleted') {
            $mail->line('Фото удалено с сайта.');
        }

        if ($this->additionalInfo) {
            $mail->line($this->additionalInfo);
        }

        return $mail;
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'report_moderated',
            'title' => $this->getMessages()['title'],
            'message' => $this->buildFinalMessage(),
            'action_url' => url('/'),
            'data' => [
                'report_id' => $this->reportId,
                'action' => $this->action,
                'report_type' => $this->reportableType,
                'reason' => $this->reason,
                'additional_info' => $this->additionalInfo,
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

    private function buildFinalMessage(): string
    {
        $message = $this->getMessages()['message'];
        if ($this->additionalInfo) {
            $message .= ' ' . $this->additionalInfo;
        }
        return $message;
    }

    private function getMessages(): array
    {
        // ФИКС: Берем имя из скаляра
        $userName = $this->reportedName ?? 'нарушитель';

        return match ($this->action) {
            'resolved' => [
                'subject' => 'Ваша жалоба решена',
                'title' => '✅ Жалоба решена',
                'body' => 'Ваша жалоба была рассмотрена и решена модератором.',
                'message' => 'Модератор рассмотрел вашу жалобу и принял соответствующие меры.',
            ],
            'rejected' => [
                'subject' => 'Ваша жалоба отклонена',
                'title' => '❌ Жалоба отклонена',
                'body' => 'Ваша жалоба была отклонена модератором.',
                'message' => 'Модератор не нашел оснований для удовлетворения вашей жалобы.',
            ],
            'user_banned' => [
                'subject' => 'Пользователь забанен по вашей жалобе',
                'title' => '🔒 Пользователь забанен',
                'body' => 'По вашей жалобе пользователь был забанен.',
                'message' => 'Пользователь ' . $userName . ' был забанен на основании жалобы.',
            ],
            'photo_deleted' => [
                'subject' => 'Фото удалено по вашей жалобе',
                'title' => '📸 Фото удалено',
                'body' => 'Фото, на которое вы пожаловались, было удалено.',
                'message' => 'Фото было удалено с сайта на основании вашей жалобы.',
            ],
            default => [
                'subject' => 'Статус жалобы изменен',
                'title' => 'Статус жалобы изменен',
                'body' => 'Статус вашей жалобы был изменен модератором.',
                'message' => 'Статус вашей жалобы был изменен. Подробности в личном кабинете.',
            ],
        };
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Не удалось отправить ReportModerated (Report ID: {$this->reportId}): " . $exception->getMessage());
    }
}
