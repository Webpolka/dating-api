<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSubscription extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_CANCELED = 'canceled';
    public const STATUS_FAILED = 'failed';

    public const TIER_PREMIUM = 'premium';
    public const TIER_VIP = 'vip';

    protected $fillable = [
        'user_id', 'plan_id', 'transaction_id', 'tier', 
        'starts_at', 'ends_at', 'is_auto_renew', 'provider_subscription_id', 
        'status', 'canceled_at', 'expires_notified_at'
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'canceled_at' => 'datetime',
        'expires_notified_at' => 'datetime',
        'is_auto_renew' => 'boolean',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function plan(): BelongsTo { return $this->belongsTo(SubscriptionPlan::class); }
    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class); }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopeActive($query) 
    { 
        return $query->where('status', self::STATUS_ACTIVE)->where('ends_at', '>', now()); 
    }

    public function scopeOverdue($query) 
    { 
        return $query->where('status', self::STATUS_ACTIVE)->where('ends_at', '<=', now()); 
    }

    public function scopeExpiringSoon($query, int $hours = 24)
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where('ends_at', '>', now())
            ->where('ends_at', '<=', now()->addHours($hours));
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================
    public function isActive(): bool 
    { 
        return $this->status === self::STATUS_ACTIVE && $this->ends_at->isFuture(); 
    }

    public function cancelAutoRenew(): bool 
    {
        if (!$this->is_auto_renew) return true;
        return $this->update(['is_auto_renew' => false, 'canceled_at' => now()]);
    }

    public function expire(): bool 
    {
        return $this->update(['status' => self::STATUS_EXPIRED, 'is_auto_renew' => false]);
    }

    // ============================================
    // АКСЕССОРЫ ДЛЯ UI
    // ============================================
    public function getStatusBadgeAttribute(): array
    {
        return match ($this->status) {
            self::STATUS_ACTIVE   => ['variant' => 'success', 'label' => 'Активна'],
            self::STATUS_EXPIRED  => ['variant' => 'secondary', 'label' => 'Истекла'],
            self::STATUS_CANCELED => ['variant' => 'warning', 'label' => 'Отменена'],
            self::STATUS_FAILED   => ['variant' => 'destructive', 'label' => 'Ошибка списания'],
            default               => ['variant' => 'secondary', 'label' => 'Неизвестно'],
        };
    }
}