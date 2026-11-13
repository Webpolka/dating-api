<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Verification extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const REASON_BLURRY = 'blurry';
    public const REASON_FAKE = 'fake';
    public const REASON_NO_CODE = 'no_code';
    public const REASON_MINOR = 'minor';
    public const REASON_OTHER = 'other';

    protected $fillable = [
        'user_id', 'photo_id', 'status', 'reject_reason', 'moderated_by', 'moderated_at',
    ];

    protected $casts = [
        'moderated_at' => 'datetime',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    // ============================================
    // ХЕЛПЕРЫ МОДЕРАЦИИ
    // ============================================
    public function markAsApproved(int $adminId): bool
    {
        $updated = $this->update([
            'status' => self::STATUS_APPROVED,
            'moderated_by' => $adminId,
            'moderated_at' => now(),
        ]);

        if ($updated) {
            $this->user()->update(['is_verified' => true]);
        }

        return $updated;
    }

    public function markAsRejected(int $adminId, string $reason): bool
    {
        return $this->update([
            'status' => self::STATUS_REJECTED,
            'moderated_by' => $adminId,
            'moderated_at' => now(),
            'reject_reason' => $reason,
        ]);
    }
}