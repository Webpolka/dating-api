<?php

namespace App\Http\Controllers\Api\V1\Admin\Moderation;

use App\Actions\V1\Admin\Moderation\ManageDiaryRubricAction;
use App\Actions\V1\Admin\Moderation\ModerateDiaryAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Moderation\ApproveDiaryRequest;
use App\Http\Requests\Api\V1\Admin\Moderation\DestroyDiaryRubricRequest;
use App\Http\Requests\Api\V1\Admin\Moderation\RejectDiaryRequest;
use App\Http\Requests\Api\V1\Admin\Moderation\StoreDiaryRubricRequest;
use App\Http\Requests\Api\V1\Admin\Moderation\UnpublishDiaryRequest;
use App\Http\Requests\Api\V1\Admin\Moderation\UpdateDiaryRubricRequest;
use App\Http\Requests\Api\V1\Admin\Moderation\UpdateDiarySettingsRequest;
use App\Http\Resources\Api\V1\Admin\Moderation\DiaryResource;
use App\Http\Resources\Api\V1\Admin\Moderation\DiaryRubricResource;
use App\Models\Diary;
use App\Models\DiaryRubric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Модерация записей дневников + CRUD системных рубрик.
 *
 * Read-side здесь. Write-логика — в ModerateDiaryAction (посты) и
 * ManageDiaryRubricAction (рубрики).
 *
 * Контракт ModerateDiaryAction: каждый метод возвращает
 * ['status' => int, 'body' => array] — контроллер строит JsonResponse.
 *
 * Два таба в index():
 *  - diaries (default): список постов с фильтрами
 *  - rubrics: список системных рубрик (user_id=null)
 */
class DiaryController extends AdminController
{
    // ============================================
    // СПИСОК (ПОСТЫ ИЛИ РУБРИКИ) + СЧЁТЧИКИ
    // ============================================

    public function index(Request $request): JsonResponse
    {
        $activeTab = $request->string('tab', 'diaries')->toString();
        return $activeTab === 'rubrics'
            ? $this->getRubrics($request)
            : $this->getDiaries($request);
    }

    private function getDiaries(Request $request): JsonResponse
    {
        $statusFilter = $request->string('status', 'pending')->toString();
        if (!in_array($statusFilter, ['all', 'pending', 'published', 'rejected'], true)) {
            $statusFilter = 'pending';
        }

        $trashedFilter = $request->string('trashed', 'without')->toString();
        if (!in_array($trashedFilter, ['with', 'only', 'without'], true)) {
            $trashedFilter = 'without';
        }

        $rubricFilter = $request->string('rubric', 'all')->toString();
        $search = $request->string('q', '')->toString();
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);
        $page = max((int) $request->integer('page', 1), 1);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isSearching = !empty(trim($search));
        $isIdSearch = $isSearching && ctype_digit($search);

