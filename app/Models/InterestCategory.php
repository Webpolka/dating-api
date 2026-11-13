<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Cocur\Slugify\Slugify;

/**
 * ============================================================
 * InterestCategory — категория интересов (Хобби, Спорт, Музыка, ...)
 * ============================================================
 *
 * Используется ТОЛЬКО как UI-группировка для интересов.
 * Сама по себе категория не выбирается юзером как интерес.
 *
 * Counter cache:
 *   interests_count — обновляется триггером update_interest_category_count.
 *   В коде инкрементировать/decrementировать НЕ нужно.
 *
 * Relations:
 *   interests() — HasMany (все интересы в категории)
 *
 * Scopes:
 *   ordered() — сортировка по sort_order + name
 *   popular() — топ по interests_count (для админки/виджетов)
 */
class InterestCategory extends Model
{
    protected $table = 'interest_categories';

    protected $fillable = [
        'name',
        'slug',
        'icon',
        'sort_order',
        // interests_count НЕ в fillable — обновляется только триггером
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'interests_count' => 'integer',
    ];

    /**
     * Boot — автогенерация slug из name через cocur/slugify.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (InterestCategory $category) {
            if (empty($category->slug)) {
                $slugify = new Slugify(['rulesets' => ['default', 'russian']]);
                $category->slug = $slugify->slugify($category->name);
            }
        });

        // При обновлении если меняется name и slug пустой — пересоздаём
        static::updating(function (InterestCategory $category) {
            if ($category->isDirty('name') && empty($category->slug)) {
                $slugify = new Slugify(['rulesets' => ['default', 'russian']]);
                $category->slug = $slugify->slugify($category->name);
            }
        });
    }

    // ============================================================
    // Relations
    // ============================================================

    /**
     * ВСЕ интересы в этой категории (для админки).
     */
    public function interests(): HasMany
    {
        return $this->hasMany(Interest::class, 'category_id')
            ->orderBy('name');
    }

    // ============================================================
    // Scopes
    // ============================================================

    /**
     * Сортировка по sort_order (для UI).
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Топ по популярности (по числу интересов).
     */
    public function scopePopular(Builder $query, int $limit = 10): Builder
    {
        return $query->orderByDesc('interests_count')->limit($limit);
    }

    /**
     * Только категории с хотя бы одним интересом.
     */
    public function scopeWithInterests(Builder $query): Builder
    {
        return $query->where('interests_count', '>', 0);
    }

    // ============================================================
    // Helper methods
    // ============================================================

    /**
     * Пересчитать interests_count для всех категорий (бэкфилл).
     */
    public static function rebuildAllInterestsCounts(): void
    {
        DB::statement("
            UPDATE interest_categories cat
            SET interests_count = COALESCE(
                (SELECT COUNT(*) FROM interests i 
                 WHERE i.category_id = cat.id),
                0
            )
        ");
    }
}