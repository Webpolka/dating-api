<?php

namespace App\Observers;

use App\Models\UserSubscription;
use App\Models\User;
use App\Jobs\SendSubscribeExpiredNotification;
use Illuminate\Support\Facades\Log; 

class UserSubscriptionObserver
{
    public function saved(UserSubscription $subscription): void
    {
        $user = User::find($subscription->user_id);
        if (!$user) {
            return;
        }

        $this->syncUserCache($user, 'premium');
        $this->syncUserCache($user, 'vip');
    }

    public function deleted(UserSubscription $subscription): void
    {
        $user = User::find($subscription->user_id);
        if (!$user) {
            return;
        }

        $this->syncUserCache($user, 'premium');
        $this->syncUserCache($user, 'vip');
    }

    private function syncUserCache(User $user, string $tier): void
    {
        $expiresField = $tier === 'premium' ? 'premium_expires_at' : 'vip_expires_at';

        // ФИКС: Если в таблице users была дата (не null), значит подписка была.
        // И не важно, истекла она секунду назад или день назад — мы уведомляем, что она закончилась!
        $wasActive = !is_null($user->{$expiresField});

        // Ищем актуальную подписку.
        // Статус 'canceled' тоже дает доступ до конца периода (ends_at)!
        $activeSubscription = UserSubscription::where('user_id', $user->id)
            ->where('tier', $tier)
            ->whereIn('status', ['active', 'canceled'])
            ->where('ends_at', '>', now())
            ->orderByDesc('ends_at')
            ->first();

        if ($activeSubscription) {
            $newExpiry = $activeSubscription->ends_at;
            
            // Оптимизация: Обновляем БД только если дата изменилась (избегаем лишних UPDATE)
            if ($user->{$expiresField} != $newExpiry) {
                User::where('id', $user->id)->update([$expiresField => $newExpiry]);
                // Синхронизируем в памяти, чтобы при втором вызове (для vip) объект был свежим
                $user->{$expiresField} = $newExpiry;
            }
        } else {
            // Подписки нет (истекла или вообще не было). Обнуляем дату.
            if ($user->{$expiresField} !== null) {
                User::where('id', $user->id)->update([$expiresField => null]);
                $user->{$expiresField} = null;
            }

            // Если раньше подписка БЫЛА (дата была не null), а сейчас мы её обнулили 
            // (значит она только что окончательно истекла) -> Отправляем пуш!
            if ($wasActive) {
                $planName = ucfirst($tier); // "Premium" или "Vip"
                // ФИКС: Передаем ID юзера, чтобы в очереди лежал скаляр, а не "слепок" модели
                SendSubscribeExpiredNotification::dispatch($user->id, $planName, $tier);
                Log::info("Статус {$tier} истек у юзера ID {$user->id}. Отправлено уведомление.");
            }
        }
    }
}