        // В list-режиме НЕ селектим body (тяжёлый текст) — DiaryResource вернёт body=null.
        // views_count/comments_count/likes_count — это колонки БД (не связи),
        // доступны без withCount.
        $query = Diary::query()
            ->select(['id', 'user_id', 'title', 'status', 'reject_reason', 'moderated_by', 'moderated_at', 'published_at', 'created_at', 'updated_at', 'deleted_at', 'is_comments_enabled', 'diary_rubric_id', 'views_count', 'comments_count', 'likes_count'])
            ->with([
                'user' => fn($q) => $q->withTrashed()->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'last_seen', 'deleted_at'])->withAvatar(),
                'diaryRubric',
            ])
            ->whereHas('user', fn($q) => $q->excludeStaff());

        if ($trashedFilter === 'with') $query->withTrashed();
        elseif ($trashedFilter === 'only') $query->onlyTrashed();

        if ($isSearching) {
            $query->where(function ($q) use ($search, $searchOperator, $isIdSearch) {
                if ($isIdSearch) {
                    $q->where('id', (int) $search);
                } else {
                    $q->where('title', $searchOperator, "%{$search}%")
                        ->orWhereHas('user', fn($uq) => $uq->withTrashed()->where('name', $searchOperator, "%{$search}%"));
                }
            });
        }

        if ($statusFilter !== 'all') $query->where('status', $statusFilter);

        if ($rubricFilter !== 'all') {
            if ($rubricFilter === 'none') {
                $query->whereNull('diary_rubric_id');
            } else {
                $query->where('diary_rubric_id', $rubricFilter);
            }
        }

        $query->when($isIdSearch, fn($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]))
            ->latest('published_at')->latest('created_at');

        $diaries = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'tab'    => 'diaries',
            'data'   => DiaryResource::collection($diaries->getCollection())->resolve($request),
            'meta'   => [
                'current_page' => $diaries->currentPage(),
                'last_page'    => $diaries->lastPage(),
                'per_page'     => $diaries->perPage(),
                'total'        => $diaries->total(),
                'from'         => $diaries->firstItem(),
                'to'           => $diaries->lastItem(),
            ],
            'all_rubrics' => $this->getAllRubrics(),
            'status'      => $statusFilter,
        ]);
    }

    private function getRubrics(Request $request): JsonResponse
    {
        $search = $request->string('q', '')->toString();
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);
        $page = max((int) $request->integer('page', 1), 1);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isSearching = !empty(trim($search));
        $isIdSearch = $isSearching && ctype_digit($search);

        $query = DiaryRubric::whereNull('user_id')->withCount('diaries');

        if ($isSearching) {
            $query->where(function ($q) use ($search, $searchOperator, $isIdSearch) {
                $q->where('name', $searchOperator, "%{$search}%");
                if ($isIdSearch) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        $query->when($isIdSearch, fn($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]));
        $rubrics = $query->ordered()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'tab'  => 'rubrics',
            'data' => DiaryRubricResource::collection($rubrics->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $rubrics->currentPage(),
                'last_page'    => $rubrics->lastPage(),
                'per_page'     => $rubrics->perPage(),
                'total'        => $rubrics->total(),
                'from'         => $rubrics->firstItem(),
                'to'           => $rubrics->lastItem(),
            ],
            'all_rubrics' => $this->getAllRubrics(),
        ]);
    }

    public function counts(): JsonResponse
    {
        return response()->json(['stats' => $this->getDiaryCounts()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $diary = Diary::withTrashed()
            ->with([
                'user' => fn($q) => $q->withTrashed()->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'last_seen', 'deleted_at'])->withAvatar(),
                'diaryRubric',
            ])          
            ->find($id);

        if (!$diary) return $this->notFound('Запись');

        // Доступные рубрики для этого поста: системные (user_id=null) + личные автора.
        $availableRubrics = DiaryRubric::where(function ($q) use ($diary) {
            $q->whereNull('user_id')->orWhere('user_id', $diary->user_id);
        })
            ->orderByRaw('user_id IS NULL DESC')
            ->orderBy('sort_order')
            ->get()
            ->keyBy('id');

        // Если текущая рубрика не попала в список (edge case) — добавим.
        if ($diary->diary_rubric_id && !$availableRubrics->has($diary->diary_rubric_id)) {
            $currentRubric = DiaryRubric::find($diary->diary_rubric_id);
            if ($currentRubric) {
                $availableRubrics->put($currentRubric->id, $currentRubric);
            }
        }

        return response()->json([
            'data'              => (new DiaryResource($diary))->resolve($request),
            'available_rubrics' => $availableRubrics->values()->map(fn($r) => [
                'id'          => $r->id,
                'name'        => $r->name,
                'user_id'     => $r->user_id,
                'is_personal' => $r->user_id !== null,
                'is_active'   => (bool) $r->is_active,
            ]),
        ]);
    }

    // ============================================
    // ПОСТЫ — ДЕЙСТВИЯ (делегируют ModerateDiaryAction)
    // ============================================

    public function approve(ApproveDiaryRequest $request, int $id, ModerateDiaryAction $action): JsonResponse
    {
        $diary = Diary::where('id', $id)->lockForUpdate()->first();
        if (!$diary) return $this->notFound('Запись');

        $result = $action->approve($diary, $request->user(), $request->validated());
        return response()->json($result['body'], $result['status']);
    }

    public function reject(RejectDiaryRequest $request, int $id, ModerateDiaryAction $action): JsonResponse
    {
        $diary = Diary::where('id', $id)->lockForUpdate()->first();
        if (!$diary) return $this->notFound('Запись');

        $result = $action->reject($diary, $request->user(), $request->validated());
        return response()->json($result['body'], $result['status']);
    }

    public function unpublish(UnpublishDiaryRequest $request, int $id, ModerateDiaryAction $action): JsonResponse
    {
        $diary = Diary::where('id', $id)->lockForUpdate()->first();
        if (!$diary) return $this->notFound('Запись');

        $result = $action->unpublish($diary, $request->user(), $request->validated());
        return response()->json($result['body'], $result['status']);
    }

    public function updateSettings(UpdateDiarySettingsRequest $request, int $id, ModerateDiaryAction $action): JsonResponse
    {
        $diary = Diary::where('id', $id)->lockForUpdate()->first();
        if (!$diary) return $this->notFound('Запись');

        $result = $action->updateSettings($diary, $request->user(), $request->validated());
        return response()->json($result['body'], $result['status']);
    }

    public function destroy(Request $request, int $id, ModerateDiaryAction $action): JsonResponse
    {
        $diary = Diary::where('id', $id)->lockForUpdate()->first();
        if (!$diary) return $this->notFound('Запись');

        $result = $action->destroy($diary, $request->user());
        return response()->json($result['body'], $result['status']);
    }

    public function restore(Request $request, int $id, ModerateDiaryAction $action): JsonResponse
    {
        $diary = Diary::withTrashed()->where('id', $id)->lockForUpdate()->first();
        if (!$diary) return $this->notFound('Запись');

        $result = $action->restore($diary, $request->user());
        return response()->json($result['body'], $result['status']);
    }

    public function forceDelete(Request $request, int $id, ModerateDiaryAction $action): JsonResponse
    {
        $diary = Diary::withTrashed()->where('id', $id)->lockForUpdate()->first();
        if (!$diary) return $this->notFound('Запись');

        $result = $action->forceDelete($diary, $request->user());
        return response()->json($result['body'], $result['status']);
    }

    // ============================================
    // РУБРИКИ — CRUD + TOGGLE (делегируют ManageDiaryRubricAction)
    // ============================================

    public function checkRubricName(Request $request): JsonResponse
    {
        $name = trim($request->string('name', '')->toString());
        $ignoreId = $request->integer('ignore_id');

        if ($name === '') {
            return response()->json(['available' => true]);
        }

        $operator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $query = DiaryRubric::whereNull('user_id')->where('name', $operator, $name);
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        $conflict = $query->first();

        return response()->json([
            'available'     => !$conflict,
            'conflict_id'   => $conflict?->id,
            'conflict_name' => $conflict?->name,
        ]);
    }

    public function storeRubric(StoreDiaryRubricRequest $request, ManageDiaryRubricAction $action): JsonResponse
    {
        $rubric = $action->storeRubric($request->user(), $request->validated());
        return $this->message('Рубрика создана', 200, ['data' => new DiaryRubricResource($rubric)]);
    }

    public function updateRubric(UpdateDiaryRubricRequest $request, int $id, ManageDiaryRubricAction $action): JsonResponse
    {
        $rubric = DiaryRubric::where('id', $id)->lockForUpdate()->first();
        if (!$rubric) return $this->notFound('Рубрика');

        $rubric = $action->updateRubric($rubric, $request->user(), $request->validated());
        return $this->message('Рубрика обновлена', 200, ['data' => new DiaryRubricResource($rubric)]);
    }

    public function destroyRubric(DestroyDiaryRubricRequest $request, int $id, ManageDiaryRubricAction $action): JsonResponse
    {
        $rubric = DiaryRubric::where('id', $id)->lockForUpdate()->first();
        if (!$rubric) return $this->notFound('Рубрика');

        $action->destroyRubric($rubric, $request->user(), $request->validated());
        return $this->message('Рубрика удалена. Записи перенесены.');
    }

    public function toggleRubricStatus(Request $request, int $id, ManageDiaryRubricAction $action): JsonResponse
    {
        $rubric = DiaryRubric::where('id', $id)->lockForUpdate()->first();
        if (!$rubric) return $this->notFound('Рубрика');

        $action->toggleRubricStatus($rubric, $request->user());
        return $this->message('Статус рубрики изменен');
    }

    // ============================================
    // ХЕЛПЕРЫ (read-side: счётчики, списки)
    // ============================================

    private function getDiaryCounts(): array
    {
        return Cache::remember('admin_diary_counts', 60, function () {
            $row = DB::table('diaries')
                ->leftJoin('users', 'diaries.user_id', '=', 'users.id')
                ->where(function ($q) {
                    $q->whereNull('users.id')
                        ->orWhereNotIn('users.role', ['admin', 'moderator']);
                })
                ->selectRaw(
                    "COUNT(*) AS all_count, " .
                    "SUM(CASE WHEN diaries.status = 'published' AND diaries.deleted_at IS NULL THEN 1 ELSE 0 END) AS published, " .
                    "SUM(CASE WHEN diaries.status = 'pending' AND diaries.deleted_at IS NULL THEN 1 ELSE 0 END) AS pending, " .
                    "SUM(CASE WHEN diaries.status = 'rejected' AND diaries.deleted_at IS NULL THEN 1 ELSE 0 END) AS rejected, " .
                    "SUM(CASE WHEN diaries.deleted_at IS NOT NULL THEN 1 ELSE 0 END) AS trashed"
                )
                ->first();

            return [
                'all'           => (int) ($row->all_count ?? 0),
                'published'     => (int) ($row->published ?? 0),
                'pending'       => (int) ($row->pending ?? 0),
                'rejected'      => (int) ($row->rejected ?? 0),
                'trashed'       => (int) ($row->trashed ?? 0),
                'rubrics_count' => DiaryRubric::whereNull('user_id')->count(),
            ];
        });
    }

    private function getAllRubrics(): array
    {
        return Cache::remember('admin_diary_rubrics_list', 60, function () {
            return DiaryRubric::whereNull('user_id')
                ->ordered()
                ->get()
                ->map(fn($r) => ['id' => $r->id, 'name' => $r->name, 'is_active' => (bool) $r->is_active])
                ->all();
        });
    }
}
