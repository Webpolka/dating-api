<?php

namespace App\Http\Controllers\Api\V1\Admin\Security;

use App\Actions\V1\Admin\Security\ResolveFraudAlertAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Resources\Api\V1\Admin\Security\FraudAlertResource;
use App\Models\FraudAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Антифрод-мониторинг: автоматические сигналы от MessageObserver
 * и других детекторов (стоп-слова, ссылки, массовая рассылка, мультиаккаунт...).
 *
 * Архитектура:
 *  - Controller: валидация (ban type), resolution алерта (lockForUpdate + 404)
 *    + idempotency early-return, форматирование через FraudAlertResource.
 *  - ResolveFraudAlertAction: закрытие алерта (resolve / false_positive),
 *    бан юзера через ToggleUserBanAction, warning в support-чат, AdminLog,
 *    инвалидация кэша.
 *
 * Counts кэшируются на 60 сек (Cache::remember 'admin_fraud_counts').
 * Все mutations вызывают Cache::forget (внутри Action).
 *
 * M1 FIX (idempotency): все 3 mutation-метода используют lockForUpdate +
 * early-return если алерт уже не Open. Без этого два админа, кликающие
 * «Закрыть» одновременно, оба запишут AdminLog + (для warning) оба
 * отправят system message в support-чат.
 *
 * M2 FIX (ban idempotency): resolveAndBan проверяет, забанен ли уже юзер
 * в target ban-статусе. Если да — skip бан (не дублируем notification
 * и AdminLog), но алерт всё равно закрываем (внутри Action).
 */
