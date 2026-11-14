<?php

namespace App\Http\Controllers\Api\V1\Admin\Moderation;

use App\Actions\V1\Admin\Moderation\ModeratePhotoCommentAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Moderation\RejectPhotoCommentRequest;
use App\Http\Resources\Api\V1\Admin\Moderation\PhotoWithCommentsResource;
use App\Models\Photo;
use App\Models\PhotoComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Модерация комментариев к фото.
 *
 * Read-side (index/counts, фильтры, счётчики, форматирование через Resource)
 * живёт здесь. Вся write-логика — в ModeratePhotoCommentAction.
 *
 * Архитектура (Вариант A — Spam как причина, не статус):
 *   - Статусы: pending / approved / rejected. Виртуальный «karantine» = soft-deleted.
 *   - Причины отклонения: profanity / insult / off_topic / spam / other / parent_rejected (системная)
 */
class PhotoCommentController extends AdminController
{
    // ============================================
    // СПИСОК + СЧЁТЧИКИ
    // ============================================

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validateAndExtractFilters($request);

        $photos = Photo::withTrashed()
            ->whereHas('comments', function ($q) use ($filters) {
                $q->whereNull('parent_id');
                $q->whereHas('user', fn($u) => $u->excludeStaff());
                $this->applyCommentFilters($q, $filters);
            })
            ->with([
                'user' => fn($q) => $q->withTrashed()
                    ->select(['id', 'name', 'email', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
                    ->withAvatar(),
                'comments' => function ($q) use ($filters) {
                    $q->whereNull('parent_id');
                    $q->whereHas('user', fn($u) => $u->excludeStaff());
                    $this->applyCommentFilters($q, $filters);
                    $q->with([
                        'user' => fn($uq) => $uq->withTrashed()
                            ->select(['id', 'name', 'email', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
                            ->withAvatar(),
                        'allReplies' => function ($q) use ($filters) {
                            $q->whereHas('user', fn($u) => $u->excludeStaff());
                            if ($filters['isKarantine']) {
                                $q->withTrashed();
                            } elseif ($filters['status'] !== 'all') {
                                $q->where('status', $filters['status']);
                            }
                            $q->with([
                                'user' => fn($uq) => $uq->withTrashed()
                                    ->select(['id', 'name', 'email', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
                                    ->withAvatar(),
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
            'data'   => PhotoWithCommentsResource::collection($photos->getCollection())->resolve($request),
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
        $comment = PhotoComment::with(['parent' => fn($q) => $q->withTrashed()])->find($id);
        if (!$comment) return $this->notFound('Комментарий');

        try {
            $message = $action->approve($comment, $request->user());
        } catch (\DomainException $e) {
            return $this->message($e->getMessage(), 422);
        }

        return $this->message($message);
    }

    public function reject(RejectPhotoCommentRequest $request, int $id, ModeratePhotoCommentAction $action): JsonResponse
    {
        $comment = PhotoComment::find($id);
        if (!$comment) return $this->notFound('Комментарий');

        return $this->message($action->reject($comment, $request->user(), $request->validated()['reason']));
    }

    public function softDelete(Request $request, int $id, ModeratePhotoCommentAction $action): JsonResponse
    {
        $comment = PhotoComment::find($id);
        if (!$comment) return $this->notFound('Комментарий');

        return $this->message($action->softDelete($comment, $request->user()));
    }

    public function restore(Request $request, int $id, ModeratePhotoCommentAction $action): JsonResponse
    {
        $comment = PhotoComment::withTrashed()->with(['parent' => fn($q) => $q->withTrashed()])->find($id);
        if (!$comment) return $this->notFound('Комментарий');

        try {
            $message = $action->restore($comment, $request->user());
        } catch (\DomainException $e) {
            return $this->message($e->getMessage(), 422);
        }

        return $this->message($message);
    }

    public function destroy(Request $request, int $id, ModeratePhotoCommentAction $action): JsonResponse
    {
        $comment = PhotoComment::withTrashed()->find($id);
        if (!$comment) return $this->notFound('Комментарий');

        return $this->message($action->destroy($comment, $request->user()));
    }

    // ============================================
    // ДЕЙСТВИЯ ПО ФОТО / ГЛОБАЛЬНЫЕ — делегируют Action
    // Action возвращает ['count' => int, 'message' => string]
    // ============================================

    public function approveRemaining(Request $request, int $photoId, ModeratePhotoCommentAction $action): JsonResponse
    {
        return response()->json($action->approveRemaining($photoId, $request->user()));
    }

    public function rejectRemaining(Request $request, int $photoId, ModeratePhotoCommentAction $action): JsonResponse
    {
        return response()->json($action->rejectRemaining($photoId, $request->user()));
    }

    public function approveAllPending(Request $request, ModeratePhotoCommentAction $action): JsonResponse
    {
        return response()->json($action->approveAllPending($request->user()));
    }

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
}
