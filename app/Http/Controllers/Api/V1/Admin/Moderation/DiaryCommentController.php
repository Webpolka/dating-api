<?php

namespace App\Http\Controllers\Api\V1\Admin\Moderation;

use App\Actions\V1\Admin\Moderation\ModerateDiaryCommentAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Moderation\RejectDiaryCommentRequest;
use App\Http\Resources\Api\V1\Admin\Moderation\DiaryWithCommentsResource;
use App\Models\Diary;
use App\Models\DiaryComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB; 

/**
 * Модерация комментариев к дневникам.
 *
 * Read-side здесь. Write-логика — в ModerateDiaryCommentAction.
 *
 * Архитектура (Вариант A — полная аналогия с PhotoCommentController):
 *   - Статусы: pending / approved / rejected. Виртуальный «karantine» = soft-deleted.
 *   - Причины: profanity / insult / off_topic / spam / other / parent_rejected (системная)
 *
 * Поддерживает ?diary_id={id} — дерево комментариев конкретного поста
 * (отключает авто-детект статуса и обычный поиск, показывает все статусы под этим постом).
 */
class DiaryCommentController extends AdminController
{
    // ============================================
    // СПИСОК + СЧЁТЧИКИ
    // ============================================

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validateAndExtractFilters($request);

        $query = Diary::query()
            ->whereHas('comments', fn($q) => $this->applyCommentFilters($q, $filters))
            ->with([
                'diaryRubric',
                'user' => fn($q) => $q->withTrashed()
                    ->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
                    ->withAvatar(),
                'comments' => function ($q) use ($filters) {
                    $this->applyCommentFilters($q, $filters);
                    $q->with([
                        'user' => fn($uq) => $uq->withTrashed()
                            ->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
                            ->withAvatar(),
                        'allReplies' => function ($q) use ($filters) {
                            $q->whereHas('user', fn($u) => $u->excludeStaff());
                            if ($filters['isKarantine']) {
                                $q->withTrashed();
                            } elseif ($filters['status'] !== 'all') {
                                $q->where('status', $filters['status']);
                            }
                            // parent (with trashed) — нужен фронту для guard'а кликабельности.
                            $q->with([
                                'user' => fn($uq) => $uq->withTrashed()
                                    ->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
                                    ->withAvatar(),
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

        // При поиске по ID — пост с искомым КОММЕНТАРИЕМ первым.
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
            'data' => DiaryWithCommentsResource::collection($diaries->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $diaries->currentPage(),
                'last_page'    => $diaries->lastPage(),
                'per_page'     => $diaries->perPage(),
                'total'        => $diaries->total(),
                'from'         => $diaries->firstItem(),
                'to'           => $diaries->lastItem(),
            ],
            'stats'  => $this->getCounts(),
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
        $comment = DiaryComment::with(['parent' => fn($q) => $q->withTrashed()])->find($id);
        if (!$comment) return $this->notFound('Комментарий');

        try {
            $message = $action->approve($comment, $request->user());
        } catch (\DomainException $e) {
            return $this->message($e->getMessage(), 422);
        }

        return $this->message($message);
    }

    public function reject(RejectDiaryCommentRequest $request, int $id, ModerateDiaryCommentAction $action): JsonResponse
    {
        $comment = DiaryComment::find($id);
        if (!$comment) return $this->notFound('Комментарий');

        return $this->message($action->reject($comment, $request->user(), $request->validated()['reason']));
    }

    public function softDelete(Request $request, int $id, ModerateDiaryCommentAction $action): JsonResponse
    {
        $comment = DiaryComment::find($id);
        if (!$comment) return $this->notFound('Комментарий');

        return $this->message($action->softDelete($comment, $request->user()));
    }

    public function restore(Request $request, int $id, ModerateDiaryCommentAction $action): JsonResponse
    {
        $comment = DiaryComment::withTrashed()->with(['parent' => fn($q) => $q->withTrashed()])->find($id);
        if (!$comment) return $this->notFound('Комментарий');

        try {
            $message = $action->restore($comment, $request->user());
        } catch (\DomainException $e) {
            return $this->message($e->getMessage(), 422);
        }

        return $this->message($message);
    }

    public function destroy(Request $request, int $id, ModerateDiaryCommentAction $action): JsonResponse
    {
        $comment = DiaryComment::withTrashed()->find($id);
        if (!$comment) return $this->notFound('Комментарий');

        return $this->message($action->destroy($comment, $request->user()));
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
            // Режим дерева комментариев одного поста — отключаем обычный поиск.
            $search = '';
            $isIdSearch = false;
            $status = 'all';
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
}
