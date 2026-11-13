<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\UserSubscription;
use App\Models\SubscriptionPlan;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessSubscriptionPaymentGateway implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $transactionId) {}

    public function handle(): void
    {
        $transaction = Transaction::find($this->transactionId);
        if (!$transaction || $transaction->status !== Transaction::STATUS_PENDING) return;

        // Симулируем успешный ответ от банка (с вероятностью 90%)
        $isSuccess = rand(1, 10) > 1;

        if ($isSuccess) {
            $transaction->markAsSuccess();

            $plan = SubscriptionPlan::find($transaction->meta['plan_id'] ?? null);
            if (!$plan) return;

            // NEW: проверяем, это renew() или обычная покупка.
            // meta.action === 'renew' — продлеваем текущую подписку, не создаём новую.
            // meta.action отсутствует (или !== 'renew') — обычная покупка, создаём новую.
            $isRenew = ($transaction->meta['action'] ?? null) === 'renew';

            $user = $transaction->user;

            if ($isRenew) {
                // ===== ПРОДЛЕНИЕ текущей подписки =====
                // Берём её по ID из meta (сохранён в renew() методе контроллера).
                $currentSub = UserSubscription::find($transaction->meta['current_subscription_id'] ?? null);

                // Защита: текущая подписка должна существовать и быть ещё действующей.
                // Если между renew() и выполнением job подписка истекла (маловероятно,
                // но возможно при долгой задержке в очереди) — продлеваем от now().
                $baseEndsAt = ($currentSub && $currentSub->ends_at->isFuture())
                    ? $currentSub->ends_at          // продлеваем от текущего ends_at
                    : now();                         // fallback: продлеваем от сейчас

                $newEndsAt = $baseEndsAt->copy()->addDays($plan->duration_days);

                if ($currentSub) {
                    $currentSub->update([
                        'ends_at'       => $newEndsAt,
                        // Восстанавливаем is_auto_renew из текущего запроса (если пользователь
                        // решил его изменить при продлении). Если не передано — сохраняем старое значение.
                        'is_auto_renew' => $transaction->meta['auto_renew'] ?? $currentSub->is_auto_renew,
                        // На случай если подписка была отменена (status='canceled') —
                        // возобновляем её как 'active'. Пользователь только что заплатил.
                        'status'        => UserSubscription::STATUS_ACTIVE,
                    ]);
                } else {
                    // Edge case: current_subscription_id был, но подписку удалили.
                    // Создаём новую UserSubscription с starts_at = now() (теряем "продление",
                    // но не падаем — фоллбэк на обычную покупку).
                    UserSubscription::create([
                        'user_id'        => $transaction->user_id,
                        'plan_id'        => $plan->id,
                        'transaction_id' => $transaction->id,
                        'tier'           => $plan->tier,
                        'starts_at'      => now(),
                        'ends_at'        => $newEndsAt,
                        'is_auto_renew'  => $transaction->meta['auto_renew'] ?? false,
                        'status'         => UserSubscription::STATUS_ACTIVE,
                    ]);
                }

                // Обновляем user.*_expires_at — Observer UserSubscriptionObserver::saved()
                // тоже это сделает, но дублируем явно, чтобы быть уверенными.
                if ($plan->tier === SubscriptionPlan::TIER_PREMIUM) {
                    $user->premium_expires_at = $newEndsAt;
                    $user->save();
                } elseif ($plan->tier === SubscriptionPlan::TIER_VIP) {
                    $user->vip_expires_at = $newEndsAt;
                    $user->save();
                }
            } else {
                // ===== ОБЫЧНАЯ ПОКУПКА — создаём новую UserSubscription =====
                $startsAt = now();
                $endsAt = $startsAt->copy()->addDays($plan->duration_days);

                UserSubscription::create([
                    'user_id'        => $transaction->user_id,
                    'plan_id'        => $plan->id,
                    'transaction_id' => $transaction->id,
                    'tier'           => $plan->tier,
                    'starts_at'      => $startsAt,
                    'ends_at'        => $endsAt,
                    'is_auto_renew'  => $transaction->meta['auto_renew'] ?? false,
                    'status'         => UserSubscription::STATUS_ACTIVE,
                ]);

                // Обновляем user.*_expires_at напрямую (дублирует Observer, но безопасно).
                if ($plan->tier === SubscriptionPlan::TIER_PREMIUM) {
                    $user->premium_expires_at = $endsAt;
                    $user->save();
                } elseif ($plan->tier === SubscriptionPlan::TIER_VIP) {
                    $user->vip_expires_at = $endsAt;
                    $user->save();
                }
            }
        } else {
            $transaction->markAsFailed("Ошибка: Недостаточно средств (Симуляция)");
        }
    }
}
