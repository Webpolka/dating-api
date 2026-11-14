<?php

namespace App\Http\Controllers\Api\V1\Admin\Moderation;

use App\Actions\V1\Admin\Moderation\ModerateDatingAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Resources\Api\V1\Admin\Moderation\SwipeResource;
use App\Http\Resources\Api\V1\Admin\Moderation\UserMatchResource;
use App\Models\Swipe;
use App\Models\UserMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Модерация свайпов и мэтчей (знакомства).
 *
 * Архитектура:
 *  - Controller: валидация, resolution модели + 404, форматирование через
 *    SwipeResource / UserMatchResource.
 *  - ModerateDatingAction: бизнес-логика mutations (удаление свайпа
 *    разрывает мэтч, разрыв мэтча, восстановление).
 *
 * index() — два режима:
 *  - mode=swipes (default): список свайпов с фильтрами (type, search, dateFrom/dateTo)
 *  - mode=matches: список мэтчей с фильтрами (search, dateFrom/dateTo)
 *
 * Во всех query ИСКЛЮЧАЕМ staff с ОБЕИХ сторон (user/targetUser или user1/user2) —
 * модерации не интересно видеть свайпы/мэтчи между staff'ом.
 *
 * Stats кэшируются на 60 сек (Cache::remember 'dating_admin_stats').
 */
