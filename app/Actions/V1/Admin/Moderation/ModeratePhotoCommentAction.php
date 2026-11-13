<?php

namespace App\Actions\Admin\Moderation;

use App\Enums\CommentRejectReason;
use App\Models\AdminLog;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\User;
use App\Notifications\PhotoCommentModerated;
use App\Services\Admin\AdminLogger;
use App\Services\Moderation\CommentTreeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Бизнес-логика модерации комментариев к фото.
 * Извлечена из PhotoCommentController (thin-controller pattern, см. ModeratePhotoAction).
 *
 * Архитектура (Вариант A — Spam как причина, не статус):
 *   - Статусы: pending / approved / rejected. Виртуальный «karantine» = soft-deleted.
 *   - Причины отклонения (только при status=rejected):
 *       пользовательские — profanity / insult / off_topic / spam / other
 *       системная        — parent_rejected (каскад)
 *
 * Каскадная логика (рекурсивная, любая глубина):
 *   - При reject родителя — все потомки получают status=rejected + reason=parent_rejected.
 *   - При softDelete — все потомки soft-deleted (карантин).
 *   - При restore из карантина — все trashed-потомки восстанавливаются в pending.
 *   - При restore из rejected — потомки с parent_rejected возвращаются в pending;
 *     потомки со своими причинами (profanity/spam/...) остаются rejected.
 *   - При destroy — все потомки force-deleted.
 *
 * Транзакции:
 *   - Все мутации с каскадом обёрнуты в DB::transaction.
 *   - Уведомления отправляются ПОСЛЕ commit.
 *
 * Логирование (структурированное):
 *   - Single actions: loggable = главный коммент (тот, с которым действовал модератор).
 *     Каскадные потомки перечислены в after.cascade.descendants.
 *   - Per-photo bulk (approveRemaining/rejectRemaining): loggable = Photo.
 *     Клик в журнале ведёт на модерацию этого фото.
 *   - Global bulk (approveAllPending/rejectAllPending): loggable = null.
 *     В журнале показывается «Массовая операция», без ссылки.
 *   - Если потомков > 50 — обрезаем массив, но count остаётся точным.
 *
 * Уведомления (паттерн Tinder/Bumble/Reddit):
 *   - Уведомляем ТОЛЬКО автора основного действия.
 *   - Каскадных потомков НЕ уведомляем.
 *   - soft-delete/restore — без уведомлений.
 *   - destroy — уведомляем автора основного коммента.
 *   - bulk-операции — каждого автора ровно один раз.
 *
 * Блокировка:
 *   - lockForUpdate в approve/reject/restore и в bulk-операциях.
 */
class ModeratePhotoCommentAction
{
    public function __construct(
        private readonly AdminLogger $adminLogger,
        private readonly CommentTreeService $commentTree,
    ) {
    }

    // ============================================
    // ОДИНОЧНЫЕ ДЕЙСТВИЯ
    // ============================================

    /**
     * Одобрить комментарий.
     *
     * @param  PhotoComment $comment Отношение parent должно быть загружено с withTrashed (для parent guard).
     * @param  User         $admin   Кто модерировал.
     * @return string       Сообщение для JSON-ответа ['message' => ...] (HTTP 200).
     * @throws \DomainException Parent guard: ответ на комментарий в карантине или не approved → HTTP 422.
     */
    public function approve(PhotoComment $comment, User $admin): string
    {
        // Parent guard: нельзя одобрить ответ, если parent не approved или в карантине.
        if ($comment->parent_id && $comment->parent) {
            if ($comment->parent->trashed()) {
                throw new \DomainException('Нельзя одобрить ответ на комментарий в карантине');
            }
            if ($comment->parent->status !== PhotoComment::STATUS_APPROVED) {
                throw new \DomainException('Нельзя одобрить ответ на неодобренный комментарий');
            }
        }

        // Идемпотентность: уже approved — ничего не делаем.
        if ($comment->status === PhotoComment::STATUS_APPROVED) {
            return 'Комментарий уже одобрен';
        }

        $before = $this->getBeforeState($comment);

        // lockForUpdate — два модератора одновременно одобряют один комментарий.
        $alreadyApproved = false;
        DB::transaction(function () use ($comment, $admin, &$alreadyApproved) {
            $locked = PhotoComment::lockForUpdate()->find($comment->id);
            if ($locked && $locked->status === PhotoComment::STATUS_APPROVED) {
                $alreadyApproved = true;
                return;
            }
            $comment->approve($admin->id);
        });

        if ($alreadyApproved) {
            return 'Комментарий уже одобрен другим модератором';
        }

        $this->adminLogger->logCommentModeration('photo_comment.approve', $comment, $admin, $before, 'approved');
        $this->notifyAuthor($comment, 'approved');

        return 'Комментарий одобрен';
    }

