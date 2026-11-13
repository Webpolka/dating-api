<?php

namespace App\Http\Controllers\Admin\V1\Moderation;

use App\Actions\Admin\Moderation\ModerateDiaryCommentAction;
use App\Http\Controllers\AdminController;
use App\Http\Requests\Admin\V1\Moderation\RejectDiaryCommentRequest;
use App\Http\Resources\Admin\V1\Users\AdminUserBadgeResource;
use App\Models\Diary;
use App\Models\DiaryComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Модерация комментариев к дневникам.
 *
 * Read-side (index/counts, фильтры, счётчики, форматирование) живёт здесь.
 * Вся write-логика (транзакции, каскады, AdminLog, уведомления) —
 * в ModerateDiaryCommentAction (thin-controller pattern, аналогично
 * PhotoController → ModeratePhotoAction).
 *
 * Архитектура (Вариант A — полная аналогия с PhotoCommentController):
 *   - Статусы: pending / approved / rejected. Виртуальный «karantine» = soft-deleted.
 *   - Причины отклонения (только при status=rejected):
 *       пользовательские — profanity / insult / off_topic / spam / other
 *       системная        — parent_rejected (каскад)
 *   - Контекст bulk-операций хранится в admin_logs.action, не в reject_reason.
 */
class DiaryCommentController extends AdminController
{
    // ============================================
    // СПИСОК + СЧЁТЧИКИ
    // ============================================

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validateAndExtractFilters($request);

        $avatarQuery = fn($q) => $q->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb', 'path_medium', 'path_large', 'path_original'])
            ->orderByDesc('is_primary')->limit(1);
        $userSelect = ['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'];

        $query = Diary::query()
            ->whereHas('comments', fn($q) => $this->applyCommentFilters($q, $filters))
            ->with([
                'diaryRubric',
                'user' => fn($q) => $q->withTrashed()->select($userSelect)->with(['photos' => $avatarQuery]),
                'comments' => function ($q) use ($avatarQuery, $userSelect, $filters) {
                    $this->applyCommentFilters($q, $filters);
                    $q->with([
                        'user' => fn($uq) => $uq->withTrashed()->select($userSelect)->with(['photos' => $avatarQuery]),
                        'allReplies' => function ($q) use ($avatarQuery, $userSelect, $filters) {
                            $q->whereHas('user', fn($u) => $u->excludeStaff());
                            if ($filters['isKarantine']) {
                                $q->withTrashed();
                            } elseif ($filters['status'] !== 'all') {
                                $q->where('status', $filters['status']);
                            }
                            // Грузим parent (с trashed) — нужен фронту для guard'а:
                            // прятать кнопку restore/approve на ребёнке, если родитель rejected/trashed.
                            $q->with([
                                'user' => fn($uq) => $uq->withTrashed()->select($userSelect)->with(['photos' => $avatarQuery]),
                                'parent' => fn($pq) => $pq->withTrashed()->select(['id', 'status', 'deleted_at']),
                            ])->latest();
                        },
                    ])->latest();
                },
            ]);

        // Если указан diary_id — фильтруем строго по этому посту.
        if (!empty($filters['diary_id'])) {
            $query->where('id', (int) $filters['diary_id']);
        } elseif ($filters['isSearching']) {
            $query->where(function ($q) use ($filters) {
                if ($filters['isIdSearch']) {
                    $q->where('id', (int) $filters['search'])
                        ->orWhereHas('comments', fn($cq) => $cq->where('id', (int) $filters['search']))
                        ->orWhereHas('comments.allReplies', fn($rq) => $rq->where('id', (int) $filters['search']));
                } else {
                    $q->where('title', $filters['operator'], "%{$filters['search']}%")
                        ->orWhereHas('user', fn($uq) => $uq->withTrashed()->where('name', $filters['operator'], "%{$filters['search']}%"))
                        ->orWhereHas('comments.user', fn($cuq) => $cuq->withTrashed()->where('name', $filters['operator'], "%{$filters['search']}%"))
                        ->orWhereHas('comments.allReplies.user', fn($ruq) => $ruq->withTrashed()->where('name', $filters['operator'], "%{$filters['search']}%"));
                }
            });
        }

        // При поиске по ID — искомый комментарий первым.
        // CASE WHEN id = ? THEN 0 ELSE 1 END — приоритетная сортировка,
        // потом обычная latest() внутри каждой группы приоритета.
        // Симметрично с PhotoCommentController и PhotoController.
        // При поиске по ID — пост с искомым КОММЕНТАРИЕМ первым.
        // Проверяем не только ID поста (diaries.id), но и наличие комментария
        // с этим ID через подзапрос EXISTS.
        // Приоритет: 0 = пост содержит комментарий #search, 1 = пост с ID=search,
        // 2 = остальные (не должны попадать в выдачу, но на всякий случай).
        if ($filters['isIdSearch']) {
            $searchId = (int) $filters['search'];
            $query->orderByRaw(
                "CASE
                    WHEN EXISTS(SELECT 1 FROM diary_comments WHERE diary_id = diaries.id AND id = ?) THEN 0
                    WHEN id = ? THEN 1
                    ELSE 2
                END",
                [$searchId, $searchId]
            );
        }
        $query->latest()->latest('id');

