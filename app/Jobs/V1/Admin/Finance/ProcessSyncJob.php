<?php

namespace App\Jobs;

use App\Events\TransactionSynced;
use App\Models\AdminLog;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\UserSubscription;
use App\Notifications\PaymentFailed;
use App\Notifications\PaymentSuccessful;
use App\Services\MockAcquiringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Асинхронная синхронизация статуса pending-транзакции с банком.
 *
 * Логика (перенесена из TransactionController::sync()):
 *  1. Запрашиваем банк: $bank->checkStatus($transaction)
 *  2. Если банк подтвердил платеж:
 *     - markAsSuccess()
 *     - Начисляем кредиты ИЛИ создаём подписку
 *     - Уведомляем юзера (PaymentSuccessful)
 *     - Логируем в AdminLog
 *     - Диспатчим broadcast-событие TransactionSynced(success=true)
 *  3. Если банк отклонил:
 *     - markAsFailed()
 *     - Уведомляем юзера (PaymentFailed)
 *     - Логируем в AdminLog
 *     - Диспатчим broadcast-событие TransactionSynced(success=false)
 *
 * Всё оборачиваем в DB::transaction() для консистентности.
 * Broadcast-события уходят ПОСЛЕ коммита (afterCommit = true).
 *
 * Redis lock: TransactionController::sync() ставит лок 'transaction-sync-{id}'
 * на 60 сек для защиты от двойного клика. Здесь, в finally, освобождаем лок
 * — чтобы при быстром ответе банка админ мог сразу повторить sync при
 * необходимости (а не ждать 60 сек).
 */
class ProcessSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $transactionId,
        public int $adminId
    ) {}

    public function handle(MockAcquiringService $bank): void
    {
        $admin = \App\Models\User::find($this->adminId);

        try {
            DB::transaction(function () use ($bank, $admin) {
                $transaction = Transaction::with('user')->lockForUpdate()->find($this->transactionId);

                if (!$transaction) {
                    Log::warning("SyncJob: Транзакция #{$this->transactionId} не найдена.");
                    return;
                }

                if ($transaction->status !== Transaction::STATUS_PENDING) {
                    Log::warning("SyncJob: Транзакция #{$this->transactionId} уже не в статусе pending (текущий: {$transaction->status}).");
                    return;
                }

                if (!$transaction->relationLoaded('user')) {
                    $transaction->load('user');
                }

                $bankResponse = $bank->checkStatus($transaction);

                // ============================================
                // УСПЕХ — банк подтвердил платёж
                // ============================================
                if ($bankResponse['status'] === 'success') {
                    $transaction->markAsSuccess([
                        'synced_by' => $admin?->name ?? 'system',
                        'bank_message' => $bankResponse['message'],
                        'provider_transaction_id' => $bankResponse['provider_transaction_id'] ?? null,
                    ]);

                    if ($transaction->user) {
                        // Начисляем кредиты ИЛИ создаём подписку
                        if ($transaction->type === 'credits' && $transaction->credits_amount) {
                            $balance = $transaction->user->balance()->firstOrCreate([]);
                            $balance->addCredits($transaction->credits_amount);
                        } elseif ($transaction->type === 'subscription' && isset($transaction->meta['plan_id'])) {
                            $plan = SubscriptionPlan::find($transaction->meta['plan_id']);
                            if ($plan) {
                                $user = $transaction->user;
                                $expiresField = $plan->tier === 'premium' ? 'premium_expires_at' : 'vip_expires_at';
                                $currentExpiry = $user->{$expiresField};
                                $startFrom = ($currentExpiry && $currentExpiry->isFuture()) ? $currentExpiry : now();
                                $endsAt = $startFrom->copy()->addDays($plan->duration_days);

                                UserSubscription::create([
                                    'user_id'        => $user->id,
                                    'plan_id'        => $plan->id,
                                    'transaction_id' => $transaction->id,
                                    'tier'           => $plan->tier,
                                    'starts_at'      => now(),
                                    'ends_at'        => $endsAt,
                                    'status'         => 'active',
                                ]);

                                $user->update([$expiresField => $endsAt]);
                            }
                        }

                        $transaction->user->notify(new PaymentSuccessful(
                            $transaction->id,
                            (float) $transaction->amount,
                            $transaction->type
                        ));
                    }

                    $before = ['status' => $transaction->getOriginal('status')];
                    AdminLog::record('transaction.sync_success', $transaction, $admin, $before, [
                        'status'    => 'success',
                        'synced_by' => $admin?->id,
                        'context'   => [
                            'transaction_id'  => $transaction->id,
                            'user_id'         => $transaction->user_id,
                            'amount'          => $transaction->amount,
                            'type'            => $transaction->type,
                            'provider_id'     => $bankResponse['provider_transaction_id'] ?? null,
                        ],
                    ], participants: [$transaction->user_id]);

                    Log::info("SyncJob: Транзакция #{$transaction->id} подтверждена банком.");

                    // Диспатчим broadcast-событие (уйдёт после коммита)
                    event(new TransactionSynced(
                        $transaction,
                        true,
                        $bankResponse['message'] ?? 'Платеж подтвержден банком.'
                    ));

                    return;
                }

                // ============================================
                // ОТКАЗ — банк отклонил платёж
                // ============================================
                $transaction->markAsFailed($bankResponse['message']);

                if ($transaction->user) {
                    $transaction->user->notify(new PaymentFailed(
                        $transaction->id,
                        (float) $transaction->amount,
                        $bankResponse['message']
                    ));
                }

                $before = ['status' => $transaction->getOriginal('status')];
                AdminLog::record('transaction.sync_failed', $transaction, $admin, $before, [
                    'status'       => 'failed',
                    'bank_message' => $bankResponse['message'],
                    'context'      => [
                        'transaction_id' => $transaction->id,
                        'user_id'         => $transaction->user_id,
                        'amount'          => $transaction->amount,
                        'type'            => $transaction->type,
                    ],
                ], participants: [$transaction->user_id]);

                Log::info("SyncJob: Транзакция #{$transaction->id} отклонена банком: {$bankResponse['message']}");

                // Диспатчим broadcast-событие (уйдёт после коммита)
                event(new TransactionSynced(
                    $transaction,
                    false,
                    $bankResponse['message'] ?? 'Банк отклонил платеж.'
                ));
            });
        } finally {
            // Освобождаем Redis lock независимо от того, как завершился Job.
            // Контроллер ставит лок на 60 сек; если банк ответил за 5 сек —
            // нет смысла держать лок ещё 55 сек (админ не сможет повторить).
            // Если Job упал с исключением — лок тоже надо освободить.
            Cache::restoreLock("transaction-sync-{$this->transactionId}", 60)
                ->release();
        }
    }
}
