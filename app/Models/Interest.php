<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Cocur\Slugify\Slugify;

/**
 * ============================================================
 * Interest — интерес (простой справочник, без модерации)
 * ============================================================
 *
 * Все интересы заранее предзаданы админом/сидером.
 * Юзер просто выбирает из списка. Никаких pending/rejected.
 *
 * Counter cache:
 *   users_count — обновляется триггером user_interests_update_count.
 *   В коде инкрементировать/decrementировать НЕ нужно.
 *
 * Relations:
 *   category() — BelongsTo InterestCategory
 *   users()    — BelongsToMany через user_interests
 *
 * Scopes:
 *   popular()   — топ по users_count (для UI "популярные")
 *   boosted()   — топ по search_boost (админ поднял)
 *   inCategory() — фильтр по категории
 */
class Interest extends Model
{
    protected $table = 'interests';

    protected $fillable = [
        'name',
        'slug',
        'category_id',
        'search_boost',
        // users_count НЕ в fillable — обновляется только триггером
    ];

    protected $casts = [
        'category_id'  => 'integer',
        'users_count'  => 'integer',
        'search_boost' => 'integer',
    ];

    /**
     * Boot — автогенерация slug из name при создании.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Interest $interest) {
            if (empty($interest->slug)) {
                $slugify = new Slugify(['rulesets' => ['default', 'russian']]);
                $interest->slug = $slugify->slugify($interest->name);
            }
        });
    }

    // ============================================================
    // Relations
    // ============================================================

    /**
     * Категория интереса (может быть null — "Без категории").
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(InterestCategory::class, 'category_id');
    }

    /**
     * Юзеры, у которых выбран этот интерес.
     * Через pivot user_interests.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_interests')
            ->withPivot('created_at')
            ->withTimestamps(false);
    }

    // ============================================================
    // Scopes
    // ============================================================

    /**
     * Популярные интересы (по users_count).
     *
     * ИСПОЛЬЗОВАНИЕ:
     *   Interest::popular(20)->get();
     */
    public function scopePopular(Builder $query, int $limit = 50): Builder
    {
        return $query->orderByDesc('users_count')->limit($limit);
    }

    /**
     * Интересы с boost (админ поднял в выдаче).
     */
    public function scopeBoosted(Builder $query): Builder
    {
        return $query->where('search_boost', '>', 0)
            ->orderByDesc('search_boost')
            ->orderByDesc('users_count');
    }

    /**
     * Интересы в конкретной категории.
     */
    public function scopeInCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where('category_id', $categoryId)->orderBy('name');
    }

    // ============================================================
    // Helper methods
    // ============================================================

    /**
     * Повысить search_boost (админ поднимает интерес в выдаче).
     */
    public function boost(int $value): void
    {
        $this->update(['search_boost' => max(0, $value)]);
    }

    /**
     * Найти или создать интерес по имени.
     * (Полезно для админки/сидера)
     *
     * citext делает сравнение case-insensitive.
     */
    public static function findOrCreate(string $name, ?int $categoryId = null): self
    {
        $name = trim($name);
        if (empty($name)) {
            throw new \InvalidArgumentException('Interest name cannot be empty');
        }

        $existing = self::where('name', $name)->first();
        if ($existing) {
            return $existing;
        }

        return self::create([
            'name'        => $name,
            'category_id' => $categoryId,
        ]);
    }

    /**
     * Пересчитать users_count для всех интересов (бэкфилл).
     * Вызывается из artisan-команды при накате на прод.
     */
    public static function rebuildUsersCount(): void
    {
        \Illuminate\Support\Facades\DB::statement("
            UPDATE interests i
            SET users_count = COALESCE(
                (SELECT COUNT(*) FROM user_interests ui WHERE ui.interest_id = i.id),
                0
            )
        ");
    }
}