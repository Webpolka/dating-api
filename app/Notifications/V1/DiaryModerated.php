<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Log;

class DiaryModerated extends Notification implements ShouldQueue
{
    use Queueable;

    // ПЕРЕДАЕМ ТОЛЬКО ДАННЫЕ (СКАЛЯРЫ), А НЕ МОДЕЛЬ
    public function __construct(
        protected int $diaryId,
        protected string $diaryTitle,
        protected string $status, // approved, rejected, unpublished
        protected ?string $reason = null
    ) {}

    public function via($notifiable): array
    {
        $channels = ['database'];

        // БЕЗОПАСНАЯ проверка: если email_settings = null, используем пустой массив
        $emailSettings = $notifiable->email_settings ?? [];

        if (
            $notifiable->email_enabled
            && ($emailSettings['on_event'] ?? true)
            && !in_array($this->status, ['unpublished'])
        ) {
            $channels[] = 'mail';
        }

        if ($notifiable->push_enabled && in_array($this->status, ['approved', 'rejected', 'unpublished'])) {
            $channels[] = 'broadcast';
        }

        return $channels;
    }

    public function toMail($notifiable): MailMessage
    {
        $messages = $this->getMessages();

        return (new MailMessage)
            ->subject($messages['subject'])
            ->greeting("Здравствуйте, {$notifiable->name}!")
            ->line($messages['body'])
            ->line("Заголовок записи: {$this->diaryTitle}")
            ->when($this->status === 'rejected', function ($message) {
                return $message->line('Причина: ' . $this->getReasonText())
                    ->line('Вы можете отредактировать запись и отправить ее снова.');
            })
            ->when($this->status === 'unpublished', function ($message) {
                return $message->line('Возможно, запись требует доработки. Она снова доступна для редактирования.');
            });
    }

    public function toDatabase($notifiable): array
    {
        $messages = $this->getMessages();

        return [
            'type' => 'diary_moderated',
            'title' => $messages['title'],
            'message' => $messages['message'],
            'action_url' => url('/diaries/' . $this->diaryId),
            'data' => [
                'diary_id' => $this->diaryId,
                'status' => $this->status,
                'reason' => $this->reason,
            ]
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        $messages = $this->getMessages();

        return new BroadcastMessage([
            'type' => 'diary_moderated',
            'title' => $messages['title'],
            'message' => $messages['message'],
            'action_url' => url('/diaries/' . $this->diaryId),
            'timestamp' => now()->toDateTimeString(),
            'data' => [
                'diary_id' => $this->diaryId,
                'status' => $this->status,
                'reason' => $this->reason,
            ]
        ]);
    }

    private function getReasonText(): string
    {
        if (!$this->reason) return 'Нарушение правил сервиса';
        $enum = \App\Enums\DiaryRejectReason::tryFrom($this->reason);
        return $enum ? $enum->label() : 'Нарушение правил сервиса';
    }

    private function getMessages(): array
    {
        return match ($this->status) {
            'approved' => [
                'subject' => 'Ваша запись в дневнике одобрена',
                'title' => '✅ Запись одобрена',
                'body' => 'Ваша запись в дневнике была одобрена модератором.',
                'message' => "Ваша запись «{$this->diaryTitle}» опубликована.",
            ],
            'rejected' => [
                'subject' => 'Ваша запись в дневнике отклонена',
                'title' => '❌ Запись отклонена',
                'body' => 'Ваша запись в дневнике была отклонена модератором.',
                'message' => "Запись «{$this->diaryTitle}» отклонена. Причина: {$this->getReasonText()}",
            ],
            'unpublished' => [
                'subject' => 'Ваша запись снята с публикации',
                'title' => '⏸️ Запись снята с публикации',
                'body' => 'Ваша запись в дневнике была снята с публикации администратором.',
                'message' => "Запись «{$this->diaryTitle}» снята с публикации и возвращена в черновики.",
            ],
            default => [
                'subject' => 'Статус записи изменен',
                'title' => 'Статус записи изменен',
                'body' => 'Статус вашей записи был изменен.',
                'message' => 'Статус вашей записи был изменен модератором.',
            ],
        };
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Не удалось отправить DiaryModerated (ID: {$this->diaryId}): " . $exception->getMessage());
    }
}
