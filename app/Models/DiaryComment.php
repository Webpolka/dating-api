<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Модель комментария к дневнику.
 *
 * Стратегия модерации (Вариант A — симметрично с PhotoComment):
 *   - 3 статуса: pending / approved / rejected. Спам — это причина отклонения, не статус.
 *   - Виртуальный «karantine» — это soft-deleted (trashed) запись любого статуса.
 *   - Причина reject_reason ставится ТОЛЬКО при status=rejected
 *     (включая каскад parent_rejected).
 *   - pending/approved всегда имеют reject_reason=null.
 *
 * Инварианты (гарантируются CHECK-констрейнтом миграции):
 *   - status=rejected → reject_reason ∈ {profanity, insult, off_topic, spam, other, parent_rejected}
 *   - status ∈ {pending, approved} → reject_reason = null
 *   - moderated_by/moderated_at заполняются при любом модераторском действии (кроме restore → pending).
 */
class DiaryComment extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    protected $fillable = [
        'diary_id',
        'user_id',
        'content',
        'parent_id',
        'status',
        'reject_reason',
        'moderated_by',
        'moderated_at',
        'likes_count',
        'replies_count',
    ];

    protected $casts = [
        'moderated_at' => 'datetime',
        'likes_count' => 'integer',
        'replies_count' => 'integer',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function likes(): HasMany
    {
        return $this->hasMany(DiaryCommentLike::class);
    }

    public function diary(): BelongsTo
    {
        return $this->belongsTo(Diary::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(DiaryComment::class, 'parent_id');
    }

    /** Дерево ответов, видимых пользователю (только approved). */
    public function replies(): HasMany
    {
        return $this->hasMany(DiaryComment::class, 'parent_id')->where('status', self::STATUS_APPROVED)->latest();
    }

    /** Все ответы (для админки и каскадов). */
    public function allReplies(): HasMany
    {
        return $this->hasMany(DiaryComment::class, 'parent_id')->latest();
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    // ============================================
    // МЕТОДЫ И ХЕЛПЕРЫ
    // ============================================
    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Одобрить комментарий. Очищает reject_reason, ставит модератора и timestamp.
     */
    public function approve(int $adminId): void
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'moderated_by' => $adminId,
            'moderated_at' => now(),
            'reject_reason' => null,
        ]);
    }

    /**
     * Отклонить комментарий с указанной причиной.
     * Причина должна быть из CommentRejectReason::userValues() для ручных действий,
     * либо CommentRejectReason::ParentRejected->value для каскада.
     */
    public function reject(int $adminId, string $reason): void
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'moderated_by' => $adminId,
            'moderated_at' => now(),
            'reject_reason' => $reason,
        ]);
    }

    /**
     * Сбросить в pending (используется при restore из карантина).
     * Полностью очищает модераторский след — комментарий снова «как новый».
     */
    public function resetToPending(): void
    {
        $this->update([
            'status' => self::STATUS_PENDING,
            'moderated_by' => null,
            'moderated_at' => null,
            'reject_reason' => null,
        ]);
    }
}
