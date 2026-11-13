<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class VerifyEmail extends BaseVerifyEmail implements ShouldQueue
{
    use Queueable;

    /** Сколько раз пытаться отправить при падении */
    public $tries = 3;

    /** Таймаут между попытками (сек) */
    public $backoff = [10, 30, 60];

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage())
            ->subject('Подтверждение email — ' . config('app.name'))
            ->greeting('Здравствуйте, ' . $notifiable->name . '!')
            ->line('Пожалуйста, подтвердите ваш email-адрес, нажав кнопку ниже.')
            ->action('Подтвердить email', $verificationUrl)
            ->line('Если вы не создавали аккаунт, просто проигнорируйте это письмо.')
            ->salutation('С уважением, команда ' . config('app.name'));
    }

    /**
     * Защищённая ссылка верификации (та же логика, что в BaseVerifyEmail).
     */
    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }
}