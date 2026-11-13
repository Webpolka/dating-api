<?php

namespace App\Http\Controllers\Admin\V1\Moderation;

use App\Actions\Admin\Moderation\ResolveFraudAlertAction;
use App\Http\Controllers\AdminController;
use App\Models\FraudAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Антифрод-мониторинг: автоматические сигналы от MessageObserver
 * и других детекторов (стоп-слова, ссылки, массовая рассылка, мультиаккаунт...).
 *
 * Архитектура:
 *  - Controller (HTTP-слой): валидация (ban type), resolution алерта
 *    (lockForUpdate + 404) + idempotency early-return, форматирование ответа
 *    (список/счётчики)
 *  - ResolveFraudAlertAction (бизнес-логика): закрытие алерта (resolve /
 *    false_positive), бан юзера через ToggleUserBanAction, warning в
 *    support-чат, AdminLog, инвалидация кэша
 *
 * Counts кэшируются на 60 сек (Cache::remember 'admin_fraud_counts').
 * Все mutations вызывают Cache::forget (внутри Action).
 *
 * M1 FIX (idempotency): все 3 mutation-метода используют lockForUpdate +
 *  early-return если алерт уже не Open. Без этого два админа, кликающие
 *  «Закрыть» одновременно, оба запишут AdminLog + (для warning) оба
 *  отправят system message в support-чат.
 *
 * M2 FIX (ban idempotency): resolveAndBan проверяет, забанен ли уже юзер
 *  в target ban-статусе. Если да — skip бан (не дублируем notification
 *  и AdminLog), но алерт всё равно закрываем (внутри Action).
 */
