<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Enums\V1\RefundReason;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Finance\RefundTransactionRequest;
use App\Http\Resources\Api\V1\Admin\Finance\TransactionResource;
use App\Jobs\V1\Admin\Finance\ProcessRefundJob;
use App\Jobs\V1\Admin\Finance\ProcessSyncJob;
use App\Models\AdminLog;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Финансы и транзакции.
 *
 * Архитектура:
 *  - Список транзакций с фильтрами (статус, тип, период) + поиск.
 *  - Детали транзакции (с meta).
 *  - Синхронизация с банком (для pending) — через ProcessSyncJob.
 *  - Возврат средств (refund) — через ProcessRefundJob.
 *  - Статистика: счётчики по статусам + выручка за период (кэш 60 сек).
 *
 * ВАЖНО про кэш stats (НЕ МЕНЯТЬ ЭТУ ЛОГИКУ):
 *  - clearStatsCache() вызывается ТОЛЬКО когда статус транзакции реально
 *    меняется через markAsSuccess/markAsFailed/markAsRefunded в модели.
 *  - В refund() статус НЕ меняется (только meta), кэш НЕ чистим — Job почистит.
 *  - В sync() статус НЕ меняется (только диспатчится Job), кэш НЕ чистим.
 *  - В ProcessRefundJob::handle() статус меняется на refunded, кэш чистит
 *    markAsRefunded(). В ProcessSyncJob — аналогично через markAs*().
 *  Если почистить кэш в контроллере раньше времени — фронт сделает refetch
 *  и закэширует устаревшие данные на 60 секунд.
 *
 * Защита от двойного клика:
 *  - sync(): Redis lock на 60 сек — если лок занят → 422.
 *  - refund(): проверка meta.refund_status — 'initiated' → 422, 'success' → 422,
 *    'failed' → можно повторить, null → можно инициировать.
 */
