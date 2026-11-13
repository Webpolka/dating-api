<?php

namespace App\Models;

use App\Enums\StopWordAction;
use App\Enums\StopWordCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;

class StopWord extends Model
{
    use HasFactory;

    protected $fillable = [
        'word', 'category', 'action', 'replacement', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'category' => StopWordCategory::class,
        'action' => StopWordAction::class,
    ];

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfCategory(Builder $query, StopWordCategory $category): Builder
    {
        return $query->where('category', $category);
    }

    public function scopeOfAction(Builder $query, StopWordAction $action): Builder
    {
        return $query->where('action', $action);
    }

    // ============================================
    // КЭШИРОВАНИЕ (HIGH-LOAD)
    // ============================================

    /**
     * Получить закэшированный список активных стоп-слов.
     *
     * При падении Redis — fallback на прямой запрос к БД.
     * Сайт продолжает работать, просто без кэширования (медленнее).
     */
    public static function getCachedActiveWords(): Collection
    {
        try {
            return Cache::rememberForever('stop_words_active', function () {
                return self::active()->get();
            });
        } catch (\Throwable $e) {
            Log::warning('StopWord: cache failed, fetching from DB', [
                'error' => $e->getMessage(),
            ]);

            return self::active()->get();
        }
    }

    /**
     * Сбросить кэш стоп-слов.
     * При падении Redis — silent (лог warning).
     */
    public static function clearCache(): void
    {
        try {
            Cache::forget('stop_words_active');
        } catch (\Throwable $e) {
            Log::warning('StopWord: cache forget failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ============================================
    // ХЕЛПЕРЫ ТИПОВ ДЕЙСТВИЙ
    // ============================================
    public function isMask(): bool
    {
        return $this->action === StopWordAction::Mask;
    }

    public function isReject(): bool
    {
        return $this->action === StopWordAction::Reject;
    }

    public function isAlert(): bool
    {
        return $this->action === StopWordAction::Alert;
    }

    // ============================================
    // АКСЕССОРЫ ДЛЯ UI
    // ============================================
    public function getActionBadgeAttribute(): array
    {
        return match ($this->action) {
            StopWordAction::Mask   => ['variant' => 'secondary', 'label' => StopWordAction::Mask->label()],
            StopWordAction::Reject => ['variant' => 'destructive', 'label' => StopWordAction::Reject->label()],
            StopWordAction::Alert  => ['variant' => 'warning', 'label' => StopWordAction::Alert->label()],
            default                => ['variant' => 'secondary', 'label' => 'Неизвестно'],
        };
    }
}