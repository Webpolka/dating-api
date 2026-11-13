<?php

namespace App\Notifications\V1;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Support\Facades\Log;

class PhotoModerated extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected ?int $photoId,
        protected ?int $userId,
        protected string $status,
        protected int $count = 1
    ) {}

    public function via($notifiable): array
    {
        $channels = ['database'];

        // БЕЗОПАСНАЯ проверка: если email_settings = null, используем пустой массив
        $emailSettings = $notifiable->email_settings ?? [];

        if (
            $notifiable->email_enabled
            && ($emailSettings['on_event'] ?? true)
            && in_array($this->status, ['approved', 'rejected', 'deleted'])
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
        $photoCountText = $this->count > 1 ? " ({$this->count} шт.)" : '';

        return (new MailMessage)
            ->subject($messages['subject'])
            ->greeting("Здравствуйте, {$notifiable->name}!")
            ->line($messages['body'] . $photoCountText)
            ->when($this->status === 'approved', function ($message) {
                return $message->line('Теперь ваше фото видно всем пользователям.');
            })
            ->when($this->status === 'rejected', function ($message) {
                return $message->line('Пожалуйста, ознакомьтесь с правилами публикации фотографий и попробуйте загрузить другое.');
            })
            ->action('Перейти в профиль', url('/profile'));
    }

    public function toDatabase($notifiable): array
    {
        $messages = $this->getMessages();

        return [
            'type' => 'photo_moderated',
            'title' => $messages['title'],
            'message' => $messages['message'],
            'action_url' => url('/profile'),
            'data' => [
                'photo_id' => $this->photoId,
                'user_id' => $this->userId,
                'status' => $this->status,
                'count' => $this->count,
            ]
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        $messages = $this->getMessages();

        return new BroadcastMessage([
            'type' => 'photo_moderated',
            'title' => $messages['title'],
            'message' => $messages['message'],
            'action_url' => url('/profile'),
            'timestamp' => now()->toDateTimeString(),
            'data' => [
                'photo_id' => $this->photoId,
                'user_id' => $this->userId,
                'status' => $this->status,
                'count' => $this->count,
            ]
        ]);
    }

    private function getMessages(): array
    {
        $isMass = $this->count > 1;

        return match ($this->status) {
            'approved' => [
                'subject' => $isMass ? 'Ваши фотографии одобрены' : 'Ваша фотография одобрена',
                'title' => '✅ ' . ($isMass ? 'Фотографии одобрены' : 'Фотография одобрена'),
                'body' => $isMass ? "Модератор одобрил {$this->count} ваших фотографий." : "Модератор одобрил вашу фотографию.",
                'message' => $isMass ? "Ваши фотографии ({$this->count} шт.) прошли модерацию и теперь видны всем." : "Ваша фотография прошла модерацию и теперь видна всем.",
            ],
            'rejected' => [
                'subject' => $isMass ? 'Ваши фотографии отклонены' : 'Ваша фотография отклонена',
                'title' => '❌ ' . ($isMass ? 'Фотографии отклонены' : 'Фотография отклонена'),
                'body' => $isMass ? "Модератор отклонил {$this->count} ваших фотографий." : "Модератор отклонил вашу фотографию.",
                'message' => $isMass ? "Ваши фотографии не соответствуют правилам сообщества и были удалены." : "Ваша фотография не соответствует правилам сообщества и была удалена.",
            ],
            'deleted' => [
                'subject' => 'Ваша фотография удалена',
                'title' => '🗑️ Фотография удалена',
                'body' => 'Администратор удалил вашу фотографию.',
                'message' => 'Ваша фотография была удалена. Если вы считаете это ошибкой, свяжитесь с поддержкой.',
            ],
            default => [
                'subject' => 'Статус фотографии изменен',
                'title' => 'Статус изменен',
                'body' => 'Статус вашей фотографии был изменен.',
                'message' => 'Статус вашей фотографии был изменен модератором.',
            ],
        };
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("Не удалось отправить PhotoModerated (User: {$this->userId}, Photo: {$this->photoId}): " . $exception->getMessage());
    }
}
