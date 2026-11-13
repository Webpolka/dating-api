<?php

namespace App\Console\Commands;

use App\Models\UserSubscription;
use Illuminate\Console\Command;

class ProcessSubscriptions extends Command
{
    protected $signature = 'subscriptions:process';
    protected $description = 'Снимать просроченные VIP-статусы и слать пуши об истечении подписки';

    public function handle(): int
    {
        $this->info('Начинаем обработку подписок...');

        // === 1. СНИМАЕМ ПРОСРОЧЕННЫЕ ПОДПИСКИ ===
        $expiredCount = 0;
        UserSubscription::query()
            ->overdue()
            ->chunkById(100, function ($subscriptions) use (&$expiredCount) {
                foreach ($subscriptions as $subscription) {
                    $subscription->expire(); // Observer сам снимет is_premium и кинет SendVipExpiredNotification
                    $expiredCount++;
                }
            });
        $this->info("Снято просроченных подписок: {$expiredCount}");


        // === 2. ПУШИ: ЗАКАНЧИВАЮТСЯ ЧЕРЕЗ 24 ЧАСА (БЕЗ СПАМА) ===
        $notifyCount = 0;
        UserSubscription::query()
            ->expiringSoon(24)
            ->whereNull('expires_notified_at') // <--- КЛЮЧЕВАЯ ЗАЩИТА ОТ СПАМА! Если уже уведомляли - пропускаем
            ->chunkById(100, function ($subscriptions) use (&$notifyCount) {
                foreach ($subscriptions as $subscription) {
                    \App\Jobs\SendSubscribeExpiringNotification::dispatch($subscription);
                    
                    // Сразу ставим метку, что уведомление отправлено
                    $subscription->update(['expires_notified_at' => now()]);
                    $notifyCount++;
                }
            });
        $this->info("Отправлено уведомлений об истечении: {$notifyCount}");

        return Command::SUCCESS;
    }
}