class TransactionController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $search       = (string) $request->string('q', '');
        $statusFilter = (string) $request->string('status', 'all');
        $typeFilter   = (string) $request->string('type', 'all');
        $dateFilter   = (string) $request->string('period', 'all');
        $perPage      = min(max((int) $request->integer('per_page', 15), 10), 100);
        $page         = max((int) $request->integer('page', 1), 1);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isId           = $search !== '' && ctype_digit($search);

        $transactions = Transaction::query()
            ->with(['user' => fn($q) => $q->withTrashed()->withAvatar()])
            ->when($search !== '', function ($q) use ($search, $searchOperator, $isId) {
                $q->where(function ($q) use ($search, $searchOperator, $isId) {
                    $q->whereHas('user', fn($uq) => $uq
                        ->withTrashed()
                        ->where('name', $searchOperator, "%{$search}%")
                        ->orWhere('email', $searchOperator, "%{$search}%"))
                        ->orWhere('provider_transaction_id', $searchOperator, "%{$search}%");
                    if ($isId) {
                        $q->orWhere('id', (int) $search)->orWhere('user_id', (int) $search);
                    }
                });
            })
            ->when($statusFilter !== 'all', fn($q) => $q->where('status', $statusFilter))
            ->when($typeFilter !== 'all', fn($q) => $q->where('type', $typeFilter))
            ->when($dateFilter !== 'all', function ($q) use ($dateFilter) {
                $date = $this->getPeriodStartDate($dateFilter);
                if ($date) $q->where('created_at', '>=', $date);
            })
            ->when($isId, fn($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]))
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return $this->paginated($transactions, TransactionResource::class, [
            // stats зависят от периода — считаются отдельно, не входят в стандартную meta.
            'stats' => $this->getStats($dateFilter),
        ]);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $transaction = Transaction::with(['user' => fn($q) => $q->withTrashed()->withAvatar()])
            ->find((int) $id);
        if (!$transaction) return $this->notFound('Транзакция');

        // withDetails() — включает meta в ответ (для detail/show).
        // В index() meta не отдаётся (большой JSON, не нужен в карточках).
        return response()->json([
            'data' => (new TransactionResource($transaction))->withDetails()->resolve($request),
        ]);
    }

    /**
     * Синхронизация статуса с банком (для pending-транзакций).
     *
     * Контроллер только валидирует и диспатчит Job — всю работу с банком
     * делает ProcessSyncJob асинхронно (таймауты nginx/apache, обновление UI
     * через WebSocket, когда банк ответит).
     *
     * Защита от двойного клика: Redis lock на 60 сек. Если лок занят — sync
     * уже идёт → 422.
     */
    public function sync(Request $request, $id): JsonResponse
    {
        $admin = $request->user();
        $transaction = Transaction::find((int) $id);

        if (!$transaction) return $this->notFound('Транзакция');

        if ($transaction->status !== Transaction::STATUS_PENDING) {
            return $this->message('Транзакция не в статусе pending', 422);
        }

        // Защита от двойного клика: пытаемся взять лок на 60 сек.
        // Если уже занят — sync идёт, отдаём 422. Лок снимется через 60 сек
        // или когда Job завершится.
        $lock = Cache::lock("transaction-sync-{$transaction->id}", 60);

        if (!$lock->get()) {
            return $this->message('Синхронизация уже выполняется. Подождите ответа банка.', 422);
        }

        // Логируем инициацию sync ДО dispatch — для аудита. Если кто-то нажал
        // sync 5 раз подряд (но лок пропустил только первый) — в логах видна
        // каждая попытка.
        AdminLog::record('transaction.sync_initiated', $transaction, $admin, null, [
            'context' => [
                'transaction_id' => $transaction->id,
                'user_id'        => $transaction->user_id,
                'amount'         => $transaction->amount,
                'type'           => $transaction->type,
                'initiated_by'   => $admin->id,
            ],
        ], participants: [$transaction->user_id]);

        // Job вызовет банк, и если банк подтвердит — поменяет статус и
        // отправит broadcast-событие через Reverb.
        ProcessSyncJob::dispatch($transaction->id, $admin->id);

        return $this->message('Запрос отправлен в банк. Ожидайте ответа.');
    }

    /**
     * Оформление возврата.
     *
     * Защита от двойного клика: проверяем meta.refund_status.
     *  - 'initiated' → возврат уже в обработке, 422
     *  - 'success'   → возврат уже выполнен, 422
     *  - 'failed'    → банк отклонил прошлый возврат, можно повторить
     *  - null        → возврат никогда не инициировался, можно
     */
    public function refund(RefundTransactionRequest $request, $id): JsonResponse
    {
        $admin = $request->user();
        $transaction = Transaction::find((int) $id);
        if (!$transaction) return $this->notFound('Транзакция');

        if ($transaction->status !== Transaction::STATUS_SUCCESS) {
            return $this->message('Возврат возможен только для успешных транзакций', 422);
        }

        $data = $request->validated();

        $reasonEnum = RefundReason::tryFrom($data['reason']);
        if (!$reasonEnum) {
            return $this->message('Неверная причина возврата', 422);
        }

        // Защита от двойного клика: проверяем статус возврата.
        $refundStatus = $transaction->getRefundStatus();

        if ($refundStatus === Transaction::REFUND_STATUS_INITIATED) {
            return $this->message('Возврат уже в обработке. Дождитесь ответа банка.', 422);
        }

        if ($refundStatus === Transaction::REFUND_STATUS_SUCCESS) {
            return $this->message('Возврат уже выполнен.', 422);
        }

        // refund_status === 'failed' или null — можно инициировать (повторно).

        $before = [
            'status'        => $transaction->getOriginal('status'),
            'refund_status' => $refundStatus,
        ];

        // Помечаем в meta, что возврат инициирован. Защита от двойного клика,
        // пока Job не отработает. ProcessRefundJob обновит refund_status на
        // 'success' (через markAsRefunded) или 'failed' (через markRefundFailed).
        $transaction->markRefundInitiated($admin->name);

        // Дописываем reason + comment в meta (markRefundInitiated не трогает их).
        $transaction->update([
            'meta' => array_merge($transaction->meta ?? [], [
                'refund_reason'  => $reasonEnum->label(),
                'refund_comment' => $data['comment'] ?? null,
            ]),
        ]);

        AdminLog::record('transaction.refund_initiated', $transaction, $admin, $before, [
            'refund_status' => Transaction::REFUND_STATUS_INITIATED,
            'reason'        => $reasonEnum->label(),
            'comment'       => $data['comment'] ?? null,
            'context'       => [
                'transaction_id' => $transaction->id,
                'user_id'        => $transaction->user_id,
                'amount'         => $transaction->amount,
                'type'           => $transaction->type,
                'initiated_by'   => $admin->id,
            ],
        ], participants: [$transaction->user_id]);

        // Job вызовет банк, и если банк подтвердит — поменяет статус на refunded.
        ProcessRefundJob::dispatch($transaction->id, $admin->id);

        // НЕ чистим кэш stats! Статус остался 'success', статистика не изменилась.
        // Кэш почистит ProcessRefundJob::handle() после реального markAsRefunded().
        // Если почистить здесь — фронт сделает refetch и закэширует устаревшие
        // данные на 60 секунд.

        return $this->message('Заявка на возврат отправлена в банк.');
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    /**
     * Дата начала периода для фильтра.
     * Вынесено в отдельный метод, чтобы не дублировать логику в index() и getStats().
     */
    private function getPeriodStartDate(string $period): ?\Illuminate\Support\Carbon
    {
        return match ($period) {
            'day'   => now()->startOfDay(),
            'week'  => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            default => null,
        };
    }

    /**
     * Статистика по транзакциям за период.
     *
     * Кэш: 60 секунд на каждый период. Ключ 'admin_transactions_stats_{period}'.
     * Инвалидация: Transaction::clearStatsCache() — вызывается автоматически
     * из markAsSuccess/markAsFailed/markAsRefunded в модели Transaction.
     *
     * Cache stampede: при истечении TTL 1000 одновременных запросов пойдут
     * в БД. На high-load стоит добавить Cache::lock или Cache::flexible.
     * Пока 60 сек TTL — приемлемо для админки.
     */
    private function getStats(string $period): array
    {
        return Cache::remember("admin_transactions_stats_{$period}", 60, function () use ($period) {
            $baseQuery = Transaction::query();

            $date = $this->getPeriodStartDate($period);
            if ($date) {
                $baseQuery->where('created_at', '>=', $date);
            }

            $stats = (clone $baseQuery)->selectRaw("
                COUNT(*) as all_count,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded,
                SUM(CASE WHEN status = 'success' AND type != 'refund' THEN amount ELSE 0 END) as total_revenue
            ")->first();

            return [
                'all'      => (int) ($stats->all_count ?? 0),
                'success'  => (int) ($stats->success ?? 0),
                'pending'  => (int) ($stats->pending ?? 0),
                'failed'   => (int) ($stats->failed ?? 0),
                'refunded' => (int) ($stats->refunded ?? 0),
                'revenue'  => number_format((float) ($stats->total_revenue ?? 0), 2, '.', ' '),
            ];
        });
    }
}
