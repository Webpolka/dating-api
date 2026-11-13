<?php

namespace App\Actions\Admin\Moderation;

use App\Enums\CommentRejectReason;
use App\Models\DiaryComment;
use App\Models\User;
use App\Notifications\DiaryCommentModerated;
use App\Services\Admin\AdminLogger;
use App\Services\Moderation\CommentTreeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Бизнес-логика модерации комментариев к дневникам.
 * Извлечена из DiaryCommentController (thin-controller pattern, см. ModeratePhotoAction).
 *
 * Архитектура (Вариант A — полная аналогия с PhotoCommentController):
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
 *     потомки со своими причинами остаются rejected.
 *   - При destroy — все потомки force-deleted.
 *
 * Транзакции:
 *   - Все мутации с каскадом обёрнуты в DB::transaction.
 *   - Уведомления отправляются ПОСЛЕ commit.
 *
 * Логирование (структурированное):
 *   - Single actions: loggable = главный коммент.
 *     Каскадные потомки — в after.cascade.descendants (массив {id, parent_id}).
 *   - Если потомков > 50 — обрезаем массив, cascade_count остаётся точным.
 *
 * Уведомления (паттерн Tinder/Bumble/Reddit):
 *   - Уведомляем ТОЛЬКО автора основного действия.
 *   - Каскадных потомков НЕ уведомляем — они пострадали «за компанию».
 *   - soft-delete/restore — без уведомлений.
 *   - destroy — уведомляем автора основного коммента.
 *
 * Блокировка:
 *   - lockForUpdate в approve/reject/restore — два модератора одновременно
 *     не получат двойных уведомлений автору.
 */
