<?php

namespace App\Http\Controllers\Admin\V1\Moderation;

use App\Actions\Admin\Moderation\ModerateBlockSignalAction;
use App\Enums\UserBlockReason;
use App\Http\Controllers\AdminController;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Антифрод-сигналы: юзеры, которых блокит много других юзеров.
 *
 * Логика: чем больше у юзера блокировок (особенно за последние 7 дней),
 * тем вероятнее, что он спамер / скамер / проститутка.
 *
 * SQL-паттерн "Group-By-Aggregate-Paginate":
 *  1. GROUP BY blocked_id (использует индекс user_blocks [blocked_id, created_at])
 *  2. HAVING COUNT(*) >= threshold — отсекаем нормальных юзеров с 1-2 блокировками
 *  3. paginate() — пагинация по сгруппированному набору (PostgreSQL это умеет через subquery)
 *  4. Вторым запросом подтягиваем юзеровwhereIn — не тащим 10000 строк в память
 *
 * Архитектура:
 *  - Controller (HTTP-слой): валидация (ban type), resolution юзера
 *    (lockForUpdate + 404) + staff-check + idempotency early-return,
 *    форматирование ответа (список/счётчики)
 *  - ModerateBlockSignalAction (бизнес-логика): бан юзера по сигналу через
 *    ToggleUserBanAction — внутри транзакции, с AdminLog и инвалидацией кэша
 *
 * Counts кэшируются на 60 сек (Cache::remember 'admin_block_signal_counts').
 * Все mutations вызывают Cache::forget (внутри Action).
 */