    /**
     * Отклонить комментарий (с рекурсивным каскадом потомков).
     *
     * @param  PhotoComment $comment
     * @param  User         $admin   Кто модерировал.
     * @param  string       $reason  Пользовательская причина (валидирована RejectPhotoCommentRequest).
     * @return string       Сообщение для JSON-ответа ['message' => ...] (HTTP 200).
     */
    public function reject(PhotoComment $comment, User $admin, string $reason): string
    {
        // Идемпотентность: уже rejected с той же причиной — ничего не делаем.
        if ($comment->status === PhotoComment::STATUS_REJECTED && $comment->reject_reason === $reason) {
            return 'Комментарий уже отклонён с этой причиной';
        }

        $before = $this->getBeforeState($comment);

        $cascadeReason = CommentRejectReason::ParentRejected->value;

        // Собираем потомков, которых каскад действительно изменит
        // (только не-rejected и не-trashed — остальные не меняются).
        // Захватываем только id/parent_id для лога.
        $allDescendantIds = $this->commentTree->collectDescendantIds(PhotoComment::class, [$comment->id], includeTrashed: true);
        $affectedDescendants = collect();
        if (!empty($allDescendantIds)) {
            $affectedDescendants = PhotoComment::whereIn('id', $allDescendantIds)
                ->where('status', '!=', PhotoComment::STATUS_REJECTED)
                ->whereNull('deleted_at')
                ->get(['id', 'parent_id']);
        }

        // lockForUpdate — два модератора одновременно reject'ят один комментарий.
        $alreadyRejected = false;
        DB::transaction(function () use ($comment, $admin, $reason, $cascadeReason, $affectedDescendants, &$alreadyRejected) {
            $locked = PhotoComment::lockForUpdate()->find($comment->id);
            if ($locked && $locked->status === PhotoComment::STATUS_REJECTED && $locked->reject_reason === $reason) {
                $alreadyRejected = true;
                return;
            }
            $comment->reject($admin->id, $reason);

            if ($affectedDescendants->isNotEmpty()) {
                PhotoComment::whereIn('id', $affectedDescendants->pluck('id')->all())
                    ->update([
                        'status'        => PhotoComment::STATUS_REJECTED,
                        'moderated_at'  => now(),
                        'reject_reason' => $cascadeReason,
                        'moderated_by'  => $admin->id,
                    ]);
            }
        });

        if ($alreadyRejected) {
            return 'Комментарий уже отклонён с этой причиной другим модератором';
        }

        // loggable = главный коммент (его явно отклонил модератор).
        // Каскадные потомки — в after.cascade.descendants (только id и parent_id).
        $this->adminLogger->logCommentModeration('photo_comment.reject', $comment, $admin, $before, 'rejected', [
            'reject_reason' => $reason,
            'cascade'       => $this->commentTree->buildCascadeLog($affectedDescendants),
        ]);

        $this->notifyAuthor($comment, 'rejected');

        return 'Комментарий отклонен';
    }

