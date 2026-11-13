<?php

namespace App\Listeners;

use App\Events\TransactionRefunded;
use App\Models\UserSubscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class RevokePurchasedItems implements ShouldQueue
{
    /**
     * Отбираем у юзера то, что он купил (Premium, VIP или кредиты).
     */
    public function handle(TransactionRefunded $event): void
    {
        $transaction = $event->transaction;
        $user = $transaction->user;

        if (!$user) {
            return;
        }

        // ============================================
        // 1. ОТКАТ ПОДПИСКИ (Premium или VIP)
        // ============================================
        if ($transaction->type === 'subscription') {
            
            // Ищем подписку, которую оплатила эта транзакция
            $subscription = UserSubscription::where('transaction_id', $transaction->id)->first();

            if ($subscription) {
                // Просто отменяем подписку и обнуляем срок.
                // UserSubscriptionObserver АВТОМАТИЧЕКИ сработает на это update(),
                // сам пересчитает даты в users и отправит пуш об истечении!
                $subscription->update([
                    'status' => 'canceled',
                    'ends_at' => now(), 
                    'canceled_at' => now(),
                    'is_auto_renew' => false,
                ]);
                Log::info("Подписка #{$subscription->id} ({$subscription->tier}) аннулирована из-за возврата #{$transaction->id}");
            }
        } 
        
        // ============================================
        // 2. ОТКАТ КРЕДИТОВ
        // ============================================
        elseif ($transaction->type === 'credits' && $transaction->credits_amount) {
            $balance = $user->balance;
            if ($balance) {
                // Списываем ровно ту сумму, что была начислена.
                // Если юзер их уже потратил, баланс уйдет в минус (это норма для фин. систем, долг придется покрывать)
                $balance->decrement('credits', $transaction->credits_amount);
                Log::info("Списано {$transaction->credits_amount} кредитов у юзера ID {$user->id} по возврату #{$transaction->id}");
            }
        }
    }
}