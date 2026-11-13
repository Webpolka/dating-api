<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Swipe extends Model
{
    use HasFactory;

    public const TYPE_LIKE = 'like';
    public const TYPE_DISLIKE = 'dislike';
    public const TYPE_SUPERLIKE = 'superlike';

    protected $fillable = [
        'user_id',
        'target_user_id',
        'type',
        'rewinded_at',
    ];

    protected $casts = [
        'rewinded_at' => 'datetime',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopePositive(Builder $query): Builder
    {
        return $query->whereIn('type', [self::TYPE_LIKE, self::TYPE_SUPERLIKE]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('rewinded_at');
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================
    public function isPositive(): bool
    {
        return in_array($this->type, [self::TYPE_LIKE, self::TYPE_SUPERLIKE]);
    }

    public function rewind(): bool
    {
        if ($this->rewinded_at) return true;

        $updated = $this->newQuery()
            ->where('id', $this->id)
            ->whereNull('rewinded_at')
            ->update(['rewinded_at' => now()]);

        if ($updated) $this->rewinded_at = now();

        return (bool)$updated;
    }
}
