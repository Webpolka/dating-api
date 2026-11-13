<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;


use App\Enums\V1\RefundReason;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Finance\RefundTransactionRequest;
use App\Jobs\V1\Admin\Finance\ProcessRefundJob;
use App\Jobs\V1\Admin\Finance\ProcessSyncJob;
use App\Models\AdminLog;
use App\Models\Transaction;
use App\Models\UserSubscription;
use App\Models\SubscriptionPlan;
use App\Notifications\V1\PaymentSuccessful;
use App\Notifications\V1\PaymentFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Финансы и транзакции.
 *
 * Архитектура:
 *  - Список транзакций с фильтрами (статус, тип, период) + поиск по ID/email/provider_transaction_id
 *  - Детали транзакции
 *  - Синхронизация с банком (для pending-транзакций) — через MockAcquiringService
 *  - Возврат средств (refund) — через ProcessRefundJob
 *  - Статистика: счётчики по статусам + выручка за период (кэш 60 сек)
 *
 * Важно про кэш stats:
 *  - clearStatsCache() вызывается ТОЛЬКО когда статус транзакции реально меняется
 *    (через markAsSuccess/markAsFailed/markAsRefunded в модели Transaction)
 *  - В refund() статус НЕ меняется (только meta), кэш НЕ чистим — Job почистит
 *  - В sync() статус НЕ меняется (только диспатчится Job), кэш НЕ чистим — Job почистит
 *  - В ProcessRefundJob::handle() статус меняется на refunded, кэш чистит markAsRefunded()
 *  - В ProcessSyncJob::handle() статус меняется на success/failed, кэш чистят markAs*()
 *
 * Защита от двойного клика:
 *  - sync(): Redis lock на 60 сек — если лок занят, значит sync уже идёт → 422
 *  - refund(): проверка meta.refund_status — если 'initiated' → 422 "уже в обработке",
 *    если 'success' → 422 "уже возвращен", если 'failed' — можно повторить
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

        $userQuery = fn($q) => $q->withTrashed()->select([
            'id',
            'name',
            'email',
            'status',
            'premium_expires_at',
            'vip_expires_at',
            'last_seen',
            'deleted_at',
            'is_verified',
        ])->with(['photos' => fn($sq) => $sq->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb'])
            ->orderByDesc('is_primary')->limit(1)]);

        $query = Transaction::query()
            ->with(['user' => $userQuery])
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
            ->when($isId, function ($q) use ($search) {
                $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
            })
            ->latest('created_at')
            ->latest('id');

        $transactions = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'  => $transactions->map(fn(Transaction $t) => $this->formatTransaction($t)),
            'meta'  => [
                'current_page' => $transactions->currentPage(),
                'last_page'    => $transactions->lastPage(),
                'per_page'     => $transactions->perPage(),
                'total'        => $transactions->total(),
                'from'         => $transactions->firstItem(),
                'to'           => $transactions->lastItem(),
            ],
            'stats' => $this->getStats($dateFilter),
        ]);
    }

    public function show(Request $request, $id): JsonResponse
    {
        $userQuery = fn($q) => $q->withTrashed()->select([
            'id',
            'name',
            'email',
            'status',
            'premium_expires_at',
            'vip_expires_at',
            'last_seen',
            'deleted_at',
            'is_verified',
        ])->with(['photos' => fn($sq) => $sq->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb'])
            ->orderByDesc('is_primary')->limit(1)]);

        $transaction = Transaction::with(['user' => $userQuery])->find((int) $id);
        if (!$transaction) {
            return response()->json(['message' => 'Транзакция не найдена'], 404);
        }

        return response()->json([
            'data' => $this->formatTransaction($transaction, true),
        ]);
    }

    /**
     * Синхронизация статуса с банком (для pending-транзакций).
     *
     * Контроллер только валидирует и диспатчит Job — всю работу с банком
     * делает ProcessSyncJob асинхронно. Это позволяет:
     *  - Не блокировать PHP-FPM на время запроса к банку
     *  - Иметь таймауты nginx/apache (Bank может думать 30+ секунд)
     *  - Обновлять UI через WebSocket, когда банк реально ответит
     *
     * Защита от двойного клика: Redis lock на 60 сек с ключом
     * 'transaction-sync-{id}'. Если лок занят — значит sync уже идёт.
     *
     * @see \App\Jobs\V1\Admin\Finance\ProcessSyncJob
     */
    public function sync(Request $request, $id): JsonResponse
    {
        $admin = $request->user();
        $transaction = Transaction::find((int) $id);

        if (!$transaction) {
            return response()->json(['message' => 'Транзакция не найдена'], 404);
        }

        if ($transaction->status !== Transaction::STATUS_PENDING) {
            return response()->json(['message' => 'Транзакция не в статусе pending'], 422);
        }

        // Защита от двойного клика: пытаемся взять лок на 60 сек.
        // Если лок уже занят — значит sync уже идёт, отдаём 422.
        // Лок автоматически снимется через 60 сек (или когда Job завершится —
        // см. ProcessSyncJob, там есть optional Cache::forget в конце).
        $lock = Cache::lock("transaction-sync-{$transaction->id}", 60);

        if (!$lock->get()) {
            return response()->json([
                'message' => 'Синхронизация уже выполняется. Подождите ответа банка.',
            ], 422);
        }

        // Логируем инициацию sync ДО dispatch — для аудита.
        // Если кто-то нажал sync 5 раз подряд (но лок пропустил только первый) —
        // в логах будет видна каждая попытка.
        AdminLog::record('transaction.sync_initiated', $transaction, $admin, null, [
            'context' => [
                'transaction_id' => $transaction->id,
                'user_id'        => $transaction->user_id,
                'amount'         => $transaction->amount,
                'type'           => $transaction->type,
                'initiated_by'   => $admin->id,
            ],
        ], participants: [$transaction->user_id]);

        // Запускаем Job — она вызовет банк, и если банк подтвердит —
        // поменяет статус и отправит broadcast-событие через Reverb.
        ProcessSyncJob::dispatch($transaction->id, $admin->id);

        return response()->json([
            'message' => 'Запрос отправлен в банк. Ожидайте ответа.',
        ]);
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
        if (!$transaction) {
            return response()->json(['message' => 'Транзакция не найдена'], 404);
        }
        if ($transaction->status !== Transaction::STATUS_SUCCESS) {
            return response()->json(['message' => 'Возврат возможен только для успешных транзакций'], 422);
        }

        $data = $request->validated();

        $reasonEnum = RefundReason::tryFrom($data['reason']);
        if (!$reasonEnum) {
            return response()->json(['message' => 'Неверная причина возврата'], 422);
        }

        // Защита от двойного клика: проверяем статус возврата.
        $refundStatus = $transaction->getRefundStatus();

        if ($refundStatus === Transaction::REFUND_STATUS_INITIATED) {
            return response()->json([
                'message' => 'Возврат уже в обработке. Дождитесь ответа банка.',
            ], 422);
        }

        if ($refundStatus === Transaction::REFUND_STATUS_SUCCESS) {
            return response()->json([
                'message' => 'Возврат уже выполнен.',
            ], 422);
        }

        // refund_status === 'failed' или null — можно инициировать (повторно).

        $before = [
            'status'         => $transaction->getOriginal('status'),
            'refund_status'  => $refundStatus,
        ];

        // Помечаем в meta, что возврат инициирован. Это защитит от двойного
        // клика, пока Job не отработает. ProcessRefundJob обновит refund_status
        // на 'success' (через markAsRefunded) или 'failed' (через markRefundFailed).
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

        // Запускаем Job — она вызовет банк, и если банк подтвердит — поменяет статус на refunded
        ProcessRefundJob::dispatch($transaction->id, $admin->id);

        // 🔴 НЕ чистим кэш stats! Статус остался 'success', статистика не изменилась.
        // Кэш почистит ProcessRefundJob::handle() после реального markAsRefunded().
        // Если почистить здесь — фронт сделает refetch и закэширует устаревшие данные на 60 секунд.

        return response()->json([
            'message' => 'Заявка на возврат отправлена в банк.',
        ]);
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
     * Пока что 60 сек TTL — приемлемо для админки.
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


    private function formatTransaction(Transaction $t, bool $detailed = false): array
    {
        $statusBadge = $t->status_badge;

        // Тип для UI — единственная истина цветов тут.
        // variant = shadcn-базовый вариант, className = кастомные Tailwind-классы (если нужен нестандартный цвет).
        $tier = $t->meta['tier'] ?? null;
        $typeBadge = match ($t->type) {
            'subscription' => [
                'variant'  => 'outline', // прозрачный фон + граница — нейтральная база
                'label'    => $tier === 'vip' ? 'VIP' : 'Premium',
                'className' => $tier === 'vip'
                    ? 'bg-sky-500/15 text-sky-600 border-sky-500/30 hover:bg-sky-500/20 dark:text-sky-400 dark:border-sky-500/40'
                    : 'bg-orange-500/15 text-orange-600 border-orange-500/30 hover:bg-orange-500/20 dark:text-orange-400 dark:border-orange-500/40',
            ],
            'credits' => [
                'variant'  => 'secondary', // стандартный shadcn secondary (серый)
                'label'    => 'Кредиты',
                'className' => '',
            ],
            'refund' => [
                'variant'  => 'destructive', // стандартный shadcn destructive (красный)
                'label'    => 'Возврат',
                'className' => '',
            ],
            default => [
                'variant'  => 'secondary',
                'label'    => $t->type,
                'className' => '',
            ],
        };

        $amountClass = match ($t->status) {
            'refunded' => 'text-destructive font-medium',
            'failed'   => 'text-muted-foreground/50',
            'pending'  => 'text-muted-foreground',
            default    => 'text-green-500 font-medium',
        };

        $data = [
            'id'                    => $t->id,
            'user_id'               => $t->user_id,
            'amount'                => (float) $t->amount,
            'formatted_amount'      => $t->formatted_amount,
            'amount_class'          => $amountClass,
            'currency'              => $t->currency,
            'type'                  => $t->type,
            'type_badge'            => $typeBadge,
            'status'                => $t->status,
            'status_badge'          => $statusBadge,
            'provider'              => $t->provider,
            'provider_transaction_id' => $t->provider_transaction_id,
            'credits_amount'        => $t->credits_amount,
            'created_at'            => $t->created_at?->toIso8601String(),
            'updated_at'            => $t->updated_at?->toIso8601String(),
            'user' => $t->user ? [
                'id'                 => $t->user->id,
                'name'               => $t->user->name,
                'email'              => $t->user->email,
                'avatar_url'         => $t->user->avatar_url,
                'is_online'          => $t->user->is_online,
                'status'             => $t->user->status,
                'deleted_at'         => $t->user->deleted_at?->toIso8601String(),
                'has_active_premium' => $t->user->has_active_premium,
                'has_active_vip'     => $t->user->has_active_vip,
                'is_verified'        => $t->user->is_verified,
            ] : null,
        ];

        if ($detailed) {
            $data['meta'] = $t->meta;
        }

        return $data;
    }
}
