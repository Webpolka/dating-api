<?php

namespace App\Http\Controllers\Admin\V1\Moderation;

use App\Actions\Admin\Moderation\ModeratePhotoCommentAction;
use App\Http\Controllers\AdminController;
use App\Http\Requests\Admin\V1\Moderation\RejectPhotoCommentRequest;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Http\Resources\Admin\V1\Users\AdminUserBadgeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Модерация комментариев к фото.
 *
 * Read-side (index/counts, фильтры, счётчики, форматирование) живёт здесь.
 * Вся write-логика (транзакции, каскады, bulk-операции, AdminLog,
 * уведомления) — в ModeratePhotoCommentAction (thin-controller pattern,
 * аналогично PhotoController → ModeratePhotoAction и
 * DiaryCommentController → ModerateDiaryCommentAction).
 *
 * Архитектура (Вариант A — Spam как причина, не статус):
 *   - Статусы: pending / approved / rejected. Виртуальный «karantine» = soft-deleted.
 *   - Причины отклонения (только при status=rejected):
 *       пользовательские — profanity / insult / off_topic / spam / other
 *       системная        — parent_rejected (каскад)
 *   - Контекст bulk-операций хранится в admin_logs.action, не в reject_reason.
 */
class PhotoCommentController extends AdminController
{
    // ============================================
    // СПИСОК + СЧЁТЧИКИ
    // ============================================

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validateAndExtractFilters($request);