class BlockSignalController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $threshold = min(max((int) $request->integer('threshold', 3), 1), 100);
        $perPage   = min(max((int) $request->integer('per_page', 20), 10), 100);
        $search    = $request->string('q', '')->toString();
        $page      = max((int) $request->integer('page', 1), 1);

        $avatarQuery = fn($q) => $q->select([
            'id',
            'user_id',
            'is_primary',
            'status',
            'path_thumb',
            'path_medium',
            'path_large',
            'path_original',
        ])
            ->orderByDesc('is_primary')
            ->limit(1);

        // ШАГ 1: GROUP BY только по user_blocks (быстро через индекс)
        $blocksQuery = UserBlock::select('blocked_id')
            ->selectRaw('COUNT(*) as total_blocks_count')
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as recent_blocks_count', [now()->subDays(7)])
            ->selectRaw('MAX(created_at) as last_block_at')
            ->groupBy('blocked_id')
            ->havingRaw('COUNT(*) >= ?', [$threshold]);

        // Поиск: если число — это ID юзера, иначе — name/email
        $isId = $search !== '' && ctype_digit($search);

        if ($search !== '') {
            $blocksQuery->whereExists(function ($q) use ($search, $isId) {
                $q->select(DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.id', 'user_blocks.blocked_id')
                    ->when($isId, function ($uq) use ($search) {
                        // Поиск по ID: точное совпадение
                        $uq->where('users.id', (int) $search);
                    }, function ($uq) use ($search) {
                        // Поиск по name/email: частичное совпадение
                        $uq->where('users.name', 'ilike', "%{$search}%")
                            ->orWhere('users.email', 'ilike', "%{$search}%");
                    });
            });
        }

        // Сортировка: при поиске по ID — искомый юзер ВСЕГДА первым.
        // blocked_id = ID юзера (FK), без JOIN — сортируем прямо по нему.
        $blocksQuery->when($isId, function ($q) use ($search) {
            $q->orderByRaw('CASE WHEN blocked_id = ? THEN 0 ELSE 1 END', [(int) $search]);
        })
            ->orderByRaw('recent_blocks_count DESC')
            ->orderByRaw('total_blocks_count DESC');

        $paginatedBlocks = $blocksQuery->paginate($perPage, ['*'], 'page', $page);

        // ШАГ 2: Достаём ID юзеров текущей страницы
        $userIds = $paginatedBlocks->getCollection()
            ->pluck('blocked_id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        // ШАГ 3: Загружаем юзеров одним запросом (с trashed — на случай деактивированных)
        $users = User::withTrashed()
            ->where('role', User::ROLE_USER)
            ->whereIn('id', $userIds)
            ->with(['photos' => $avatarQuery])
            ->get()
            ->keyBy('id');

        // ШАГ 4: Склеиваем юзеров со счётчиками блокировок, сохраняем порядок пагинации
        $data = $paginatedBlocks->getCollection()
            ->map(function ($item) use ($users) {
                $user = $users->get($item->blocked_id);
                if (!$user) {
                    return null;
                }
                return [
                    'id'                  => $user->id,
                    'name'                => $user->name,
                    'email'               => $user->email,
                    'avatar_url'          => $user->avatar_url,
                    'is_online'           => $user->is_online,
                    'status'              => $user->status,
                    'deleted_at'          => $user->deleted_at?->toIso8601String(),
                    'has_active_premium'  => $user->has_active_premium,
                    'has_active_vip'      => $user->has_active_vip,
                    'is_verified'         => $user->is_verified,
                    'total_blocks_count'  => (int) $item->total_blocks_count,
                    'recent_blocks_count' => (int) $item->recent_blocks_count,
                    'last_block_at'       => $item->last_block_at ? \Illuminate\Support\Carbon::parse($item->last_block_at)->toIso8601String() : null,
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'data'      => $data,
            'meta'      => [
                'current_page' => $paginatedBlocks->currentPage(),
                'last_page'    => $paginatedBlocks->lastPage(),
                'per_page'     => $paginatedBlocks->perPage(),
                'total'        => $paginatedBlocks->total(),
                'from'         => $paginatedBlocks->firstItem(),
                'to'           => $paginatedBlocks->lastItem(),
            ],
            'threshold' => $threshold,
            // Counts убраны из list response — отдельный endpoint /block-signals/counts
            // со stable queryKey на фронте. Раньше counts были здесь → скачкообразный UI
            // при смене порога/страницы (каждая page-loading перезаписывала их).
        ]);
    }

    /**
     * GET /api/admin/block-signals/counts
     *
     * Отдельный endpoint для stats — stable queryKey на фронте.
     * Кэшируется на 60 сек (Cache::remember 'admin_block_signal_counts').
     * refetchInterval 30s на фронте → автоматически обновляется.
     */
    public function counts(): JsonResponse
    {
        return response()->json(['stats' => $this->getCounts()]);
    }

    /**
     * Список юзеров, которые заблокировали данного.
     * Лимит 50 — чтобы не вытащить 10000 строк в память.
     */
    public function blockers(Request $request, int $userId): JsonResponse
    {
        $avatarQuery = fn($q) => $q->select([
            'id',
            'user_id',
            'is_primary',
            'status',
            'path_thumb',
            'path_medium',
            'path_large',
            'path_original',
        ])
            ->orderByDesc('is_primary')
            ->limit(1);

        $blocks = UserBlock::where('blocked_id', $userId)
            ->with([
                'blocker' => fn($q) => $q->withTrashed()
                    ->select('id', 'name', 'email', 'status', 'premium_expires_at', 'vip_expires_at', 'last_seen', 'deleted_at', 'is_verified')
                    ->with(['photos' => $avatarQuery])
            ])
            ->latest()
            ->limit(50)
            ->get();

        $data = $blocks->map(fn($block) => [
            'id'         => $block->id,
            'reason'     => $block->reason,
            'reason_label' => $this->reasonLabel($block->reason),
            'reason_color'  => $this->reasonColor($block->reason),
            'created_at' => $block->created_at?->toIso8601String(),
            'blocker'    => $block->blocker ? [
                'id'                 => $block->blocker->id,
                'name'               => $block->blocker->name,
                'email'              => $block->blocker->email,
                'avatar_url'         => $block->blocker->avatar_url,
                'is_online'          => $block->blocker->is_online,
                'status'             => $block->blocker->status,
                'deleted_at'         => $block->blocker->deleted_at?->toIso8601String(),
                'has_active_premium' => $block->blocker->has_active_premium,
                'has_active_vip'     => $block->blocker->has_active_vip,
                'is_verified'        => $block->blocker->is_verified,
            ] : null,
        ])->values();

        return response()->json([
            'data'     => $data,
            'user_id'  => $userId,
        ]);
    }

    // ============================================
    // ДЕЙСТВИЯ — делегируют ModerateBlockSignalAction
    // ============================================

    /**
     * Бан юзера по сигналу блокировок.
     * Делегирует в ModerateBlockSignalAction (внутри — ToggleUserBanAction,
     * как fraud-alerts).
     *
     * M1 FIX: lockForUpdate — race condition между двумя админами,
     * кликающими «Вечный бан» одновременно на одного юзера.
     *
     * M2 FIX: Idempotency — если юзер уже в target ban-статусе,
     * early-return без повторного бана и уведомления. Без этого
     * дабл-клик по кнопке приводил к повторной отправке
     * UserBanned notification и дубликату в AdminLog.
     */
    public function ban(Request $request, int $userId, ModerateBlockSignalAction $action): JsonResponse
    {
        $admin    = $request->user();
        $banType  = $request->string('type', 'permanent')->toString();
        $banTypes = ['shadow', 'temp', 'permanent'];
        if (!in_array($banType, $banTypes, true)) {
            return response()->json(['message' => 'Неверный тип бана'], 422);
        }

        // M1 FIX: lockForUpdate на User — два админа не забанят одновременно
        $user = User::withTrashed()->where('id', $userId)->lockForUpdate()->first();
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }
        if ($user->isStaff()) {
            return response()->json(['message' => 'Нельзя забанить сотрудника'], 422);
        }

        // Целевой ban-статус для этого типа бана
        $targetStatus = match ($banType) {
            'shadow'    => 'shadowbanned',
            'temp'      => 'banned',
            'permanent' => 'banned',
        };

        // M2 FIX: Idempotency — если юзер уже в target ban-статусе,
        // не баним повторно. Возвращаем friendly message, что уже забанен.
        // ToggleUserBanAction с forceBan=true всё равно перезапишет бан,
        // что создаст дубль AdminLog + дубль notification.
        if ($user->status === $targetStatus) {
            $label = match ($banType) {
                'shadow'    => 'уже в теневом бане',
                'temp'      => 'уже забанен временно',
                'permanent' => 'уже забанен навсегда',
            };
            return response()->json(['message' => "Пользователь {$label}"], 200);
        }

        try {
            $banResult = $action->ban($user, $admin, $banType);
        } catch (\RuntimeException $e) {
            // ToggleUserBanAction вернул success=false → пробросили как RuntimeException.
            // Возвращаем 422 + message — фронт покажет toast.error через extractErrorMessage.
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            // Любая другая ошибка (БД упала, timeout) — логируем и отдаём 500.
            // Не светим внутренности externally — generic message.
            report($e);
            return response()->json(['message' => 'Внутренняя ошибка сервера при бане'], 500);
        }

        return response()->json(['message' => $banResult['message'] ?? 'Пользователь забанен']);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getCounts(): array
    {
        return Cache::remember('admin_block_signal_counts', 60, function () {
            // Один SQL: группы по порогам (3+, 5+, 10+) + неделя.
            // HAVING COUNT(*) >= 3 — отсекаем норм юзеров (1-2 блока = штатная ситуация).
            $stats = UserBlock::select('blocked_id')
                ->selectRaw('COUNT(*) as total')
                ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as recent', [now()->subDays(7)])
                ->groupBy('blocked_id')
                ->havingRaw('COUNT(*) >= 3')
                ->get();

            // Считаем по порогам на PHP-стороне (после группировки строк мало,
            // ~десятки/сотни — это дешевле чем 4 отдельных SQL на бэке).
            $total  = $stats->count();
            $danger = $stats->filter(fn($r) => (int) $r->recent >= 5)->count();
            $medium = $stats->filter(fn($r) => (int) $r->total >= 3 && (int) $r->total < 10)->count();
            $high   = $stats->filter(fn($r) => (int) $r->total >= 10)->count();

            return [
                'total'  => $total,
                'medium' => $medium,
                'high'   => $high,
                'danger' => $danger,
            ];
        });
    }

    private function reasonLabel(?string $reason): ?string
    {
        if (!$reason) return null;
        $enum = UserBlockReason::tryFrom($reason);
        return $enum?->label();
    }

    private function reasonColor(?string $reason): ?string
    {
        if (!$reason) return null;
        $enum = UserBlockReason::tryFrom($reason);
        return $enum?->color();
    }
}
