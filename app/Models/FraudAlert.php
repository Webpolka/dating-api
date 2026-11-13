<?php

namespace App\Models;

use App\Enums\FraudAlertSeverity;
use App\Enums\FraudAlertStatus;
use App\Enums\FraudTriggerType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraudAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'trigger_type', 'severity', 'meta', 'status', 'admin_id', 'resolved_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'resolved_at' => 'datetime',
        'status' => FraudAlertStatus::class,
        'severity' => FraudAlertSeverity::class,
        'trigger_type' => FraudTriggerType::class, 
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopeOpen(Builder $query): Builder { return $query->where('status', FraudAlertStatus::Open); }
    public function scopeResolved(Builder $query): Builder { return $query->where('status', FraudAlertStatus::Resolved); }
    public function scopeFalsePositive(Builder $query): Builder { return $query->where('status', FraudAlertStatus::FalsePositive); }
    public function scopeHighSeverity(Builder $query): Builder { return $query->where('severity', FraudAlertSeverity::High); }
    
    public function scopeOfTrigger(Builder $query, FraudTriggerType $type): Builder 
    { 
        return $query->where('trigger_type', $type); 
    }

    // ============================================
    // ХЕЛПЕРЫ БИЗНЕС-ЛОГИКИ
    // ============================================
    public function isOpen(): bool
    {
        return $this->status === FraudAlertStatus::Open;
    }

    public function resolve(int $adminId): bool
    {
        if (!$this->isOpen()) return true;
        return $this->update([
            'status' => FraudAlertStatus::Resolved,
            'admin_id' => $adminId,
            'resolved_at' => now(),
        ]);
    }

    public function markAsFalsePositive(int $adminId): bool
    {
        if (!$this->isOpen()) return true;
        return $this->update([
            'status' => FraudAlertStatus::FalsePositive,
            'admin_id' => $adminId,
            'resolved_at' => now(),
        ]);
    }

    // ============================================
    // АКСЕССОРЫ ДЛЯ UI
    // ============================================
    public function getTriggerLabelAttribute(): string
    {
        if ($this->trigger_type instanceof FraudTriggerType) {
            return $this->trigger_type->label();
        }
        $raw = $this->getRawOriginal('trigger_type');
        return is_string($raw) ? ucfirst(str_replace('_', ' ', $raw)) : 'Unknown';
    }

    public function getStatusBadgeAttribute(): array
    {
        return [
            'variant' => $this->status->badgeVariant(),
            'label' => $this->status->label()
        ];
    }

    public function getSeverityBadgeAttribute(): array
    {
        return [
            'variant' => $this->severity->badgeVariant(),
            'label' => $this->severity->label()
        ];
    }
}