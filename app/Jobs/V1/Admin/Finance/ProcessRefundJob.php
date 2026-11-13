<?php

namespace App\Jobs\V1\Admin\Finance;

use App\Events\V1\TransactionRefunded;
use App\Events\V1\TransactionRefundFailed;
use App\Models\AdminLog;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\V1\RefundProcessed;
use App\Notifications\V1\PaymentFailed;
use App\Services\V1\MockAcquiringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessRefundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $transactionId,
        public int $adminId
    ) {}

    public function handle(MockAcquiringService $bank): void
    {
        $admin = User::find($this->adminId);

        DB::transaction(function () use ($bank, $admin) {

            $transaction = Transaction::lockForUpdate()->find($this->transactionId);

            if (!$transaction || $transaction->status !== 'success') {
                Log::warning("RefundJob: Транзакция #{$this->transactionId} не найдена, уже возвращена или не успешна.");
                return;
            }

            // Подгружаем юзера, если вдруг не загружен, для уведомлений
            if (!$transaction->relationLoaded('user')) {
                $transaction->load('user');
            }

            $bankResponse = $bank->refund($transaction);

            // ============================================
            // ОТКАЗ — банк отклонил возврат
            // ============================================
            if (!$bankResponse['success']) {
                // Помечаем refund_status = 'failed' — это позволит админу
                // повторить возврат позже (TransactionController::refund()
                // пропускает 'initiated' и 'success', но пускает 'failed').
                $transaction->markRefundFailed($bankResponse['message']);

                // Оповещаем юзера, что банк отклонил возврат
                if ($transaction->user) {
                    $transaction->user->notify(new PaymentFailed($transaction->id, (float)$transaction->amount, 'Банк отклонил возврат: ' . $bankResponse['message']));
                }

                // Логируем провал в AdminLog (для аудита).
                // before = refund_status был 'initiated', after = 'failed'.
                $before = [
                    'status'        => $transaction->getOriginal('status'),
                    'refund_status' => Transaction::REFUND_STATUS_INITIATED,
                ];
                AdminLog::record('transaction.refund_failed', $transaction, $admin, $before, [
                    'refund_status'  => Transaction::REFUND_STATUS_FAILED,
                    'bank_message'   => $bankResponse['message'],
                    'context'        => [
                        'transaction_id' => $transaction->id,
                        'user_id'        => $transaction->user_id,
                        'amount'         => $transaction->amount,
                        'type'           => $transaction->type,
                        'initiated_by'   => $admin?->id,
                    ],
                ], participants: [$transaction->user_id]);

                Log::error("RefundJob: Банк отклонил возврат #{$transaction->id}. Причина: {$bankResponse['message']}");

                // уведомляем админку через Reverb
                event(new TransactionRefundFailed($transaction, $bankResponse['message']));

                return;
            }

            // ============================================
            // УСПЕХ — банк подтвердил возврат
            // ============================================
            $metaData = [
                'bank_response' => $bankResponse['raw_response'],
                'bank_refund_id' => $bankResponse['provider_refund_id'],
            ];

            // Сохраним reason/comment ДО markAsRefunded — они уже в meta
            // (поставлены в TransactionController::refund), но markAsRefunded
            // делает array_merge, так что не потеряются.
            $refundReason = $transaction->meta['refund_reason'] ?? null;
            $refundComment = $transaction->meta['refund_comment'] ?? null;

            // Модель Transaction сама вызовет событие TransactionRefunded
            // внутри markAsRefunded. Также markAsRefunded поставит
            // meta.refund_status = 'success' и почистит кэш stats.
            $transaction->markAsRefunded($metaData);

            // Логируем успех в AdminLog (для аудита).
            // before = refund_status был 'initiated' + status был 'success',
            // after = refund_status 'success' + status 'refunded'.
            $before = [
                'status'        => Transaction::STATUS_SUCCESS,
                'refund_status' => Transaction::REFUND_STATUS_INITIATED,
            ];
            AdminLog::record('transaction.refund_success', $transaction, $admin, $before, [
                'status'        => Transaction::STATUS_REFUNDED,
                'refund_status' => Transaction::REFUND_STATUS_SUCCESS,
                'reason'        => $refundReason,
                'comment'       => $refundComment,
                'context'       => [
                    'transaction_id'   => $transaction->id,
                    'user_id'          => $transaction->user_id,
                    'amount'           => $transaction->amount,
                    'type'             => $transaction->type,
                    'bank_refund_id'   => $bankResponse['provider_refund_id'] ?? null,
                    'initiated_by'     => $admin?->id,
                ],
            ], participants: [$transaction->user_id]);

            // Отправляем уведомление об успешном возврате
            if ($transaction->user) {
                $reason = $refundReason ?? 'По решению администрации';
                $transaction->user->notify(new RefundProcessed($transaction->id, (float)$transaction->amount, $reason));
            }

            Log::info("RefundJob: Возврат #{$transaction->id} успешно обработан банком.");
        });
    }
}


// Сценарий 1: Банк одобрил возврат
// 1. Job::handle()
// 2.   DB::transaction() {
// 3.     $transaction->markAsRefunded($metaData)
// 4.       → update(status: 'refunded', meta: [..., refund_status: 'success'])
// 5.       → Transaction::clearStatsCache()
// 6.       → event(new TransactionRefunded($this))
// 7.     AdminLog::record('transaction.refund_success', ...) ← НОВОЕ
// 8.     notify(RefundProcessed) юзеру
// 9.   } ← COMMIT
// 10.  ← BroadcastEvent Job → Reverb → фронт ловит refund.processed (success=true)
// 11.  ← RevokePurchasedItems Job → отбирает бонусы
// 12. Фронт: clearRefundPending(id) → спиннер останавливается → toast.success


// Сценарий 2: Банк отклонил возврат
// 1. Job::handle()
// 2.   DB::transaction() {
// 3.     $transaction->markRefundFailed($errorMessage)
// 4.       → update(meta: [refund_status: 'failed', refund_error, refund_failed_at])
// 5.     AdminLog::record('transaction.refund_failed', ...) ← НОВОЕ
// 6.     notify(PaymentFailed) юзеру
// 7.     event(new TransactionRefundFailed(...))
// 8.   } ← COMMIT
// 9.  ← BroadcastEvent Job → Reverb → фронт ловит refund.processed (success=false)
// 10. Фронт: clearRefundPending(id) → спиннер останавливается → toast.error
//     → Админ может повторить возврат (refund_status='failed' → controller пустит)


// Сценарий 3: Двойной клик на "Возврат"
// 1. Админ дважды кликает по кнопке возврата
// 2. Первый POST /refund:
//    - Проверка refund_status === null → OK
//    - markRefundInitiated() → meta.refund_status = 'initiated'
//    - AdminLog::record('transaction.refund_initiated', ...)
//    - ProcessRefundJob::dispatch($tx->id, $admin->id)
// 3. Второй POST /refund (пока Job ещё не отработал):
//    - Проверка refund_status === 'initiated' → 422 "Возврат уже в обработке"
//    - Второй Job НЕ создаётся


// Все сценарии консистентны: фронтенд обновляется только после коммита БД,
// логи аудита пишутся для каждого этапа (initiated → success/failed).