class FraudAlertController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $search = $request->string('q', '')->toString();
        $status = $request->string('status', 'open')->toString();
        $severity = $request->string('severity', 'all')->toString();
        $perPage = min(max((int) $request->integer('per_page', 20), 10), 100);
        $isId = $search !== '' && ctype_digit($search);

        $avatarQuery = fn($q) => $q->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb', 'path_medium'])
            ->orderByDesc('is_primary')
            ->limit(1);

        $query = FraudAlert::query()
            ->whereNotNull('user_id')
            ->whereHas('user', fn($q) => $q->withTrashed()->excludeStaff())
            ->with([
                'user' => fn($q) => $q->withTrashed()->select('id', 'name', 'email', 'status', 'deleted_at', 'premium_expires_at', 'vip_expires_at', 'last_seen', 'is_verified')->with(['photos' => $avatarQuery]),
                'admin:id,name'
            ]);

        // Поиск
        $query->when($search !== '', function ($q) use ($search, $isId) {
            $q->where(function ($q) use ($search, $isId) {
                $q->whereHas('user', function ($uq) use ($search) {
                    $uq->withTrashed()
                        ->where('name', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%");
                });
                if ($isId) {
                    $q->orWhere('id', (int) $search);
                }
            });
        });

        // ✅ Сортировка: при поиске по ID — нужный алерт первым
        $query->when($isId, function ($q) use ($search) {
            $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
        })
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->when($severity !== 'all', fn($q) => $q->where('severity', $severity))
            ->latest('created_at')
            ->latest('id');

        $alerts = $query->paginate($perPage);

        return response()->json([
            'data' => $alerts->map(fn(FraudAlert $alert) => $this->formatAlert($alert)),
            'meta' => [
                'current_page' => $alerts->currentPage(),
                'last_page'    => $alerts->lastPage(),
                'per_page'     => $alerts->perPage(),
                'total'        => $alerts->total(),
                'from'         => $alerts->firstItem(),
                'to'           => $alerts->lastItem(),
            ],
            // Counts убраны из list response — отдельный endpoint /fraud-alerts/counts
            // со stable queryKey на фронте. Раньше counts были здесь → скачкообразный UI
            // при смене status/severity/page (каждая page-loading перезаписывала их).
            'status' => $status,
        ]);
    }

    /**
     * GET /api/admin/fraud-alerts/counts
     *
     * Отдельный endpoint для stats — stable queryKey на фронте.
     * Кэшируется на 60 сек (Cache::remember 'admin_fraud_counts').
     * refetchInterval 30s на фронте → автоматически обновляется.
     *
     * Возвращает { stats: {...} } — формат унифицирован с reports/block-signals.
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
            return response()->json(['message' => 'Неверный тип бана'], 422);
        }

        // M1 FIX: lockForUpdate — два админа не закроют один алерт одновременно
        $alert = FraudAlert::where('id', $alert)->lockForUpdate()->first();
        if (!$alert) {
            return response()->json(['message' => 'Алерт не найден'], 404);
        }

        // M1 FIX: idempotency — если алерт уже закрыт, не делаем повторные действия.
        // Возвращаем 200 + friendly message (как block-signal.ban).
        if (!$alert->isOpen()) {
            $statusLabel = $alert->status->label();
            return response()->json(['message' => "Алерт уже закрыт (статус: {$statusLabel})"], 200);
        }

        $action->resolveAndBan($alert, $admin, $banType);

        return response()->json(['message' => 'Действие применено, алерт закрыт!']);
    }

    /**
     * Закрыть алерт + отправить warning в support-чат юзера.
     *
     * System message создаётся ВНУТРИ transaction (в Action) — это правильно,
     * потому что message в БД должно быть atomic с закрытием алерта.
     * Notifications здесь нет (system message не триггерит UserNotified),
     * так что не нужно переносить после commit.
     */
    public function resolveWithWarning(Request $request, int $alert, ResolveFraudAlertAction $action): JsonResponse
    {
        $admin = $request->user();

        // M1 FIX: lockForUpdate
        $alert = FraudAlert::where('id', $alert)->lockForUpdate()->first();
        if (!$alert) {
            return response()->json(['message' => 'Алерт не найден'], 404);
        }

        // M1 FIX: idempotency — иначе два админа отправят ДВА warning-сообщения
        // в support-чат (одинаковый текст), что засоряет чат и путает юзера.
        if (!$alert->isOpen()) {
            $statusLabel = $alert->status->label();
            return response()->json(['message' => "Алерт уже закрыт (статус: {$statusLabel})"], 200);
        }

        $action->resolveWithWarning($alert, $admin);

        return response()->json(['message' => 'Предупреждение отправлено в чат поддержки!']);
    }

    /**
     * Закрыть алерт как ложное срабатывание.
     */
    public function markAsFalsePositive(Request $request, int $alert, ResolveFraudAlertAction $action): JsonResponse
    {
        $admin = $request->user();

        // M1 FIX: lockForUpdate
        $alert = FraudAlert::where('id', $alert)->lockForUpdate()->first();
        if (!$alert) {
            return response()->json(['message' => 'Алерт не найден'], 404);
        }

        // M1 FIX: idempotency — отметка как false_positive второй раз бессмысленна
        if (!$alert->isOpen()) {
            $statusLabel = $alert->status->label();
            return response()->json(['message' => "Алерт уже закрыт (статус: {$statusLabel})"], 200);
        }

        $action->markAsFalsePositive($alert, $admin);

        return response()->json(['message' => 'Отмечено как ложное срабатывание.']);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function formatAlert(FraudAlert $alert): array
    {
        $user = $alert->user;
        return [
            'id' => $alert->id,
            'trigger_type' => $alert->trigger_type->value,
            'trigger_label' => $alert->trigger_label,
            'trigger_color' => $alert->trigger_type->colorClass(),
            'severity' => $alert->severity->value,
            'severity_label' => $alert->severity_badge['label'],
            'severity_variant' => $alert->severity_badge['variant'],
            'status' => $alert->status->value,
            'status_label' => $alert->status_badge['label'],
            'status_variant' => $alert->status_badge['variant'],
            'meta' => $alert->meta,
            'created_at' => $alert->created_at?->toIso8601String(),
            'is_open' => $alert->isOpen(),
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'is_online' => $user->is_online,
                'status' => $user->status,
                'deleted_at' => $user->deleted_at,
                'has_active_premium' => $user->has_active_premium,
                'has_active_vip' => $user->has_active_vip,
                'is_verified' => $user->is_verified,
            ] : null,
            'admin' => $alert->admin ? ['id' => $alert->admin->id, 'name' => $alert->admin->name] : null,
        ];
    }

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
                'total' => (int) ($stats?->total ?? 0),
                'open' => (int) ($stats?->open ?? 0),
                'resolved' => (int) ($stats?->resolved ?? 0),
                'false_positive' => (int) ($stats?->false_positive ?? 0),
                'high_priority' => (int) ($stats?->high_priority ?? 0),
            ];
        });
    }
}