class ModerateDiaryCommentAction
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
     * @param  DiaryComment $comment Отношение parent должно быть загружено с withTrashed (для parent guard).
     * @param  User         $admin   Кто модерировал.
     * @return string       Сообщение для JSON-ответа ['message' => ...] (HTTP 200).
     * @throws \DomainException Parent guard: ответ на комментарий в карантине или не approved → HTTP 422.
     */
    public function approve(DiaryComment $comment, User $admin): string
    {
        // Parent guard: нельзя одобрить ответ, если parent не approved или в карантине.
        if ($comment->parent_id && $comment->parent) {
            if ($comment->parent->trashed()) {
                throw new \DomainException('Нельзя одобрить ответ на комментарий в карантине');
            }
            if ($comment->parent->status !== DiaryComment::STATUS_APPROVED) {
                throw new \DomainException('Нельзя одобрить ответ на неодобренный комментарий');
            }
        }

        // Идемпотентность: уже approved — ничего не делаем.
        if ($comment->status === DiaryComment::STATUS_APPROVED) {
            return 'Комментарий уже одобрен';
        }

        $before = $this->getBeforeState($comment);

        // lockForUpdate — два модератора одновременно одобряют один комментарий.
        $alreadyApproved = false;
        DB::transaction(function () use ($comment, $admin, &$alreadyApproved) {
            $locked = DiaryComment::lockForUpdate()->find($comment->id);
            if ($locked && $locked->status === DiaryComment::STATUS_APPROVED) {
                $alreadyApproved = true;
                return;
            }
            $comment->approve($admin->id);
        });

        if ($alreadyApproved) {
            return 'Комментарий уже одобрен другим модератором';
        }

        $this->adminLogger->logCommentModeration('diary_comment.approve', $comment, $admin, $before, 'approved');
        $this->notifyAuthor($comment, 'approved');

        return 'Комментарий одобрен';
    }

    /**
     * Отклонить комментарий (с рекурсивным каскадом потомков).
     *
     * @param  DiaryComment $comment
     * @param  User         $admin   Кто модерировал.
     * @param  string       $reason  Пользовательская причина (валидирована RejectDiaryCommentRequest).
     * @return string       Сообщение для JSON-ответа ['message' => ...] (HTTP 200).
     */
    public function reject(DiaryComment $comment, User $admin, string $reason): string
    {
        // Идемпотентность: уже rejected с той же причиной — ничего не делаем.
        if ($comment->status === DiaryComment::STATUS_REJECTED && $comment->reject_reason === $reason) {
            return 'Комментарий уже отклонён с этой причиной';
        }

        $before = $this->getBeforeState($comment);

        $cascadeReason = CommentRejectReason::ParentRejected->value;

        // Собираем потомков, которых каскад действительно изменит
        // (только не-rejected и не-trashed — остальные не меняются).
        $allDescendantIds = $this->commentTree->collectDescendantIds(DiaryComment::class, [$comment->id], includeTrashed: true);
        $affectedDescendants = collect();
        if (!empty($allDescendantIds)) {
            $affectedDescendants = DiaryComment::whereIn('id', $allDescendantIds)
                ->where('status', '!=', DiaryComment::STATUS_REJECTED)
                ->whereNull('deleted_at')
                ->get(['id', 'parent_id']);
        }

        // lockForUpdate — два модератора одновременно reject'ят один комментарий.
        $alreadyRejected = false;
        DB::transaction(function () use ($comment, $admin, $reason, $cascadeReason, $affectedDescendants, &$alreadyRejected) {
            $locked = DiaryComment::lockForUpdate()->find($comment->id);
            if ($locked && $locked->status === DiaryComment::STATUS_REJECTED && $locked->reject_reason === $reason) {
                $alreadyRejected = true;
                return;
            }
            $comment->reject($admin->id, $reason);

            // Рекурсивный каскад: отклоняем ВСЕХ потомков.
            if ($affectedDescendants->isNotEmpty()) {
                DiaryComment::whereIn('id', $affectedDescendants->pluck('id')->all())
                    ->update([
                        'status'        => DiaryComment::STATUS_REJECTED,
                        'moderated_at'  => now(),
                        'reject_reason' => $cascadeReason,
                        'moderated_by'  => $admin->id,
                    ]);
            }
        });

        if ($alreadyRejected) {
            return 'Комментарий уже отклонён с этой причиной другим модератором';
        }

        // loggable = главный коммент. Каскадные потомки — в after.cascade.descendants.
        $this->adminLogger->logCommentModeration('diary_comment.reject', $comment, $admin, $before, 'rejected', [
            'reject_reason' => $reason,
            'cascade'       => $this->commentTree->buildCascadeLog($affectedDescendants),
        ]);

        // Уведомляем ТОЛЬКО автора основного коммента.
        $this->notifyAuthor($comment, 'rejected');

        return 'Комментарий отклонен';
    }

    /**
     * Отправить комментарий в карантин (soft-delete, с каскадом потомков).
     *
     * @param  DiaryComment $comment
     * @param  User         $admin   Кто модерировал.
     * @return string       Сообщение для JSON-ответа ['message' => ...] (HTTP 200).
     */
    public function softDelete(DiaryComment $comment, User $admin): string
    {
        $before = $this->getBeforeState($comment, true);

        // Собираем потомков (только не-trashed) — для лога.
        $allDescendantIds = $this->commentTree->collectDescendantIds(DiaryComment::class, [$comment->id], includeTrashed: true);
        $affectedDescendants = collect();
        if (!empty($allDescendantIds)) {
            $affectedDescendants = DiaryComment::whereIn('id', $allDescendantIds)
                ->whereNull('deleted_at')
                ->get(['id', 'parent_id']);
        }

        DB::transaction(function () use ($comment, $affectedDescendants) {
            // Рекурсивный каскад: отправляем в карантин ВСЕХ потомков (любую глубину).
            if ($affectedDescendants->isNotEmpty()) {
                DiaryComment::whereIn('id', $affectedDescendants->pluck('id')->all())
                    ->withTrashed()
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => now()]);
            }
            $comment->delete();
        });

        $this->adminLogger->logCommentModeration('diary_comment.soft_delete', $comment, $admin, $before, 'quarantined', [
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
     * @param  DiaryComment $comment Отношение parent должно быть загружено с withTrashed (для parent guard).
     * @param  User         $admin   Кто модерировал.
     * @return string       Сообщение для JSON-ответа ['message' => ...] (HTTP 200; текст зависит от типа restore).
     * @throws \DomainException Parent guard: родитель в карантине или отклонён → HTTP 422.
     */
    public function restore(DiaryComment $comment, User $admin): string
    {
        $isTrashed = $comment->trashed();
        $isRejected = $comment->status === DiaryComment::STATUS_REJECTED;

        // 3) PENDING / APPROVED: идемпотентность.
        if (!$isTrashed && !$isRejected) {
            return 'Комментарий уже в очереди или одобрен';
        }

        // Parent guard.
        if ($comment->parent_id && $comment->parent) {
            if ($comment->parent->trashed()) {
                throw new \DomainException('Нельзя восстановить ответ: родительский комментарий в карантине. Сначала восстановите родителя.');
            }
            if ($comment->parent->status === DiaryComment::STATUS_REJECTED) {
                throw new \DomainException('Нельзя восстановить ответ: родительский комментарий отклонён. Сначала пересмотрите родителя (восстановите или оставьте отклонённым).');
            }
        }

        $before = $this->getBeforeState($comment, true);

        // Собираем потомков для каскада.
        $allDescendantIds = $this->commentTree->collectDescendantIds(DiaryComment::class, [$comment->id], includeTrashed: true);
        $affectedDescendants = collect();
        if (!empty($allDescendantIds)) {
            $query = DiaryComment::withTrashed()->whereIn('id', $allDescendantIds);
            if ($isTrashed) {
                $query->whereNotNull('deleted_at');
            } else {
                $query->where('status', DiaryComment::STATUS_REJECTED)
                    ->where('reject_reason', CommentRejectReason::ParentRejected->value)
                    ->whereNull('deleted_at');
            }
            $affectedDescendants = $query->get(['id', 'parent_id', 'deleted_at']);
        }

        // lockForUpdate — два модератора одновременно restore один комментарий.
        $alreadyRestored = false;
        DB::transaction(function () use ($comment, $affectedDescendants, $isTrashed, &$alreadyRestored) {
            $locked = DiaryComment::withTrashed()->lockForUpdate()->find($comment->id);

            if ($isTrashed) {
                if ($locked && !$locked->trashed()) {
                    $alreadyRestored = true;
                    return;
                }
                $comment->restore();
                $comment->resetToPending();
            } else {
                if ($locked && $locked->status !== DiaryComment::STATUS_REJECTED) {
                    $alreadyRestored = true;
                    return;
                }
                $comment->resetToPending();
            }

            if ($affectedDescendants->isNotEmpty()) {
                $updateData = [
                    'status'        => DiaryComment::STATUS_PENDING,
                    'moderated_at'  => null,
                    'reject_reason' => null,
                    'moderated_by'  => null,
                ];
                $q = DiaryComment::withTrashed()->whereIn('id', $affectedDescendants->pluck('id')->all());
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

        $action = $isTrashed ? 'diary_comment.restore' : 'diary_comment.restore_from_rejected';
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
     * @param  DiaryComment $comment
     * @param  User         $admin   Кто модерировал.
     * @return string       Сообщение для JSON-ответа ['message' => ...] (HTTP 200).
     */
    public function destroy(DiaryComment $comment, User $admin): string
    {
        $before = $this->getBeforeState($comment, true);

        // Собираем всех потомков (включая trashed) для логирования перед удалением.
        $allDescendantIds = $this->commentTree->collectDescendantIds(DiaryComment::class, [$comment->id], includeTrashed: true);
        $descendantsForLog = collect();
        if (!empty($allDescendantIds)) {
            $descendantsForLog = DiaryComment::withTrashed()
                ->whereIn('id', $allDescendantIds)
                ->get(['id', 'parent_id', 'deleted_at']);
        }

        DB::transaction(function () use ($comment, $allDescendantIds) {
            if (!empty($allDescendantIds)) {
                DiaryComment::whereIn('id', $allDescendantIds)
                    ->withTrashed()
                    ->forceDelete();
            }
            $comment->forceDelete();
        });

        $this->adminLogger->logCommentModeration('diary_comment.destroy', $comment, $admin, $before, 'destroyed', [
            'cascade' => $this->commentTree->buildCascadeLog($descendantsForLog, includeTrashed: true),
        ]);

        // Уведомляем автора основного коммента об окончательном удалении.
        $this->notifyAuthor($comment, 'deleted');

        return 'Комментарий удален навсегда';
    }

    // ============================================
    // ХЕЛПЕРЫ ЛОГИРОВАНИЯ И УВЕДОМЛЕНИЙ
    // ============================================

    private function notifyAuthor(DiaryComment $comment, string $status): void
    {
        try {
            if ($comment->user) {
                $comment->user->notify(new DiaryCommentModerated(
                    $comment->id,
                    $comment->diary_id,
                    $comment->content,
                    $status,
                ));
            }
        } catch (\Exception $e) {
            Log::error('Ошибка уведомления о модерации комментария дневника: ' . $e->getMessage());
        }
    }

    private function getBeforeState(DiaryComment $comment, bool $includeDeleted = false): array
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