        $diaries = $query->paginate($filters['perPage'], ['*'], 'page', $filters['page']);

        return response()->json([
            'data' => $diaries->map(fn($d) => $this->formatDiaryWithComments($d))->values()->all(),
            'meta' => [
                'current_page' => $diaries->currentPage(),
                'last_page'    => $diaries->lastPage(),
                'per_page'     => $diaries->perPage(),
                'total'        => $diaries->total(),
                'from'         => $diaries->firstItem(),
                'to'           => $diaries->lastItem(),
            ],
            'stats' => $this->getCounts(),
            'status' => $filters['status'],
        ]);
    }

    public function counts(): JsonResponse
    {
        return response()->json(['stats' => $this->getCounts()]);
    }

    // ============================================
    // ОДИНОЧНЫЕ ДЕЙСТВИЯ — делегируют ModerateDiaryCommentAction
    // ============================================

    public function approve(Request $request, int $id, ModerateDiaryCommentAction $action): JsonResponse
    {
        // parent загружаем с withTrashed — нужен Action'у для parent guard.
        $comment = DiaryComment::with(['parent' => fn($q) => $q->withTrashed()])->find($id);
        if (!$comment) {
            return $this->notFound('Комментарий');
        }

        try {
            $message = $action->approve($comment, $request->user());
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => $message]);
    }

    public function reject(RejectDiaryCommentRequest $request, int $id, ModerateDiaryCommentAction $action): JsonResponse
    {
        // Валидируем: причина должна быть ПОЛЬЗОВАТЕЛЬСКОЙ (не системной).
        $validated = $request->validated();

        $comment = DiaryComment::find($id);
        if (!$comment) {
            return $this->notFound('Комментарий');
        }

        return response()->json(['message' => $action->reject($comment, $request->user(), $validated['reason'])]);
    }

    public function softDelete(Request $request, int $id, ModerateDiaryCommentAction $action): JsonResponse
    {
        $comment = DiaryComment::find($id);
        if (!$comment) {
            return $this->notFound('Комментарий');
        }

        return response()->json(['message' => $action->softDelete($comment, $request->user())]);
    }

    public function restore(Request $request, int $id, ModerateDiaryCommentAction $action): JsonResponse
    {
        // parent загружаем с withTrashed — нужен Action'у для parent guard.
        $comment = DiaryComment::withTrashed()->with(['parent' => fn($q) => $q->withTrashed()])->find($id);
        if (!$comment) {
            return $this->notFound('Комментарий');
        }

        try {
            $message = $action->restore($comment, $request->user());
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => $message]);
    }

    public function destroy(Request $request, int $id, ModerateDiaryCommentAction $action): JsonResponse
    {
        $comment = DiaryComment::withTrashed()->find($id);
        if (!$comment) {
            return $this->notFound('Комментарий');
        }

        return response()->json(['message' => $action->destroy($comment, $request->user())]);
    }

    // ============================================
    // ПРИВАТНЫЕ ХЕЛПЕРЫ — ФИЛЬТРЫ И СЧЁТЧИКИ
    // ============================================

    private function validateAndExtractFilters(Request $request): array
    {
        $status = $request->string('status', 'pending')->toString();
        if (!in_array($status, ['all', 'pending', 'approved', 'rejected', 'karantine'], true)) {
            $status = 'pending';
        }

        $search = trim($request->string('q', '')->toString());
        $isIdSearch = $search !== '' && ctype_digit($search);

        // Если передан diary_id — мы смотрим комментарии конкретного поста.
        // В этом режиме отключаем обычный поиск и авто-детект статуса,
        // чтобы просто вывести дерево комментариев этого поста.
        $diaryId = $request->integer('diary_id');

        if (!$diaryId) {
            if ($isIdSearch) {
                $comment = DiaryComment::withTrashed()->find((int) $search);
                if ($comment) {
                    $detectedStatus = $comment->trashed() ? 'karantine' : $comment->status;
                    if ($detectedStatus !== $status) {
                        $status = $detectedStatus;
                    }
                } else {
                    $status = 'all';
                }
            }
        } else {
            // Если ищем по diary_id, обнуляем обычный поиск
            $search = '';
            $isIdSearch = false;
            $status = 'all'; // Показываем все статусы комментариев под этим постом
        }

        return [
            'status'      => $status,
            'isKarantine' => $status === 'karantine',
            'search'      => $search,
            'isSearching' => $search !== '',
            'isIdSearch'  => $isIdSearch,
            'diary_id'    => $diaryId,
            'operator'    => config('database.default') === 'pgsql' ? 'ilike' : 'like',
            'perPage'     => min(max((int) $request->integer('per_page', 5), 1), 50),
            'page'        => max((int) $request->integer('page', 1), 1),
        ];
    }

    private function applyCommentFilters($query, array $filters): void
    {
        $query->whereHas('user', fn($u) => $u->excludeStaff());
        $query->whereNull('parent_id');

        if ($filters['isKarantine']) {
            $query->withTrashed()->where(function ($sub) {
                $sub->whereNotNull('deleted_at')
                    ->orWhereHas('allReplies', fn($r) => $r->withTrashed()->whereNotNull('deleted_at')->whereHas('user', fn($u) => $u->excludeStaff()));
            });
        } elseif ($filters['status'] !== 'all') {
            $query->where(function ($sub) use ($filters) {
                $sub->where('status', $filters['status'])
                    ->orWhereHas('allReplies', fn($r) => $r->where('status', $filters['status'])->whereHas('user', fn($u) => $u->excludeStaff()));
            });
        }
    }

    /**
     * Счётчики для табов.
     * Один SQL-запрос с условной агрегацией. Фильтруем staff.
     */
    private function getCounts(): array
    {
        $row = DB::table('diary_comments')
            ->leftJoin('users', 'diary_comments.user_id', '=', 'users.id')
            ->where(function ($q) {
                $q->whereNull('users.id')
                    ->orWhereNotIn('users.role', ['admin', 'moderator']);
            })
            ->selectRaw(
                "COUNT(*) AS all_count, " .
                    "COUNT(*) FILTER (WHERE diary_comments.status = 'pending' AND diary_comments.deleted_at IS NULL) AS pending_count, " .
                    "COUNT(*) FILTER (WHERE diary_comments.status = 'approved' AND diary_comments.deleted_at IS NULL) AS approved_count, " .
                    "COUNT(*) FILTER (WHERE diary_comments.status = 'rejected' AND diary_comments.deleted_at IS NULL) AS rejected_count, " .
                    "COUNT(*) FILTER (WHERE diary_comments.deleted_at IS NOT NULL) AS karantine_count"
            )
            ->first();

        $stats = [
            'all'       => (int) ($row->all_count ?? 0),
            'pending'   => (int) ($row->pending_count ?? 0),
            'approved'  => (int) ($row->approved_count ?? 0),
            'rejected'  => (int) ($row->rejected_count ?? 0),
            'karantine' => (int) ($row->karantine_count ?? 0),
        ];
        $stats['total'] = $stats['all'];

        return $stats;
    }

    // ============================================
    // ФОРМАТИРОВАНИЕ ДЛЯ API
    // ============================================

    private function formatDiaryWithComments(Diary $d): array
    {
        return [
            'id'            => $d->id,
            'title'         => $d->title,
            'diary_rubric'  => $d->diaryRubric ? [
                'id'          => $d->diaryRubric->id,
                'name'        => $d->diaryRubric->name,
                'is_active'   => (bool) $d->diaryRubric->is_active,
                'is_personal' => $d->diaryRubric->user_id !== null,
                'user_id'     => $d->diaryRubric->user_id,
            ] : null,
            'user'          => $d->user ? AdminUserBadgeResource::nullable($d->user, withRole: true) : null,
            'comments'      => $d->comments->map(fn($c) => $this->formatComment($c))->values()->all(),
        ];
    }

    private function formatComment(DiaryComment $c): array
    {
        // Сводка о родителе — ровно то, что нужно фронту для guard'а кликабельности.
        $parentSummary = null;
        if ($c->parent_id && $c->parent) {
            $parentSummary = [
                'id'      => $c->parent->id,
                'status'  => $c->parent->status,
                'trashed' => $c->parent->trashed(),
            ];
        }

        return [
            'id'            => $c->id,
            'diary_id'      => $c->diary_id,
            'user_id'       => $c->user_id,
            'parent_id'     => $c->parent_id,
            'content'       => $c->content,
            'status'        => $c->status,
            'reject_reason' => $c->reject_reason,
            'moderated_by'  => $c->moderated_by,
            'moderated_at'  => $c->moderated_at?->toIso8601String(),
            'created_at'    => $c->created_at?->toIso8601String(),
            'updated_at'    => $c->updated_at?->toIso8601String(),
            'trashed'       => $c->trashed(),
            'likes_count'   => (int) $c->likes_count,
            'replies_count' => (int) $c->replies_count,
            'parent'        => $parentSummary,
            'user'          => $c->user ? AdminUserBadgeResource::nullable($c->user, withRole: true) : null,
            'replies'       => ($c->allReplies ?? collect())
                ->map(fn($r) => $this->formatComment($r))
                ->values()
                ->all(),
        ];
    }

}
