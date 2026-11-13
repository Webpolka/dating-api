<?php

namespace App\Jobs;

use App\Models\User;
use App\Notifications\SubscriptionExpiredNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSubscribeExpiredNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    // ФИКС: Передаем ТОЛЬКО ID юзера, чтобы в очереди не лежал "слепок" старой модели
    public function __construct(
        protected int $userId,
        protected string $planName = 'VIP',
        protected string $tier = 'vip'
    ) {}

    public function handle(): void
    {
        // ФИКС: Заново запрашиваем юзера из БД, чтобы получить АКТУАЛЬНЫЕ даты подписок
        $user = User::withTrashed()->find($this->userId);
        
        if (!$user) {
            return; // Юзер удален за время ожидания в очереди
        }

        // ФИКС: Проверяем АКТУАЛЬНЫЕ даты напрямую из БД (через свежий объект)
        $hasActiveSub = false;
        if ($this->tier === 'premium') {
            $hasActiveSub = $user->premium_expires_at && $user->premium_expires_at->isFuture();
        } else {
            $hasActiveSub = $user->vip_expires_at && $user->vip_expires_at->isFuture();
        }

        // Если юзер успел купить новую подписку, пока Джоба лежала в очереди — отменяем отправку!
        if ($hasActiveSub) {
            return; 
        }

        $user->notify(new SubscriptionExpiredNotification($this->planName));
    }
}