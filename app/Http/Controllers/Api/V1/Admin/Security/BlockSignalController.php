<?php

namespace App\Http\Controllers\Api\V1\Admin\Security;

use App\Actions\V1\Admin\Security\ModerateBlockSignalAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Resources\Api\V1\Admin\Security\BlockerResource;
use App\Http\Resources\Api\V1\Admin\Security\BlockSignalResource;
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
 *  3. paginate() — пагинация по сгруппированному набору
 *  4. Вторым запросом подтягиваем юзеровwhereIn — не тащим 10000 строк в память
 *
 * Архитектура:
 *  - Controller: валидация (ban type), resolution юзера (lockForUpdate + 404)
 *    + staff-check + idempotency early-return, форматирование через Resource.
 *  - ModerateBlockSignalAction: бан юзера по сигналу через ToggleUserBanAction —
 *    внутри транзакции, с AdminLog и инвалидацией кэша.
 *
 * Counts кэшируются на 60 сек (Cache::remember 'admin_block_signal_counts').
 * Все mutations вызывают Cache::forget (внутри Action).
 *
 * M1 FIX: lockForUpdate на User — два админа не забанят одновременно.
 * M2 FIX: idempotency — если юзер уже в target ban-статусе, early-return.
 */
class BlockSignalController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $threshold = min(max((int) $request->integer('threshold', 3), 1), 100);
        $perPage   = min(max((int) $request->integer('per_page', 20), 10), 100);
        $search    = $request->string('q', '')->toString();
        $page      = max((int) $request->integer('page', 1), 1);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        // ШАГ 1: GROUP BY только по user_blocks (быстро через индекс).
        $blocksQuery = UserBlock::select('blocked_id')
            ->selectRaw('COUNT(*) as total_blocks_count')
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as recent_blocks_count', [now()->subDays(7)])
            ->selectRaw('MAX(created_at) as last_block_at')
            ->groupBy('blocked_id')
            ->havingRaw('COUNT(*) >= ?', [$threshold]);

        // Поиск: если число — это ID юзера, иначе — name/email.
        $isId = $search !== '' && ctype_digit($search);

        if ($search !== '') {
            $blocksQuery->whereExists(function ($q) use ($search, $isId, $searchOperator) {
                $q->select(DB::raw(1))
                    ->from('users')
                    ->whereColumn('users.id', 'user_blocks.blocked_id')
                    ->when($isId, fn($uq) => $uq->where('users.id', (int) $search))
                    ->when(!$isId, fn($uq) => $uq->where('users.name', $searchOperator, "%{$search}%")
                        ->orWhere('users.email', $searchOperator, "%{$search}%"));
            });
        }

        // Сортировка: при поиске по ID — искомый юзер ВСЕГДА первым.
        $blocksQuery->when($isId, fn($q) => $q->orderByRaw('CASE WHEN blocked_id = ? THEN 0 ELSE 1 END', [(int) $search]))
            ->orderByRaw('recent_blocks_count DESC')
            ->orderByRaw('total_blocks_count DESC');

        $paginatedBlocks = $blocksQuery->paginate($perPage, ['*'], 'page', $page);

        // ШАГ 2: Достаём ID юзеров текущей страницы.
        $userIds = $paginatedBlocks->getCollection()
            ->pluck('blocked_id')
            ->filter()
            ->unique()
            ->values()
            ->toArray();

        // ШАГ 3: Загружаем юзеров одним запросом (с trashed — на случай деактивированных).
        $users = User::withTrashed()
            ->where('role', User::ROLE_USER)
            ->whereIn('id', $userIds)
            ->withAvatar()
            ->get()
            ->keyBy('id');

        // ШАГ 4: Склеиваем юзеров с aggregate-данными, сохраняем порядок пагинации.
        // setRelation('user', ...) — Resource видит loaded user и отдаёт его.
        $data = $paginatedBlocks->getCollection()
            ->map(function ($item) use ($users) {
                $user = $users->get($item->blocked_id);
                if (!$user) {
                    return null;  // юзер удалён → пропускаем (filter ниже)
                }
                $item->setRelation('user', $user);
                return $item;
            })
            ->filter()
            ->values();

        return response()->json([
            'data' => BlockSignalResource::collection($data)->resolve(request()),
            'meta' => [
                'current_page' => $paginatedBlocks->currentPage(),
                'last_page'    => $paginatedBlocks->lastPage(),
                'per_page'     => $paginatedBlocks->perPage(),
                'total'        => $paginatedBlocks->total(),
                'from'         => $paginatedBlocks->firstItem(),
                'to'           => $paginatedBlocks->lastItem(),
            ],
            'threshold' => $threshold,
            // Counts убраны из list response — отдельный endpoint /block-signals/counts.
        ]);
    }

    /**
     * GET /api/admin/block-signals/counts — отдельный endpoint для stats.
     */
    public function counts(): JsonResponse
    {
        return response()->json(['stats' => $this->getCounts()]);
    }

    /**
     * GET /api/admin/block-signals/{userId}/blockers
     * Список юзеров, которые заблокировали данного. Лимит 50 — чтобы не вытащить
     * 10000 строк в память.
     */
    public function blockers(Request $request, int $userId): JsonResponse
    {
        $blocks = UserBlock::where('blocked_id', $userId)
            ->with([
                'blocker' => fn($q) => $q->withTrashed()->withAvatar(),
            ])
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'data'    => BlockerResource::collection($blocks)->resolve(request()),
            'user_id' => $userId,
        ]);
    }

    // ============================================
    // ДЕЙСТВИЯ — делегируют ModerateBlockSignalAction
    // ============================================

    /**
     * Бан юзера по сигналу блокировок.
     *
     * M1 FIX: lockForUpdate — race condition между двумя админами.
     * M2 FIX: idempotency — если юзер уже в target ban-статусе, early-return.
     */
    public function ban(Request $request, int $userId, ModerateBlockSignalAction $action): JsonResponse
    {
        $admin    = $request->user();
        $banType  = $request->string('type', 'permanent')->toString();
        $banTypes = ['shadow', 'temp', 'permanent'];
        if (!in_array($banType, $banTypes, true)) {
            return $this->message('Неверный тип бана', 422);
        }

        // M1 FIX: lockForUpdate на User.
        $user = User::withTrashed()->where('id', $userId)->lockForUpdate()->first();
        if (!$user) {
            return $this->notFound('Пользователь');
        }
        if ($user->isStaff()) {
            return $this->message('Нельзя забанить сотрудника', 422);
        }

        // Целевой ban-статус для этого типа бана.
        $targetStatus = match ($banType) {
            'shadow'    => 'shadowbanned',
            'temp'      => 'banned',
            'permanent' => 'banned',
        };

        // M2 FIX: idempotency — если юзер уже в target ban-статусе, не баним повторно.
        if ($user->status === $targetStatus) {
            $label = match ($banType) {
                'shadow'    => 'уже в теневом бане',
                'temp'      => 'уже забанен временно',
                'permanent' => 'уже забанен навсегда',
            };
            return $this->message("Пользователь {$label}");
        }

        try {
            $banResult = $action->ban($user, $admin, $banType);
        } catch (\RuntimeException $e) {
            // ToggleUserBanAction вернул success=false → пробросили как RuntimeException.
            return $this->message($e->getMessage(), 422);
        } catch (\Throwable $e) {
            // Любая другая ошибка (БД упала, timeout) — логируем и 500.
            report($e);
            return $this->message('Внутренняя ошибка сервера при бане', 500);
        }

        return $this->message($banResult['message'] ?? 'Пользователь забанен');
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getCounts(): array
    {
        return Cache::remember('admin_block_signal_counts', 60, function () {
            // Один SQL: группы по порогам (3+, 5+, 10+) + неделя.
            $stats = UserBlock::select('blocked_id')
                ->selectRaw('COUNT(*) as total')
                ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as recent', [now()->subDays(7)])
                ->groupBy('blocked_id')
                ->havingRaw('COUNT(*) >= 3')
                ->get();

            // Считаем по порогам на PHP (после группировки строк мало).
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
}