    /**
     * Отправить комментарий в карантин (soft-delete, с каскадом потомков).
     *
     * @param  PhotoComment $comment
     * @param  User         $admin   Кто модерировал.
     * @return string       Сообщение для JSON-ответа ['message' => ...] (HTTP 200).
     */
    public function softDelete(PhotoComment $comment, User $admin): string
    {
        $before = $this->getBeforeState($comment, true);

        // Собираем потомков (только не-trashed) — для лога только id/parent_id.
        $allDescendantIds = $this->commentTree->collectDescendantIds(PhotoComment::class, [$comment->id], includeTrashed: true);
        $affectedDescendants = collect();
        if (!empty($allDescendantIds)) {
            $affectedDescendants = PhotoComment::whereIn('id', $allDescendantIds)
                ->whereNull('deleted_at')
                ->get(['id', 'parent_id']);
        }

        DB::transaction(function () use ($comment, $affectedDescendants) {
            if ($affectedDescendants->isNotEmpty()) {
                PhotoComment::whereIn('id', $affectedDescendants->pluck('id')->all())
                    ->withTrashed()
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => now()]);
            }
            $comment->delete();
        });

        $this->adminLogger->logCommentModeration('photo_comment.soft_delete', $comment, $admin, $before, 'quarantined', [
            'cascade' => $this->commentTree->buildCascadeLog($affectedDescendants),
        ]);

        // Без уведомления — коммент в карантине, модератор ещё может восстановить.
        return 'Комментарий перемещен в карантин';
    }

    /**
     * Восстановить комментарий (из карантина или из rejected, с каскадом потомков).
     *
     * Два сценария:
     *   1) TRASHED (карантин): restore() + resetToPending() + каскад trashed-потомков.
     *   2) REJECTED (не trashed): resetToPending() + каскад потомков с parent_rejected.
     *
     * @param  PhotoComment $comment Отношение parent должно быть загружено с withTrashed (для parent guard).
     * @param  User         $admin   Кто модерировал.
     * @return string       Сообщение для JSON-ответа ['message' => ...] (HTTP 200; текст зависит от типа restore).
     * @throws \DomainException Parent guard: родитель в карантине или отклонён → HTTP 422.
     */
    public function restore(PhotoComment $comment, User $admin): string
    {
        $isTrashed = $comment->trashed();
        $isRejected = $comment->status === PhotoComment::STATUS_REJECTED;

        // 3) PENDING / APPROVED: идемпотентность.
        if (!$isTrashed && !$isRejected) {
            return 'Комментарий уже в очереди или одобрен';
        }

        // Parent guard.
        if ($comment->parent_id && $comment->parent) {
            if ($comment->parent->trashed()) {
                throw new \DomainException('Нельзя восстановить ответ: родительский комментарий в карантине. Сначала восстановите родителя.');
            }
            if ($comment->parent->status === PhotoComment::STATUS_REJECTED) {
                throw new \DomainException('Нельзя восстановить ответ: родительский комментарий отклонён. Сначала пересмотрите родителя (восстановите или оставьте отклонённым).');
            }
        }

        $before = $this->getBeforeState($comment, true);

        // Собираем потомков для каскада — для лога только id/parent_id.
        $allDescendantIds = $this->commentTree->collectDescendantIds(PhotoComment::class, [$comment->id], includeTrashed: true);
        $affectedDescendants = collect();
        if (!empty($allDescendantIds)) {
            $query = PhotoComment::withTrashed()->whereIn('id', $allDescendantIds);
            if ($isTrashed) {
                $query->whereNotNull('deleted_at');
            } else {
                $query->where('status', PhotoComment::STATUS_REJECTED)
                    ->where('reject_reason', CommentRejectReason::ParentRejected->value)
                    ->whereNull('deleted_at');
            }
            $affectedDescendants = $query->get(['id', 'parent_id', 'deleted_at']);
        }

        // lockForUpdate — два модератора одновременно restore один комментарий.
        $alreadyRestored = false;
        DB::transaction(function () use ($comment, $affectedDescendants, $isTrashed, &$alreadyRestored) {
            $locked = PhotoComment::withTrashed()->lockForUpdate()->find($comment->id);

            if ($isTrashed) {
                if ($locked && !$locked->trashed()) {
                    $alreadyRestored = true;
                    return;
                }
                $comment->restore();
                $comment->resetToPending();
            } else {
                if ($locked && $locked->status !== PhotoComment::STATUS_REJECTED) {
                    $alreadyRestored = true;
                    return;
                }
                $comment->resetToPending();
            }

            if ($affectedDescendants->isNotEmpty()) {
                $updateData = [
                    'status'        => PhotoComment::STATUS_PENDING,
                    'moderated_at'  => null,
                    'reject_reason' => null,
                    'moderated_by'  => null,
                ];
                $q = PhotoComment::withTrashed()->whereIn('id', $affectedDescendants->pluck('id')->all());
                if ($isTrashed) {
                    $q->whereNotNull('deleted_at')
                        ->update(array_merge($updateData, ['deleted_at' => null]));
                } else {
                    $q->whereNull('deleted_at')->update($updateData);
                }
            }
        });

        if ($alreadyRestored) {
            return 'Комментарий уже восстановлен другим модератором';
        }

        $action = $isTrashed ? 'photo_comment.restore' : 'photo_comment.restore_from_rejected';
        $this->adminLogger->logCommentModeration($action, $comment, $admin, $before, 'pending', [
            'restore_type' => $isTrashed ? 'from_karantine' : 'from_rejected',
            'cascade'      => $this->commentTree->buildCascadeLog($affectedDescendants, includeTrashed: true),
        ]);

        // Без уведомления — restore это техническое действие, коммент в pending.
        return $isTrashed
            ? 'Комментарий восстановлен из карантина и возвращён на модерацию'
            : 'Комментарий возвращён на модерацию';
    }

    /**
     * Удалить комментарий навсегда (force-delete, с каскадом потомков).
     *
     * @param  PhotoComment $comment
     * @param  User         $admin   Кто модерировал.
     * @return string       Сообщение для JSON-ответа ['message' => ...] (HTTP 200).
     */
    public function destroy(PhotoComment $comment, User $admin): string
    {
        $before = $this->getBeforeState($comment, true);

        // Собираем всех потомков (включая trashed) — для лога только id/parent_id.
        $allDescendantIds = $this->commentTree->collectDescendantIds(PhotoComment::class, [$comment->id], includeTrashed: true);
        $descendantsForLog = collect();
        if (!empty($allDescendantIds)) {
            $descendantsForLog = PhotoComment::withTrashed()
                ->whereIn('id', $allDescendantIds)
                ->get(['id', 'parent_id', 'deleted_at']);
        }

        DB::transaction(function () use ($comment, $allDescendantIds) {
            if (!empty($allDescendantIds)) {
                PhotoComment::whereIn('id', $allDescendantIds)
                    ->withTrashed()
                    ->forceDelete();
            }
            $comment->forceDelete();
        });

        $this->adminLogger->logCommentModeration('photo_comment.destroy', $comment, $admin, $before, 'destroyed', [
            'cascade' => $this->commentTree->buildCascadeLog($descendantsForLog, includeTrashed: true),
        ]);

        // Уведомляем автора основного коммента об окончательном удалении.
        $this->notifyAuthor($comment, 'deleted');

        return 'Комментарий удален навсегда';
    }

    // ============================================
    // ДЕЙСТВИЯ ПО ФОТО
    // ============================================
    //
    // loggable = Photo (не первый коммент из batch!).
    // Клик в журнале ведёт на модерацию этого фото — модератор видит,
    // под каким фото были массово одобрены/отклонены комментарии.
    // ============================================

    /**
     * Одобрить все оставшиеся pending-комментарии фото (корни + ответы).
     *
     * @param  int  $photoId
     * @param  User $admin   Кто модерировал.
     * @return array{count: int, message: string} Данные для JSON-ответа (HTTP 200).
     */
    public function approveRemaining(int $photoId, User $admin): array
    {
        $rootIds = PhotoComment::where('photo_id', $photoId)
            ->whereNull('parent_id')
            ->pluck('id')
            ->all();

        $pendingComments = PhotoComment::where('status', PhotoComment::STATUS_PENDING)
            ->where(function ($q) use ($photoId, $rootIds) {
                $q->where('photo_id', $photoId);
                if (!empty($rootIds)) {
                    $q->orWhereIn('parent_id', $rootIds);
                }
            })
            ->with(['parent', 'user'])
            ->get();

        if ($pendingComments->isEmpty()) {
            return ['count' => 0, 'message' => 'Нет комментариев для одобрения'];
        }

        // loggable = Photo — клик в журнале ведёт на модерацию этого фото.
        $photo = Photo::withTrashed()->find($photoId);
        $count = $this->bulkApprove($pendingComments, $admin, $photo, 'photo', $photoId);

        return ['count' => $count, 'message' => "Одобрено {$count} комментариев"];
    }

    /**
     * Отклонить все оставшиеся pending-комментарии фото (корни + ответы, reason='other').
     *
     * @param  int    $photoId
     * @param  User   $admin   Кто модерировал.
     * @return array{count: int, message: string} Данные для JSON-ответа (HTTP 200).
     */
    public function rejectRemaining(int $photoId, User $admin): array
    {
        $rootIds = PhotoComment::where('photo_id', $photoId)
            ->whereNull('parent_id')
            ->pluck('id')
            ->all();

        $pendingComments = PhotoComment::where('status', PhotoComment::STATUS_PENDING)
            ->where(function ($q) use ($photoId, $rootIds) {
                $q->where('photo_id', $photoId);
                if (!empty($rootIds)) {
                    $q->orWhereIn('parent_id', $rootIds);
                }
            })
            ->with(['user'])
            ->get();

        if ($pendingComments->isEmpty()) {
            return ['count' => 0, 'message' => 'Нет комментариев для отклонения'];
        }

        $photo = Photo::withTrashed()->find($photoId);
        $count = $this->bulkReject($pendingComments, $admin, 'other', $photo, 'photo', $photoId);

        return ['count' => $count, 'message' => "Отклонено {$count} комментариев"];
    }

    // ============================================
    // ГЛОБАЛЬНЫЕ ДЕЙСТВИЯ
    // ============================================
    //
    // loggable = null — массовая операция по всей системе, конкретного объекта нет.
    // В журнале показывается «Массовая операция», без ссылки.
    // ============================================

    /**
     * Одобрить ВСЕ pending-комментарии системы (чанками по 200, топологически).
     *
     * @param  User $admin Кто модерировал.
     * @return array{count: int, message: string} Данные для JSON-ответа (HTTP 200).
     */
    public function approveAllPending(User $admin): array
    {
        $count = 0;

        PhotoComment::where('status', PhotoComment::STATUS_PENDING)
            ->with(['parent', 'user'])
            ->chunkById(200, function ($comments) use ($admin, &$count) {
                $count += $this->bulkApprove($comments, $admin, null, 'global');
            });

        $message = $count > 0 ? "Одобрено {$count} комментариев" : 'Нет комментариев для одобрения';

        return ['count' => $count, 'message' => $message];
    }

    /**
     * Отклонить ВСЕ pending-комментарии системы (чанками по 200, с каскадом потомков).
     *
     * @param  User $admin Кто модерировал.
     * @return array{count: int, message: string} Данные для JSON-ответа (HTTP 200).
     */
    public function rejectAllPending(User $admin): array
    {
        $count = 0;

        PhotoComment::where('status', PhotoComment::STATUS_PENDING)
            ->with(['user'])
            ->chunkById(200, function ($comments) use ($admin, &$count) {
                $count += $this->bulkReject($comments, $admin, 'other', null, 'global');
            });

        $message = $count > 0 ? "Отклонено {$count} комментариев" : 'Нет комментариев для отклонения';

        return ['count' => $count, 'message' => $message];
    }

    // ============================================
    // ХЕЛПЕРЫ BULK-ОПЕРАЦИЙ
    // ============================================
    //
    // $loggable — модель для записи в admin_logs.loggable_*
    //   - Photo для per-photo bulk (клик ведёт на модерацию фото)
    //   - null для global bulk (в журнале «Массовая операция», без ссылки)
    //
    // $bulkContext — 'photo' или 'global', записывается в after для прозрачности.
    // ============================================

    /**
     * Bulk approve с топологической обработкой глубоких деревьев.
     * lockForUpdate на финальный batch — защита от race с single-approve.
     *
     * @param  iterable $comments    Коллекция pending-комментариев (с загруженным parent для топологии).
     * @param  User     $admin
     * @param  Model|null $loggable  Photo | null
     * @param  string   $bulkContext 'photo' | 'global'
     * @param  int|null $photoId
     * @return int      Число реально одобренных комментариев.
     */
    private function bulkApprove($comments, User $admin, $loggable = null, string $bulkContext = 'global', ?int $photoId = null): int
    {
        $approvedIds = [];
        $approvedIdSet = [];
        $notifiedUsers = [];

        $maxIterations = $comments->count() + 1;
        for ($i = 0; $i < $maxIterations; $i++) {
            $addedThisIteration = 0;

            foreach ($comments as $comment) {
                if (isset($approvedIdSet[$comment->id])) {
                    continue;
                }

                if (!$comment->parent_id) {
                    $approvedIds[] = $comment->id;
                    $approvedIdSet[$comment->id] = true;
                    $addedThisIteration++;
                    continue;
                }

                $parentApprovedInDb = $comment->parent && $comment->parent->status === PhotoComment::STATUS_APPROVED;
                $parentApprovedInBatch = isset($approvedIdSet[$comment->parent_id]);

                if ($parentApprovedInDb || $parentApprovedInBatch) {
                    $approvedIds[] = $comment->id;
                    $approvedIdSet[$comment->id] = true;
                    $addedThisIteration++;
                }
            }

            if ($addedThisIteration === 0) {
                break;
            }
        }

        if (empty($approvedIds)) return 0;

        $actuallyUpdatedIds = [];

        DB::transaction(function () use ($approvedIds, $admin, &$actuallyUpdatedIds) {
            $alreadyApprovedIds = PhotoComment::whereIn('id', $approvedIds)
                ->lockForUpdate()
                ->where('status', PhotoComment::STATUS_APPROVED)
                ->pluck('id')
                ->all();
            $alreadyApprovedSet = array_flip($alreadyApprovedIds);

            $actuallyUpdatedIds = array_values(array_filter($approvedIds, fn($id) => !isset($alreadyApprovedSet[$id])));

            if (!empty($actuallyUpdatedIds)) {
                PhotoComment::whereIn('id', $actuallyUpdatedIds)->update([
                    'status'        => PhotoComment::STATUS_APPROVED,
                    'moderated_at'  => now(),
                    'reject_reason' => null,
                    'moderated_by'  => $admin->id,
                ]);
            }
        });

        if (empty($actuallyUpdatedIds)) return 0;

        // Уведомления ПОСЛЕ commit — каждому автору ровно одно.
        $approvedComments = PhotoComment::whereIn('id', $actuallyUpdatedIds)->with('user')->get();
        foreach ($approvedComments as $comment) {
            if ($comment->user_id && !isset($notifiedUsers[$comment->user_id])) {
                $this->notifyAuthor($comment, 'approved');
                $notifiedUsers[$comment->user_id] = true;
            }
        }

        // Структурированный лог: только id (previous_status/reason — лишний шум).
        $approvedLog = [];
        foreach ($actuallyUpdatedIds as $id) {
            $approvedLog[] = ['id' => $id];
            if (count($approvedLog) >= 50) break;
        }

        $after = [
            'count'        => count($actuallyUpdatedIds),
            'approved'     => $approvedLog,
            'truncated'    => count($actuallyUpdatedIds) > 50,
            'bulk_context' => $bulkContext,
            'moderated_by' => $admin->id,
        ];
        if ($photoId) {
            $after['photo_id'] = $photoId;
        }

        AdminLog::record(
            'photo_comment.mass_approve',
            $loggable,
            $admin,
            null,
            $after,
            participants: array_keys($notifiedUsers)
        );

        return count($actuallyUpdatedIds);
    }

    /**
     * Bulk reject с рекурсивным каскадом на ВСЕХ потомков.
     * lockForUpdate на rejected + descendant — защита от race с single-reject.
     * Уведомляем только авторов явно rejected-комментариев (не каскадных детей).
     *
     * @param  iterable $comments    Коллекция pending-комментариев.
     * @param  User     $admin
     * @param  string   $reason      Пользовательская причина (по умолчанию 'other').
     * @param  Model|null $loggable  Photo | null
     * @param  string   $bulkContext 'photo' | 'global'
     * @param  int|null $photoId
     * @return int      Число явно отклонённых комментариев (без каскадных потомков).
     */
    private function bulkReject($comments, User $admin, string $reason = 'other', $loggable = null, string $bulkContext = 'global', ?int $photoId = null): int
    {
        $rejectedIds = [];
        $notifiedUsers = [];

        foreach ($comments as $comment) {
            $rejectedIds[] = $comment->id;
        }

        if (empty($rejectedIds)) return 0;

        $cascadeReason = CommentRejectReason::ParentRejected->value;

        $descendantIds = [];
        $previousDescendantStates = collect();

        DB::transaction(function () use ($rejectedIds, $admin, $reason, $cascadeReason, &$descendantIds, &$previousDescendantStates) {
            PhotoComment::whereIn('id', $rejectedIds)->lockForUpdate()->get();

            PhotoComment::whereIn('id', $rejectedIds)->update([
                'status'        => PhotoComment::STATUS_REJECTED,
                'moderated_at'  => now(),
                'reject_reason' => $reason,
                'moderated_by'  => $admin->id,
            ]);

            $descendantIds = $this->commentTree->collectDescendantIds(PhotoComment::class, $rejectedIds, includeTrashed: true);

            if (!empty($descendantIds)) {
                // Захватываем parent_id для лога (только id и parent_id — без previous_status/reason).
                $previousDescendantStates = PhotoComment::whereIn('id', $descendantIds)
                    ->where('status', '!=', PhotoComment::STATUS_REJECTED)
                    ->whereNull('deleted_at')
                    ->get(['id', 'parent_id'])
                    ->keyBy('id');

                PhotoComment::whereIn('id', $descendantIds)->lockForUpdate()->get();

                PhotoComment::whereIn('id', $descendantIds)
                    ->where('status', '!=', PhotoComment::STATUS_REJECTED)
                    ->whereNull('deleted_at')
                    ->update([
                        'status'        => PhotoComment::STATUS_REJECTED,
                        'moderated_at'  => now(),
                        'reject_reason' => $cascadeReason,
                        'moderated_by'  => $admin->id,
                    ]);
            }
        });

        // Уведомления ПОСЛЕ commit — только авторам явно rejected-комментариев.
        $rejectedComments = PhotoComment::whereIn('id', $rejectedIds)->with('user')->get();
        foreach ($rejectedComments as $comment) {
            if ($comment->user_id && !isset($notifiedUsers[$comment->user_id])) {
                $this->notifyAuthor($comment, 'rejected');
                $notifiedUsers[$comment->user_id] = true;
            }
        }

        // Структурированный лог: только id и parent_id (previous_status/reason — лишний шум).
        $directlyRejectedLog = [];
        foreach ($rejectedIds as $id) {
            $directlyRejectedLog[] = ['id' => $id];
            if (count($directlyRejectedLog) >= 50) break;
        }

        $cascadedDescendantsLog = [];
        foreach ($previousDescendantStates as $desc) {
            $cascadedDescendantsLog[] = [
                'id'        => $desc->id,
                'parent_id' => $desc->parent_id,
            ];
            if (count($cascadedDescendantsLog) >= 50) break;
        }

        $after = [
            'count'              => count($rejectedIds),
            'reason'             => $reason,
            'directly_rejected'  => $directlyRejectedLog,
            'directly_truncated' => count($rejectedIds) > 50,
            'cascade'            => [
                'count'       => count($descendantIds),
                'descendants' => $cascadedDescendantsLog,
                'truncated'   => count($descendantIds) > 50,
            ],
            'bulk_context'       => $bulkContext,
            'moderated_by'       => $admin->id,
        ];
        if ($photoId) {
            $after['photo_id'] = $photoId;
        }

        AdminLog::record(
            'photo_comment.mass_reject',
            $loggable,
            $admin,
            null,
            $after,
            participants: array_keys($notifiedUsers)
        );

        return count($rejectedIds);
    }

    // ============================================
    // ХЕЛПЕРЫ ЛОГИРОВАНИЯ И УВЕДОМЛЕНИЙ
    // ============================================

    private function notifyAuthor(PhotoComment $comment, string $status): void
    {
        try {
            if ($comment->user) {
                $comment->user->notify(new PhotoCommentModerated(
                    $comment->id,
                    $comment->photo_id,
                    $comment->content,
                    $status,
                ));
            }
        } catch (\Exception $e) {
            Log::error('Ошибка уведомления о модерации комментария: ' . $e->getMessage());
        }
    }

    private function getBeforeState(PhotoComment $comment, bool $includeDeleted = false): array
    {
        $before = [
            'status'        => $comment->getOriginal('status'),
            'reject_reason' => $comment->getOriginal('reject_reason'),
        ];
        if ($includeDeleted) {
            $before['deleted_at'] = $comment->getOriginal('deleted_at');
        }
        return $before;
    }
}
