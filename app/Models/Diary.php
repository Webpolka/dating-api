<?php

namespace App\Models;

use App\Enums\V1\DiaryStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property DiaryStatus $status
 */
class Diary extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'user_id',
        'diary_rubric_id',
        'title',
        'body',
        'status',
        'reject_reason',
        'moderated_by',
        'moderated_at',
        'published_at',
        'is_comments_enabled',
        'is_quote_enabled',
        'views_count',
        'comments_count',
        'likes_count',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'moderated_at' => 'datetime',
        'is_comments_enabled' => 'boolean',
        'is_quote_enabled' => 'boolean',
        'views_count' => 'integer',
        'comments_count' => 'integer',
        'likes_count' => 'integer',
        'status' => DiaryStatus::class,
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function diaryRubric(): BelongsTo
    {
        return $this->belongsTo(DiaryRubric::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(DiaryComment::class);
    }

    public function approvedComments(): HasMany
    {
        return $this->hasMany(DiaryComment::class)->root()->approved()->latest();
    }

    public function likes(): HasMany
    {
        return $this->hasMany(DiaryLike::class);
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopePublished($query)
    {
        return $query->where('status', DiaryStatus::Published);
    }

    public function scopePending($query)
    {
        return $query->where('status', DiaryStatus::Pending);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', DiaryStatus::Rejected);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================
    /**
     * Сбросить в pending (используется при restore из карантина).
     * Полностью очищает модераторский след — пост снова «как новый».
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

    public function incrementViews(): void
    {
        $this->newQuery()->where('id', $this->id)->increment('views_count');
        $this->views_count++;
    }

    public function incrementLikes(): void
    {
        $this->newQuery()->where('id', $this->id)->increment('likes_count');
        $this->likes_count++;
    }

    public function decrementLikes(): void
    {
        $this->newQuery()->where('id', $this->id)->where('likes_count', '>', 0)->decrement('likes_count');
        if ($this->likes_count > 0) {
            $this->likes_count--;
        }
    }
}
