<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Report extends Model
{
    use HasFactory, SoftDeletes; 

    public const STATUS_PENDING = 'pending';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_REJECTED = 'rejected';

    public const RESOLUTION_BAN = 'ban';
    public const RESOLUTION_WARN = 'warn';
    public const RESOLUTION_SHADOWBAN = 'shadowban';
    public const RESOLUTION_NO_ACTION = 'no_action';

    public const REASON_SPAM = 'spam';
    public const REASON_PORN = 'porn';
    public const REASON_SCAM = 'scam';
    public const REASON_INSULT = 'insult';
    public const REASON_MINOR = 'minor';
    public const REASON_OTHER = 'other';

    protected $fillable = [
        'reporter_id', 'reported_id', 'reportable_type', 'reportable_id',
        'reason', 'description', 'status', 'resolution', 'resolution_note',
        'admin_id', 'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reported(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_id')->withTrashed();
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function reportable(): MorphTo
    {
        return $this->morphTo();
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeResolved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_RESOLVED);
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REJECTED);
    }

    public function scopeForType(Builder $query, string $type): Builder
    {
        return $query->where('reportable_type', $type);
    }

    // ============================================
    // ХЕЛПЕРЫ БИЗНЕС-ЛОГИКИ
    // ============================================
    public function resolve(int $adminId, string $resolution, ?string $note = null): bool
    {
        $status = ($resolution === self::RESOLUTION_NO_ACTION) ? self::STATUS_REJECTED : self::STATUS_RESOLVED;

        return $this->update([
            'status' => $status,
            'resolution' => $resolution,
            'resolution_note' => $note,
            'admin_id' => $adminId,
            'resolved_at' => now(),
        ]);
    }

    public function reopen(): bool
    {
        return $this->update([
            'status' => self::STATUS_PENDING,
            'resolution' => null,
            'resolution_note' => null,
            'admin_id' => null,
            'resolved_at' => null,
        ]);
    }

    public function getStatusBadgeAttribute(): array
    {
        return match ($this->status) {
            self::STATUS_PENDING  => ['variant' => 'warning', 'label' => 'Ожидает'],
            self::STATUS_RESOLVED => ['variant' => 'success', 'label' => 'Разобрано'],
            self::STATUS_REJECTED => ['variant' => 'secondary', 'label' => 'Отклонено'],
            default               => ['variant' => 'secondary', 'label' => 'Неизвестно'],
        };
    }
}