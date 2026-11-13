<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Log;

/**
 * Уведомление автору комментария к дневнику о модерации.
 *
 * Паттерн Tinder/Bumble/Reddit: уведомляем ТОЛЬКО автора основного действия.
 * Каскадных потомков НЕ уведомляем — они пострадали «за компанию».
 *
 * Статусы:
 *   - approved  — комментарий одобрен, виден всем
 *   - rejected  — комментарий отклонён с причиной
 *   - deleted   — комментарий удалён модератором (soft-delete или destroy)
 *   - restored  — комментарий восстановлен (техническое, обычно не шлётся)
 *
 * spam убран — теперь это причина отклонения, не отдельный статус.
 * При reject с reason='spam' автор получает обычное уведомление 'rejected'.
 */
class DiaryCommentModerated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected int $commentId,
        protected int $diaryId,
        protected string $commentContent,
        protected string $status
    ) {}

    public function via($notifiable): array
    {
        $channels = ['database'];

        $emailSettings = $notifiable->email_settings ?? [];

        if (
            $notifiable->email_enabled
            && ($emailSettings['on_event'] ?? true)
            && !in_array($this->status, ['restored'])
        ) {
            $channels[] = 'mail';
        }

        if ($notifiable->push_enabled && in_array($this->status, ['approved', 'rejected'])) {
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
            ->line("Комментарий: \"{$this->commentContent}\"")
            ->when($this->status === 'approved', function ($message) {
                return $message->line('Теперь он виден всем пользователям под записью в дневнике.');
            })
            ->when($this->status === 'rejected', function ($message) {
                return $message->line('Вы можете отредактировать комментарий и отправить его снова.');
            })
            ->when($this->status === 'deleted', function ($message) {
                return $message->line('Если вы считаете, что это ошибка, свяжитесь с поддержкой.');
            });
    }

    public function toDatabase($notifiable): array
    {
        $messages = $this->getMessages();

        return [
            'type' => 'diary_comment_moderated',
            'title' => $messages['title'],
            'message' => $messages['message'],
            'action_url' => url('/diaries/' . $this->diaryId),
            'data' => [
                'comment_id' => $this->commentId,
                'diary_id' => $this->diaryId,
                'status' => $this->status,
                'content' => $this->commentContent,
            ]
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        $messages = $this->getMessages();

        return new BroadcastMessage([
            'type' => 'diary_comment_moderated',
            'title' => $messages['title'],
            'message' => $messages['message'],
            'action_url' => url('/diaries/' . $this->diaryId),
            'timestamp' => now()->toDateTimeString(),
            'data' => [
                'comment_id' => $this->commentId,
                'diary_id' => $this->diaryId,
                'status' => $this->status,
                'content' => $this->commentContent,
            ]
        ]);
    }

    private function getMessages(): array
    {
        return match ($this->status) {
            'approved' => [
                'subject' => 'Ваш комментарий к дневнику одобрен',
                'title' => '✅ Комментарий одобрен',
                'body' => 'Ваш комментарий к записи в дневнике был одобрен модератором.',
                'message' => 'Ваш комментарий к записи в дневнике был одобрен модератором и теперь виден всем.',
            ],
            'rejected' => [
                'subject' => 'Ваш комментарий к дневнику отклонен',
                'title' => '❌ Комментарий отклонен',
                'body' => 'Ваш комментарий к записи в дневнике был отклонен модератором.',
                'message' => 'Ваш комментарий был отклонен модератором. Вы можете отредактировать его и отправить снова.',
            ],
            'deleted' => [
                'subject' => 'Ваш комментарий удален',
                'title' => '🗑️ Комментарий удален',
                'body' => 'Ваш комментарий к записи в дневнике был удален модератором.',
                'message' => 'Ваш комментарий был удален модератором. Если вы считаете, что это ошибка, свяжитесь с поддержкой.',
            ],
            'restored' => [
                'subject' => 'Ваш комментарий восстановлен',
                'title' => '🔄 Комментарий восстановлен',
                'body' => 'Ваш комментарий к записи в дневнике был восстановлен модератором.',
                'message' => 'Ваш комментарий был восстановлен модератором и снова виден всем.',
            ],
            default => [
                'subject' => 'Статус комментария изменен',
                'title' => 'Статус комментария изменен',
                'body' => 'Статус вашего комментария был изменен.',
                'message' => 'Статус вашего комментария был изменен модератором.',
            ],
        };
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Не удалось отправить DiaryCommentModerated (ID: {$this->commentId}): " . $exception->getMessage());
    }
}