class DatingController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $viewMode = $request->string('mode', 'swipes')->toString();
        if (!in_array($viewMode, ['swipes', 'matches'], true)) {
            $viewMode = 'swipes';
        }

        $typeFilter = $request->string('type', 'all')->toString();
        if (!in_array($typeFilter, ['all', 'like', 'dislike', 'superlike'], true)) {
            $typeFilter = 'all';
        }

        $search = $request->string('q', '')->toString();
        $dateFrom = $request->string('dateFrom', '')->toString();
        $dateTo = $request->string('dateTo', '')->toString();

        // Если dateFrom > dateTo — меняем местами (защита от опечатки юзера).
        if ($dateFrom && $dateTo && Carbon::parse($dateFrom)->gt(Carbon::parse($dateTo))) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $perPage = min(max((int) $request->integer('per_page', 10), 1), 100);
        $page = max((int) $request->integer('page', 1), 1);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isSearching = !empty(trim($search));
        $isIdSearch = $isSearching && ctype_digit($search);

        // Замыкание eager-load юзера с аватаром — переиспользуем для обеих пар
        // (user/targetUser или user1/user2).
        $userWithAvatar = fn($q) => $q->withTrashed()
            ->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
            ->withAvatar();

        if ($viewMode === 'matches') {
            $items = UserMatch::with(['user1' => $userWithAvatar, 'user2' => $userWithAvatar])
                ->where(function ($q) {
                    $q->whereNull('user1_id')->orWhereHas('user1', fn($q2) => $q2->withTrashed()->excludeStaff());
                })
                ->where(function ($q) {
                    $q->whereNull('user2_id')->orWhereHas('user2', fn($q2) => $q2->withTrashed()->excludeStaff());
                })
                ->when($isSearching, fn($q) => $q->where(function ($innerQ) use ($search, $searchOperator, $isIdSearch) {
                    if ($isIdSearch) {
                        $innerQ->where('id', (int) $search)
                            ->orWhere('user1_id', (int) $search)
                            ->orWhere('user2_id', (int) $search);
                    } else {
                        $innerQ->orWhereHas('user1', fn($q2) => $q2->withTrashed()->where(function ($q3) use ($search, $searchOperator) {
                            $q3->where('name', $searchOperator, "%{$search}%")->orWhere('email', $searchOperator, "%{$search}%");
                        }))
                            ->orWhereHas('user2', fn($q2) => $q2->withTrashed()->where(function ($q3) use ($search, $searchOperator) {
                                $q3->where('name', $searchOperator, "%{$search}%")->orWhere('email', $searchOperator, "%{$search}%");
                            }));
                    }
                }))
                ->when($dateFrom, fn($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)->startOfDay()))
                ->when($dateTo, fn($q, $v) => $q->where('created_at', '<=', Carbon::parse($v)->endOfDay()))
                ->when($isIdSearch, fn($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]))
                ->latest('created_at')->latest('id')
                ->paginate($perPage, ['*'], 'page', $page);

            $data = UserMatchResource::collection($items->getCollection())->resolve($request);
        } else {
            $items = Swipe::with(['user' => $userWithAvatar, 'targetUser' => $userWithAvatar])
                ->where(function ($q) {
                    $q->whereNull('user_id')->orWhereHas('user', fn($q2) => $q2->withTrashed()->excludeStaff());
                })
                ->where(function ($q) {
                    $q->whereNull('target_user_id')->orWhereHas('targetUser', fn($q2) => $q2->withTrashed()->excludeStaff());
                })
                ->when($typeFilter !== 'all', fn($q) => $q->where('type', $typeFilter))
                ->when($isSearching, fn($q) => $q->where(function ($innerQ) use ($search, $searchOperator, $isIdSearch) {
                    if ($isIdSearch) {
                        $innerQ->where('id', (int) $search)
                            ->orWhere('user_id', (int) $search)
                            ->orWhere('target_user_id', (int) $search);
                    } else {
                        $innerQ->orWhereHas('user', fn($q2) => $q2->withTrashed()->where(function ($q3) use ($search, $searchOperator) {
                            $q3->where('name', $searchOperator, "%{$search}%")->orWhere('email', $searchOperator, "%{$search}%");
                        }))
                            ->orWhereHas('targetUser', fn($q2) => $q2->withTrashed()->where(function ($q3) use ($search, $searchOperator) {
                                $q3->where('name', $searchOperator, "%{$search}%")->orWhere('email', $searchOperator, "%{$search}%");
                            }));
                    }
                }))
                ->when($dateFrom, fn($q, $v) => $q->where('created_at', '>=', Carbon::parse($v)->startOfDay()))
                ->when($dateTo, fn($q, $v) => $q->where('created_at', '<=', Carbon::parse($v)->endOfDay()))
                ->when($isIdSearch, fn($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]))
                ->latest('created_at')->latest('id')
                ->paginate($perPage, ['*'], 'page', $page);

            $data = SwipeResource::collection($items->getCollection())->resolve($request);
        }

        return response()->json([
            'mode' => $viewMode,
            'data' => $data,
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'per_page'     => $items->perPage(),
                'total'        => $items->total(),
                'from'         => $items->firstItem(),
                'to'           => $items->lastItem(),
            ],
            'filters' => [
                'type'     => $typeFilter,
                'q'        => $search,
                'dateFrom' => $dateFrom,
                'dateTo'   => $dateTo,
            ],
        ]);
    }

    public function counts(): JsonResponse
    {
        return response()->json(['stats' => $this->getStats()]);
    }

    // ============================================
    // ДЕЙСТВИЯ — делегируют ModerateDatingAction
    // ============================================

    public function destroySwipe(Request $request, int $id, ModerateDatingAction $action): JsonResponse
    {
        // lockForUpdate — защита от race condition, если два модератора одновременно.
        $swipe = Swipe::lockForUpdate()->find($id);
        if (!$swipe) return $this->notFound('Свайп');

        $action->destroySwipe($swipe, $request->user());

        return $this->message('Свайп удален. Если это был лайк, мэтч разорван.');
    }

    public function destroyMatch(Request $request, int $id, ModerateDatingAction $action): JsonResponse
    {
        $match = UserMatch::lockForUpdate()->find($id);
        if (!$match) return $this->notFound('Мэтч');

        // Идемпотентность: мэтч уже разорван — ничего не делаем.
        if ($match->status === UserMatch::STATUS_UNMATCHED) {
            return $this->message('Мэтч уже разорван');
        }

        $action->destroyMatch($match, $request->user());

        return $this->message('Мэтч принудительно разорван.');
    }

    public function restoreMatch(Request $request, int $id, ModerateDatingAction $action): JsonResponse
    {
        $match = UserMatch::lockForUpdate()->find($id);
        if (!$match) return $this->notFound('Мэтч');

        // Идемпотентность: мэтч уже активен — ничего не делаем.
        if ($match->status === UserMatch::STATUS_ACTIVE) {
            return $this->message('Мэтч уже активен');
        }

        $action->restoreMatch($match, $request->user());

        return $this->message('Мэтч восстановлен.');
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getStats(): array
    {
        return Cache::remember('dating_admin_stats', 60, function () {
            // Симметрично с index(): исключаем staff с ОБЕИХ сторон.
            $swipeStats = Swipe::where(function ($q) {
                $q->whereNull('user_id')->orWhereHas('user', fn($q2) => $q2->withTrashed()->excludeStaff());
            })->where(function ($q) {
                $q->whereNull('target_user_id')->orWhereHas('targetUser', fn($q2) => $q2->withTrashed()->excludeStaff());
            })->selectRaw("
                SUM(CASE WHEN type = 'like' THEN 1 ELSE 0 END) as total_likes,
                SUM(CASE WHEN type = 'dislike' THEN 1 ELSE 0 END) as total_dislikes,
                SUM(CASE WHEN type = 'superlike' THEN 1 ELSE 0 END) as total_superlikes,
                COUNT(*) as total_swipes
            ")->first();

            $matchCount = UserMatch::where(function ($q) {
                $q->whereNull('user1_id')->orWhereHas('user1', fn($q2) => $q2->withTrashed()->excludeStaff());
            })->where(function ($q) {
                $q->whereNull('user2_id')->orWhereHas('user2', fn($q2) => $q2->withTrashed()->excludeStaff());
            })->count();

            return [
                'total_likes'      => (int) ($swipeStats->total_likes ?? 0),
                'total_dislikes'   => (int) ($swipeStats->total_dislikes ?? 0),
                'total_superlikes' => (int) ($swipeStats->total_superlikes ?? 0),
                'total_swipes'     => (int) ($swipeStats->total_swipes ?? 0),
                'total_matches'    => $matchCount,
            ];
        });
    }
}
