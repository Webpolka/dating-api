<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'blocker_id',
        'blocked_id',
        'reason',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function blocker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocker_id');
    }

    public function blocked(): BelongsTo
    {
        return $this->belongsTo(User::class, 'blocked_id');
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================
    public static function isBlocked(int $blockerId, int $blockedId): bool
    {
        return static::where('blocker_id', $blockerId)
            ->where('blocked_id', $blockedId)
            ->exists();
    }

    public static function isEitherBlocked(int $userA, int $userB): bool
    {
        return static::where(function ($query) use ($userA, $userB) {
            $query->where('blocker_id', $userA)->where('blocked_id', $userB);
        })->orWhere(function ($query) use ($userA, $userB) {
            $query->where('blocker_id', $userB)->where('blocked_id', $userA);
        })->exists();
    }
}
