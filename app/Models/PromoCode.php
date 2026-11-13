<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class PromoCode extends Model
{
    use HasFactory;

    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';
    public const TYPE_PREMIUM = 'premium';
    public const TYPE_VIP = 'vip';

    protected $fillable = [
        'code', 'discount_type', 'discount_value', 'duration_days',
        'max_uses', 'used_count', 'expires_at', 'user_id', 'is_active',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'duration_days' => 'integer',
        'max_uses' => 'integer',
        'used_count' => 'integer',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function usages(): HasMany
    {
        return $this->hasMany(PromoCodeUsage::class);
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ============================================
    // БИЗНЕС-ЛОГИКА
    // ============================================
    public function isValidForUser(User $user): bool
    {
        if (!$this->is_active) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        if ($this->max_uses && $this->used_count >= $this->max_uses) return false;
        if ($this->user_id && $this->user_id !== $user->id) return false;
        
        return !$this->usages()->where('user_id', $user->id)->exists();
    }

    public function calculateDiscount(float $originalAmount): float
    {
        if ($this->discount_type === self::TYPE_PERCENT) {
            return round($originalAmount * ($this->discount_value / 100), 2);
        }
        
        if ($this->discount_type === self::TYPE_FIXED) {
            return min($originalAmount, (float) $this->discount_value);
        }

        return 0; // Для premium/vip скидка на сумму не считается, они дают дни
    }

    public function apply(User $user, ?Transaction $transaction = null): ?PromoCodeUsage
    {
        if (!$this->isValidForUser($user)) {
            return null;
        }

        try {
            return DB::transaction(function () use ($user, $transaction) {
                $updated = $this->newQuery()
                    ->where('id', $this->id)
                    ->when($this->max_uses, fn($q) => $q->where('used_count', '<', $this->max_uses))
                    ->increment('used_count');

                if (!$updated) {
                    throw new \Exception("Промокод исчерпал лимит использований.");
                }

                $this->used_count++; // Обновляем в памяти

                $appliedDiscount = 0;
                if ($transaction) {
                    $appliedDiscount = $this->calculateDiscount((float) $transaction->amount);
                }

                return PromoCodeUsage::create([
                    'promo_code_id' => $this->id,
                    'user_id' => $user->id,
                    'transaction_id' => $transaction?->id,
                    'applied_discount' => $appliedDiscount,
                ]);
            });
        } catch (\Exception $e) {
            return null;
        }
    }
}