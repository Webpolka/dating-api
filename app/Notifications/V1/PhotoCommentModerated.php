<?php

namespace App\Notifications\V1;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Log;

class PhotoCommentModerated extends Notification implements ShouldQueue
{
    use Queueable;

    // ПЕРЕДАЕМ ТОЛЬКО ДАННЫЕ (СКАЛЯРЫ), А НЕ МОДЕЛЬ
    public function __construct(
        protected int $commentId,
        protected int $photoId,
        protected string $commentContent, // Сохраняем текст на момент отправки
        protected string $status
    ) {}

    public function via($notifiable): array
    {
        $channels = ['database'];

        // БЕЗОПАСНАЯ проверка
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
                return $message->line('Теперь он виден всем пользователям.');
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
            'type' => 'comment_moderated',
            'title' => $messages['title'],
            'message' => $messages['message'],
            'action_url' => url('/photos/' . $this->photoId),
            'data' => [
                'comment_id' => $this->commentId,
                'photo_id' => $this->photoId,
                'status' => $this->status,
                'content' => $this->commentContent,
            ]
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        $messages = $this->getMessages();

        return new BroadcastMessage([
            'type' => 'comment_moderated',
            'title' => $messages['title'],
            'message' => $messages['message'],
            'action_url' => url('/photos/' . $this->photoId),
            'timestamp' => now()->toDateTimeString(),
            'data' => [
                'comment_id' => $this->commentId,
                'photo_id' => $this->photoId,
                'status' => $this->status,
                'content' => $this->commentContent,
            ]
        ]);
    }

    private function getMessages(): array
    {
        return match ($this->status) {
            'approved' => [
                'subject' => 'Ваш комментарий одобрен',
                'title' => '✅ Комментарий одобрен',
                'body' => 'Ваш комментарий был одобрен модератором.',
                'message' => 'Ваш комментарий был одобрен модератором и теперь виден всем.',
            ],
            'rejected' => [
                'subject' => 'Ваш комментарий отклонен',
                'title' => '❌ Комментарий отклонен',
                'body' => 'Ваш комментарий был отклонен модератором.',
                'message' => 'Ваш комментарий был отклонен модератором. Вы можете отредактировать его и отправить снова.',
            ],
            'spam' => [
                'subject' => 'Ваш комментарий помечен как спам',
                'title' => '🚫 Комментарий помечен как спам',
                'body' => 'Ваш комментарий был помечен как спам.',
                'message' => 'Ваш комментарий был помечен как спам. Пожалуйста, ознакомьтесь с правилами сообщества.',
            ],
            'deleted' => [
                'subject' => 'Ваш комментарий удален',
                'title' => '🗑️ Комментарий удален',
                'body' => 'Ваш комментарий был удален модератором.',
                'message' => 'Ваш комментарий был удален модератором. Если вы считаете, что это ошибка, свяжитесь с поддержкой.',
            ],
            'restored' => [
                'subject' => 'Ваш комментарий восстановлен',
                'title' => '🔄 Комментарий восстановлен',
                'body' => 'Ваш комментарий был восстановлен модератором.',
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

    /**
     * ЗАЩИТА ОЧЕРЕДИ
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("Не удалось отправить PhotoCommentModerated (ID: {$this->commentId}): " . $exception->getMessage());
    }
}
