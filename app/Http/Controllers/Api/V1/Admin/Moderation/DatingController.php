<?php

namespace App\Http\Controllers\Admin\V1\Moderation;

use App\Actions\Admin\Moderation\ModerateDatingAction;
use App\Http\Controllers\AdminController;
use App\Models\Swipe;
use App\Models\UserMatch;
use App\Http\Resources\Admin\V1\Users\AdminUserBadgeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

        if ($dateFrom && $dateTo && Carbon::parse($dateFrom)->gt(Carbon::parse($dateTo))) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $perPage = min(max((int) $request->integer('per_page', 10), 1), 100);
        $page = max((int) $request->integer('page', 1), 1);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isSearching = !empty(trim($search));
        $isIdSearch = $isSearching && ctype_digit($search);

        if ($viewMode === 'matches') {
            $query = UserMatch::with([
                'user1' => fn($q) => $q->withTrashed()->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
                    ->with(['photos' => fn($sq) => $sq->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb', 'path_medium'])->orderByDesc('is_primary')->limit(1)]),
                'user2' => fn($q) => $q->withTrashed()->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
                    ->with(['photos' => fn($sq) => $sq->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb', 'path_medium'])->orderByDesc('is_primary')->limit(1)]),
            ])
                ->where(function ($q) {
                    $q->whereNull('user1_id')->orWhereHas('user1', fn($q2) => $q2->withTrashed()->excludeStaff());
                })
                ->where(function ($q) {
                    $q->whereNull('user2_id')->orWhereHas('user2', fn($q2) => $q2->withTrashed()->excludeStaff());
                });

            if ($isSearching) {
                $query->where(function ($innerQ) use ($search, $searchOperator, $isIdSearch) {
                    if ($isIdSearch) {
                        $innerQ->where('id', (int) $search)
                            ->orWhere('user1_id', (int) $search)
                            ->orWhere('user2_id', (int) $search);
                    } else {
                        $innerQ->orWhereHas('user1', fn($q2) => $q2->withTrashed()->where(function ($q3) use ($search, $searchOperator) {
                            $q3->where('name', $searchOperator, "%{$search}%")
                                ->orWhere('email', $searchOperator, "%{$search}%");
                        }))
                            ->orWhereHas('user2', fn($q2) => $q2->withTrashed()->where(function ($q3) use ($search, $searchOperator) {
                                $q3->where('name', $searchOperator, "%{$search}%")
                                    ->orWhere('email', $searchOperator, "%{$search}%");
                            }));
                    }
                });
            }

            if ($dateFrom) $query->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            if ($dateTo) $query->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());

            // При поиске по ID — искомый мэтч первым.
            if ($isIdSearch) {
                $query->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
            }
            $query->latest('created_at')->latest('id');

            $items = $query->paginate($perPage, ['*'], 'page', $page);
            $data = $items->map(fn($m) => $this->formatMatch($m))->values()->all();
        } else {
            $query = Swipe::with([
                'user' => fn($q) => $q->withTrashed()->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
                    ->with(['photos' => fn($sq) => $sq->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb', 'path_medium'])->orderByDesc('is_primary')->limit(1)]),
                'targetUser' => fn($q) => $q->withTrashed()->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
                    ->with(['photos' => fn($sq) => $sq->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb', 'path_medium'])->orderByDesc('is_primary')->limit(1)]),
            ])
                ->where(function ($q) {
                    $q->whereNull('user_id')->orWhereHas('user', fn($q2) => $q2->withTrashed()->excludeStaff());
                })
                ->where(function ($q) {
                    $q->whereNull('target_user_id')->orWhereHas('targetUser', fn($q2) => $q2->withTrashed()->excludeStaff());
                });

            if ($typeFilter !== 'all') {
                $query->where('type', $typeFilter);
            }

            if ($isSearching) {
                $query->where(function ($innerQ) use ($search, $searchOperator, $isIdSearch) {
                    if ($isIdSearch) {
                        $innerQ->where('id', (int) $search)
                            ->orWhere('user_id', (int) $search)
                            ->orWhere('target_user_id', (int) $search);
                    } else {
                        $innerQ->orWhereHas('user', fn($q2) => $q2->withTrashed()->where(function ($q3) use ($search, $searchOperator) {
                            $q3->where('name', $searchOperator, "%{$search}%")
                                ->orWhere('email', $searchOperator, "%{$search}%");
                        }))
                            ->orWhereHas('targetUser', fn($q2) => $q2->withTrashed()->where(function ($q3) use ($search, $searchOperator) {
                                $q3->where('name', $searchOperator, "%{$search}%")
                                    ->orWhere('email', $searchOperator, "%{$search}%");
                            }));
                    }
                });
            }

            if ($dateFrom) $query->where('created_at', '>=', Carbon::parse($dateFrom)->startOfDay());
            if ($dateTo) $query->where('created_at', '<=', Carbon::parse($dateTo)->endOfDay());

            // При поиске по ID — искомый свайп первым.
            if ($isIdSearch) {
                $query->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
            }
            $query->latest('created_at')->latest('id');

            $items = $query->paginate($perPage, ['*'], 'page', $page);
            $data = $items->map(fn($s) => $this->formatSwipe($s))->values()->all();
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
                'type' => $typeFilter,
                'q' => $search,
                'dateFrom' => $dateFrom,
                'dateTo' => $dateTo,
            ]
        ]);
    }

    public function destroySwipe(Request $request, int $id, ModerateDatingAction $action): JsonResponse
    {
        // lockForUpdate — защита от race condition, если два модератора одновременно удаляют один свайп.
        $swipe = Swipe::lockForUpdate()->find($id);
        if (!$swipe) {
            return response()->json(['message' => 'Свайп не найден'], 404);
        }

        $action->destroySwipe($swipe, $request->user());

        return response()->json(['message' => 'Свайп удален. Если это был лайк, мэтч разорван.']);
    }

    public function destroyMatch(Request $request, int $id, ModerateDatingAction $action): JsonResponse
    {
        // lockForUpdate — защита от двойного разрыва.
        $match = UserMatch::lockForUpdate()->find($id);
        if (!$match) {
            return response()->json(['message' => 'Мэтч не найден'], 404);
        }

        // Идемпотентность: мэтч уже разорван — ничего не делаем.
        if ($match->status === UserMatch::STATUS_UNMATCHED) {
            return response()->json(['message' => 'Мэтч уже разорван']);
        }

        $action->destroyMatch($match, $request->user());

        return response()->json(['message' => 'Мэтч принудительно разорван.']);
    }

    public function restoreMatch(Request $request, int $id, ModerateDatingAction $action): JsonResponse
    {
        // lockForUpdate — защита от двойного восстановления.
        $match = UserMatch::lockForUpdate()->find($id);
        if (!$match) {
            return response()->json(['message' => 'Мэтч не найден'], 404);
        }

        // Идемпотентность: мэтч уже активен — ничего не делаем.
        if ($match->status === UserMatch::STATUS_ACTIVE) {
            return response()->json(['message' => 'Мэтч уже активен']);
        }

        $action->restoreMatch($match, $request->user());

        return response()->json(['message' => 'Мэтч восстановлен.']);
    }

    public function counts(): JsonResponse
    {
        return response()->json([
            'stats' => $this->getStats(),
        ]);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getStats(): array
    {
        return Cache::remember('dating_admin_stats', 60, function () {
            // Симметрично с index(): исключаем staff с ОБЕИХ сторон свайпа/мэтча.
            $baseSwipeQuery = Swipe::where(function ($q) {
                $q->whereNull('user_id')->orWhereHas('user', fn($q2) => $q2->withTrashed()->excludeStaff());
            })->where(function ($q) {
                $q->whereNull('target_user_id')->orWhereHas('targetUser', fn($q2) => $q2->withTrashed()->excludeStaff());
            });

            $swipeStats = (clone $baseSwipeQuery)->selectRaw("
                SUM(CASE WHEN type = 'like' THEN 1 ELSE 0 END) as total_likes,
                SUM(CASE WHEN type = 'dislike' THEN 1 ELSE 0 END) as total_dislikes,
                SUM(CASE WHEN type = 'superlike' THEN 1 ELSE 0 END) as total_superlikes,
                COUNT(*) as total_swipes
            ")->first();

            $baseMatchQuery = UserMatch::where(function ($q) {
                $q->whereNull('user1_id')->orWhereHas('user1', fn($q2) => $q2->withTrashed()->excludeStaff());
            })->where(function ($q) {
                $q->whereNull('user2_id')->orWhereHas('user2', fn($q2) => $q2->withTrashed()->excludeStaff());
            });

            return [
                'total_likes'       => (int) ($swipeStats->total_likes ?? 0),
                'total_dislikes'    => (int) ($swipeStats->total_dislikes ?? 0),
                'total_superlikes'  => (int) ($swipeStats->total_superlikes ?? 0),
                'total_swipes'      => (int) ($swipeStats->total_swipes ?? 0),
                'total_matches'     => $baseMatchQuery->count(),
            ];
        });
    }

    private function formatSwipe(Swipe $s): array
    {
        return [
            'id'         => $s->id,
            'type'       => $s->type,
            'rewinded'   => (bool) $s->rewinded_at,
            'created_at' => $s->created_at?->toIso8601String(),
            'user'       => $s->user ? AdminUserBadgeResource::nullable($s->user) : null,
            'targetUser' => $s->targetUser ? AdminUserBadgeResource::nullable($s->targetUser) : null,
        ];
    }

    private function formatMatch(UserMatch $m): array
    {
        return [
            'id'           => $m->id,
            'status'       => $m->status,
            'unmatched_by' => $m->unmatched_by,
            'unmatched_at' => $m->unmatched_at?->toIso8601String(),
            'created_at'   => $m->created_at?->toIso8601String(),
            'user1'        => $m->user1 ? AdminUserBadgeResource::nullable($m->user1) : null,
            'user2'        => $m->user2 ? AdminUserBadgeResource::nullable($m->user2) : null,
        ];
    }

}
