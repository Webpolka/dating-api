<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Модель комментария к фото.
 *
 * Стратегия модерации (Вариант A):
 *   - 3 статуса: pending / approved / rejected. Спам — это причина отклонения, не статус.
 *   - Виртуальный «karantine» — это soft-deleted (trashed) запись любого статуса.
 *   - Причина reject_reason ставится ТОЛЬКО при status=rejected (включая каскад parent_rejected).
 *   - pending/approved всегда имеют reject_reason=null.
 *
 * Инварианты (см. tests/Feature/PhotoCommentModerationTest.php):
 *   - status=rejected → reject_reason ∈ {profanity, insult, off_topic, spam, other, parent_rejected}
 *   - status ∈ {pending, approved} → reject_reason = null
 *   - moderated_by/moderated_at заполняются при любом модераторском действии (кроме restore → pending).
 */
class PhotoComment extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED];

    protected $fillable = [
        'photo_id',
        'user_id',
        'content',
        'status',
        'reject_reason',
        'moderated_by',
        'moderated_at',
        'parent_id',
        'likes_count',
        'reports_count',
        'replies_count',
        'is_pinned',
        'edited_at',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'likes_count' => 'integer',
        'reports_count' => 'integer',
        'replies_count' => 'integer',
        'moderated_at' => 'datetime',
        'edited_at' => 'datetime',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
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
        return $this->belongsTo(PhotoComment::class, 'parent_id');
    }

    /** Дерево ответов, видимых пользователю (только approved). */
    public function replies(): HasMany
    {
        return $this->hasMany(PhotoComment::class, 'parent_id')->where('status', self::STATUS_APPROVED);
    }

    /** Все ответы (для админки и каскадов). */
    public function allReplies(): HasMany
    {
        return $this->hasMany(PhotoComment::class, 'parent_id');
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

    public function getStatusBadgeAttribute(): array
    {
        return match ($this->status) {
            self::STATUS_PENDING  => ['variant' => 'warning', 'label' => 'Ожидает'],
            self::STATUS_APPROVED => ['variant' => 'success', 'label' => 'Одобрен'],
            self::STATUS_REJECTED => ['variant' => 'destructive', 'label' => 'Отклонен'],
            default               => ['variant' => 'secondary', 'label' => 'Неизвестно'],
        };
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