class FraudAlertController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $search   = $request->string('q', '')->toString();
        $status   = $request->string('status', 'open')->toString();
        $severity = $request->string('severity', 'all')->toString();
        $perPage  = min(max((int) $request->integer('per_page', 20), 10), 100);
        $isId     = $search !== '' && ctype_digit($search);

        // Используем config-based operator (старый код хардкодил 'ilike').
        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        $alerts = FraudAlert::query()
            ->whereNotNull('user_id')
            ->whereHas('user', fn($q) => $q->withTrashed()->excludeStaff())
            ->with([
                'user' => fn($q) => $q->withTrashed()->withAvatar(),
                'admin:id,name',
            ])
            ->when($search !== '', function ($q) use ($search, $isId, $searchOperator) {
                $q->where(function ($q) use ($search, $isId, $searchOperator) {
                    $q->whereHas('user', fn($uq) => $uq->withTrashed()
                        ->where('name', $searchOperator, "%{$search}%")
                        ->orWhere('email', $searchOperator, "%{$search}%"));
                    if ($isId) {
                        $q->orWhere('id', (int) $search);
                    }
                });
            })
            ->when($isId, fn($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]))
            ->when($status !== 'all',   fn($q) => $q->where('status', $status))
            ->when($severity !== 'all', fn($q) => $q->where('severity', $severity))
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'data' => FraudAlertResource::collection($alerts->getCollection())->resolve(request()),
            'meta' => [
                'current_page' => $alerts->currentPage(),
                'last_page'    => $alerts->lastPage(),
                'per_page'     => $alerts->perPage(),
                'total'        => $alerts->total(),
                'from'         => $alerts->firstItem(),
                'to'           => $alerts->lastItem(),
            ],
            // Counts убраны из list response — отдельный endpoint /fraud-alerts/counts
            // со stable queryKey на фронте.
            'status' => $status,
        ]);
    }

    /**
     * GET /api/admin/fraud-alerts/counts — отдельный endpoint для stats.
     * Кэшируется на 60 сек. refetchInterval 30s на фронте.
     */
    public function counts(): JsonResponse
    {
        return response()->json(['stats' => $this->getCounts()]);
    }

    // ============================================
    // ДЕЙСТВИЯ — делегируют ResolveFraudAlertAction
    // ============================================

    /**
     * Закрыть алерт + забанить юзера (если есть).
     *
     * Если user удалён — просто закрываем алерт без бана.
     * Если user уже забанен в target ban-статусе — skip бан (идемпотентность),
     * но алерт всё равно закрываем.
     */
    public function resolveAndBan(Request $request, int $alert, ResolveFraudAlertAction $action): JsonResponse
    {
        $admin = $request->user();
        $banType = $request->string('type', 'permanent')->toString();
        $banTypes = ['shadow', 'temp', 'permanent'];
        if (!in_array($banType, $banTypes, true)) {
            return $this->message('Неверный тип бана', 422);
        }

        // M1 FIX: lockForUpdate — два админа не закроют один алерт одновременно.
        $alert = FraudAlert::where('id', $alert)->lockForUpdate()->first();
        if (!$alert) {
            return $this->notFound('Алерт');
        }

        // M1 FIX: idempotency — если алерт уже закрыт, не делаем повторные действия.
        if (!$alert->isOpen()) {
            $statusLabel = $alert->status->label();
            return $this->message("Алерт уже закрыт (статус: {$statusLabel})");
        }

        $action->resolveAndBan($alert, $admin, $banType);

        return $this->message('Действие применено, алерт закрыт!');
    }

    /**
     * Закрыть алерт + отправить warning в support-чат юзера.
     *
     * System message создаётся ВНУТРИ transaction (в Action) — это правильно,
     * потому что message в БД должно быть atomic с закрытием алерта.
     */
    public function resolveWithWarning(Request $request, int $alert, ResolveFraudAlertAction $action): JsonResponse
    {
        $admin = $request->user();

        $alert = FraudAlert::where('id', $alert)->lockForUpdate()->first();
        if (!$alert) {
            return $this->notFound('Алерт');
        }

        // M1 FIX: idempotency — иначе два админа отправят ДВА warning-сообщения.
        if (!$alert->isOpen()) {
            $statusLabel = $alert->status->label();
            return $this->message("Алерт уже закрыт (статус: {$statusLabel})");
        }

        $action->resolveWithWarning($alert, $admin);

        return $this->message('Предупреждение отправлено в чат поддержки!');
    }

    /**
     * Закрыть алерт как ложное срабатывание.
     */
    public function markAsFalsePositive(Request $request, int $alert, ResolveFraudAlertAction $action): JsonResponse
    {
        $admin = $request->user();

        $alert = FraudAlert::where('id', $alert)->lockForUpdate()->first();
        if (!$alert) {
            return $this->notFound('Алерт');
        }

        // M1 FIX: idempotency — отметка как false_positive второй раз бессмысленна.
        if (!$alert->isOpen()) {
            $statusLabel = $alert->status->label();
            return $this->message("Алерт уже закрыт (статус: {$statusLabel})");
        }

        $action->markAsFalsePositive($alert, $admin);

        return $this->message('Отмечено как ложное срабатывание.');
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getCounts(): array
    {
        return Cache::remember('admin_fraud_counts', 60, function () {
            $stats = FraudAlert::query()
                ->whereNotNull('user_id')
                ->whereHas('user', fn($q) => $q->withTrashed()->excludeStaff())
                ->selectRaw("COUNT(*) as total")
                ->selectRaw("SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) as open")
                ->selectRaw("SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved")
                ->selectRaw("SUM(CASE WHEN status = 'false_positive' THEN 1 ELSE 0 END) as false_positive")
                ->selectRaw("SUM(CASE WHEN severity = 'high' AND status = 'open' THEN 1 ELSE 0 END) as high_priority")
                ->first();

            return [
                'total'          => (int) ($stats?->total ?? 0),
                'open'           => (int) ($stats?->open ?? 0),
                'resolved'       => (int) ($stats?->resolved ?? 0),
                'false_positive' => (int) ($stats?->false_positive ?? 0),
                'high_priority'  => (int) ($stats?->high_priority ?? 0),
            ];
        });
    }
} 