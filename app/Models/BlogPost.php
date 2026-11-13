<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BlogPost extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'user_id', 'cover_media_id', 'title', 'slug', 'excerpt', 'body',
        'category_id', 'status', 'is_featured', 'views_count',
    ];

    protected $casts = [
        'is_featured' => 'boolean',
        'views_count' => 'integer',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeOfCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where('category_id', $categoryId);
    }

    // ============================================
    // ХЕЛПЕРЫ БИЗНЕС-ЛОГИКИ
    // ============================================
    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function publish(): bool
    {
        return $this->update(['status' => self::STATUS_PUBLISHED]);
    }

    public function unpublish(): bool
    {
        return $this->update(['status' => self::STATUS_DRAFT]);
    }

    public function incrementViews(): void
    {
        $this->newQuery()->where('id', $this->id)->increment('views_count');
        $this->views_count++;
    }

    // ============================================
    // АКСЕССОРЫ ДЛЯ UI
    // ============================================
    public function getCoverUrlAttribute(): string
    {
        return $this->cover?->getVariantUrl('lg') ?? asset('images/default-blog-cover.jpg');
    }

    public function getOgImageUrlAttribute(): string
    {
        return $this->cover?->getVariantUrl('og') ?? $this->cover_url;
    }

    public function getStatusBadgeAttribute(): array
    {
        return match ($this->status) {
            self::STATUS_DRAFT     => ['variant' => 'warning', 'label' => 'Черновик'],
            self::STATUS_PUBLISHED => ['variant' => 'success', 'label' => 'Опубликована'],
            self::STATUS_ARCHIVED  => ['variant' => 'secondary', 'label' => 'В архиве'],
            default                => ['variant' => 'secondary', 'label' => 'Неизвестно'],
        };
    }
}