        $userAvatarQuery = fn($q) => $q->withTrashed()
            ->select(['id', 'name', 'email', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
            ->with([
                'photos' => fn($sq) => $sq->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb', 'path_medium', 'path_large', 'path_original'])
                    ->where('is_primary', true)->limit(1)
            ]);

        $photos = Photo::withTrashed()
            ->whereHas('comments', function ($q) use ($userAvatarQuery, $filters) {
                $q->whereNull('parent_id');
                $q->whereHas('user', fn($u) => $u->excludeStaff());
                $this->applyCommentFilters($q, $filters);
            })
            ->with([
                'user' => fn($q) => $q->withTrashed()->select(['id', 'name', 'email', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at']),
                'comments' => function ($q) use ($userAvatarQuery, $filters) {
                    $q->whereNull('parent_id');
                    $q->whereHas('user', fn($u) => $u->excludeStaff());
                    $this->applyCommentFilters($q, $filters);
                    $q->with([
                        'user' => $userAvatarQuery,
                        'allReplies' => function ($q) use ($userAvatarQuery, $filters) {
                            $q->whereHas('user', fn($u) => $u->excludeStaff());
                            if ($filters['isKarantine']) {
                                $q->withTrashed();
                            } elseif ($filters['status'] !== 'all') {
                                $q->where('status', $filters['status']);
                            }
                            $q->with([
                                'user' => $userAvatarQuery,
                                'parent' => fn($pq) => $pq->withTrashed()->select(['id', 'status', 'deleted_at']),
                            ]);
                        },
                    ]);
                },
                'album' => fn($q) => $q->select(['id', 'name']),
            ])
            ->orderByDesc('id')
            ->paginate($filters['perPage'], page: $filters['page']);

        return response()->json([
            'mode'   => 'list',
            'data'   => $photos->getCollection()->map(fn($p) => $this->formatPhotoWithComments($p))->values()->all(),
            'meta'   => [
                'current_page' => $photos->currentPage(),
                'last_page'    => $photos->lastPage(),
                'per_page'     => $photos->perPage(),
                'total'        => $photos->total(),
                'from'         => $photos->firstItem(),
                'to'           => $photos->lastItem(),
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
    // ОДИНОЧНЫЕ ДЕЙСТВИЯ — делегируют ModeratePhotoCommentAction
    // ============================================

    public function approve(Request $request, int $id, ModeratePhotoCommentAction $action): JsonResponse
    {
        // parent загружаем с withTrashed — нужен Action'у для parent guard.
        $comment = PhotoComment::with(['parent' => fn($q) => $q->withTrashed()])->find($id);
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

    public function reject(RejectPhotoCommentRequest $request, int $id, ModeratePhotoCommentAction $action): JsonResponse
    {
        // Валидируем: причина должна быть ПОЛЬЗОВАТЕЛЬСКОЙ (не системной).
        $validated = $request->validated();

        $comment = PhotoComment::find($id);
        if (!$comment) {
            return $this->notFound('Комментарий');
        }

        return response()->json(['message' => $action->reject($comment, $request->user(), $validated['reason'])]);
    }

    public function softDelete(Request $request, int $id, ModeratePhotoCommentAction $action): JsonResponse
    {
        $comment = PhotoComment::find($id);
        if (!$comment) {
            return $this->notFound('Комментарий');
        }

        return response()->json(['message' => $action->softDelete($comment, $request->user())]);
    }

    public function restore(Request $request, int $id, ModeratePhotoCommentAction $action): JsonResponse
    {
        // parent загружаем с withTrashed — нужен Action'у для parent guard.
        $comment = PhotoComment::withTrashed()->with(['parent' => fn($q) => $q->withTrashed()])->find($id);
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

    public function destroy(Request $request, int $id, ModeratePhotoCommentAction $action): JsonResponse
    {
        $comment = PhotoComment::withTrashed()->find($id);
        if (!$comment) {
            return $this->notFound('Комментарий');
        }

        return response()->json(['message' => $action->destroy($comment, $request->user())]);
    }

    // ============================================
    // ДЕЙСТВИЯ ПО ФОТО — делегируют ModeratePhotoCommentAction
    // ============================================

    /**
     * Action возвращает ['count' => int, 'message' => string].
     */
    public function approveRemaining(Request $request, int $photoId, ModeratePhotoCommentAction $action): JsonResponse
    {
        return response()->json($action->approveRemaining($photoId, $request->user()));
    }

    /**
     * Action возвращает ['count' => int, 'message' => string].
     */
    public function rejectRemaining(Request $request, int $photoId, ModeratePhotoCommentAction $action): JsonResponse
    {
        return response()->json($action->rejectRemaining($photoId, $request->user()));
    }

    // ============================================
    // ГЛОБАЛЬНЫЕ ДЕЙСТВИЯ — делегируют ModeratePhotoCommentAction
    // ============================================

    /**
     * Action возвращает ['count' => int, 'message' => string].
     */
    public function approveAllPending(Request $request, ModeratePhotoCommentAction $action): JsonResponse
    {
        return response()->json($action->approveAllPending($request->user()));
    }

    /**
     * Action возвращает ['count' => int, 'message' => string].
     */
    public function rejectAllPending(Request $request, ModeratePhotoCommentAction $action): JsonResponse
    {
        return response()->json($action->rejectAllPending($request->user()));
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

        if ($isIdSearch) {
            $comment = PhotoComment::withTrashed()->find((int) $search);
            if ($comment) {
                $detectedStatus = $comment->trashed() ? 'karantine' : $comment->status;
                if ($detectedStatus !== $status) {
                    $status = $detectedStatus;
                }
            } else {
                $status = 'all';
            }
        }

        return [
            'status'      => $status,
            'isKarantine' => $status === 'karantine',
            'search'      => $search,
            'isSearching' => $search !== '',
            'isIdSearch'  => $isIdSearch,
            'operator'    => config('database.default') === 'pgsql' ? 'ilike' : 'like',
            'perPage'     => min(max((int) $request->integer('per_page', 10), 1), 50),
            'page'        => max((int) $request->integer('page', 1), 1),
        ];
    }

    private function applyCommentFilters($q, array $filters): void
    {
        $q->whereHas('user', fn($u) => $u->excludeStaff());
        $q->whereNull('parent_id');

        if ($filters['isKarantine']) {
            $q->withTrashed()->where(function ($sub) {
                $sub->whereNotNull('deleted_at')
                    ->orWhereHas('allReplies', fn($r) => $r->withTrashed()->whereNotNull('deleted_at')->whereHas('user', fn($u) => $u->excludeStaff()));
            });
        } elseif ($filters['status'] !== 'all') {
            $q->where(function ($sub) use ($filters) {
                $sub->where('status', $filters['status'])
                    ->orWhereHas('allReplies', fn($r) => $r->where('status', $filters['status'])->whereHas('user', fn($u) => $u->excludeStaff()));
            });
        }

        if ($filters['isSearching']) {
            $q->where(function ($sub) use ($filters) {
                if ($filters['isIdSearch']) {
                    $sub->where('id', (int) $filters['search'])
                        ->orWhereHas('allReplies', fn($r) => $r->where('id', (int) $filters['search'])->whereHas('user', fn($u) => $u->excludeStaff()));
                } else {
                    $sub->where('content', $filters['operator'], "%{$filters['search']}%")
                        ->orWhereHas('user', fn($u) => $u->withTrashed()->where('name', $filters['operator'], "%{$filters['search']}%"))
                        ->orWhereHas('allReplies', function ($r) use ($filters) {
                            $r->where(function ($rSub) use ($filters) {
                                $rSub->where('content', $filters['operator'], "%{$filters['search']}%")
                                    ->orWhereHas('user', fn($u) => $u->withTrashed()->where('name', $filters['operator'], "%{$filters['search']}%"));
                            })->whereHas('user', fn($u) => $u->excludeStaff());
                        });
                }
            });
        }
    }

    /**
     * Счётчики для табов.
     * Один SQL-запрос с условной агрегацией. Фильтруем staff (admin/moderator).
     */
    private function getCounts(): array
    {
        $row = DB::table('photo_comments')
            ->leftJoin('users', 'photo_comments.user_id', '=', 'users.id')
            ->where(function ($q) {
                $q->whereNull('users.id')
                    ->orWhereNotIn('users.role', ['admin', 'moderator']);
            })
            ->selectRaw(
                "COUNT(*) AS all_count, " .
                    "COUNT(*) FILTER (WHERE photo_comments.status = 'pending' AND photo_comments.deleted_at IS NULL) AS pending_count, " .
                    "COUNT(*) FILTER (WHERE photo_comments.status = 'approved' AND photo_comments.deleted_at IS NULL) AS approved_count, " .
                    "COUNT(*) FILTER (WHERE photo_comments.status = 'rejected' AND photo_comments.deleted_at IS NULL) AS rejected_count, " .
                    "COUNT(*) FILTER (WHERE photo_comments.deleted_at IS NOT NULL) AS karantine_count"
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

    private function formatPhotoWithComments(Photo $p): array
    {
        $comments = $p->comments ?? collect();

        $pendingCount = $comments->where('status', PhotoComment::STATUS_PENDING)->count()
            + $comments->flatMap->allReplies->where('status', PhotoComment::STATUS_PENDING)->count();

        return [
            'id'            => $p->id,
            'user_id'       => $p->user_id,
            'is_primary'    => (bool) $p->is_primary,
            'is_intimate'   => (bool) $p->is_intimate,
            'status'        => $p->status,
            'trashed'       => $p->trashed(),
            'thumb_url'     => $p->thumb_url,
            'medium_url'    => $p->medium_url,
            'large_url'     => $p->large_url,
            'original_url'  => $p->original_url,
            'album'         => $p->album ? ['id' => $p->album->id, 'name' => $p->album->name] : null,
            'user'          => $p->user ? AdminUserBadgeResource::nullable($p->user) : null,
            'pending_count' => $pendingCount,
            'comments'      => $comments->map(fn($c) => $this->formatComment($c))->values()->all(),
        ];
    }

    private function formatComment(PhotoComment $c): array
    {
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
            'photo_id'      => $c->photo_id,
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
            'is_pinned'     => (bool) $c->is_pinned,
            'likes_count'   => (int) $c->likes_count,
            'replies_count' => (int) $c->replies_count,
            'parent'        => $parentSummary,
            'user'          => $c->user ? AdminUserBadgeResource::nullable($c->user) : null,
            'replies'       => ($c->allReplies ?? collect())
                ->map(fn($r) => $this->formatComment($r))
                ->values()
                ->all(),
        ];
    }

}
