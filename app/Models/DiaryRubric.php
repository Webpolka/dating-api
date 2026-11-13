<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DiaryRubric extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];



    // ============================================
    // СВЯЗИ
    // ============================================
    public function diaries(): HasMany
    {
        return $this->hasMany(Diary::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    public static function findBySlug(string $slug): ?self
    {
        return static::where('slug', $slug)->first();
    }

    /**
     * Доступна ли рубрика для поста?
     *
     * Рубрика доступна если:
     *  - она системная (user_id IS NULL) — общие рубрики доступны всем,
     *  - ИЛИ она личная автора поста (user_id = diary.user_id).
     *
     * Личные рубрики ДРУГИХ юзеров недоступны — админ не может привязать
     * пост Васи к личной рубрике Пети.
     */
    public static function isAccessibleFor(?int $rubricId, ?int $diaryAuthorId): bool
    {
        if ($rubricId === null) {
            return true;
        }

        return static::where('id', $rubricId)
            ->where(function ($q) use ($diaryAuthorId) {
                $q->whereNull('user_id')->orWhere('user_id', $diaryAuthorId);
            })
            ->exists();
    }
}
