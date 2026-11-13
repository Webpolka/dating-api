<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiarySubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscriber_id', 'author_id',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subscriber_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================
    public static function isSubscribed(int $subscriberId, int $authorId): bool
    {
        return static::where('subscriber_id', $subscriberId)
            ->where('author_id', $authorId)
            ->exists();
    }

    public static function subscribe(int $subscriberId, int $authorId): bool
    {
        return static::firstOrCreate([
            'subscriber_id' => $subscriberId,
            'author_id' => $authorId,
        ])->wasRecentlyCreated;
    }

    public static function unsubscribe(int $subscriberId, int $authorId): void
    {
        static::where('subscriber_id', $subscriberId)
            ->where('author_id', $authorId)
            ->delete();
    }
}