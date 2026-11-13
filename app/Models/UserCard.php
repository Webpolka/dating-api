<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class UserCard extends Model
{
    use HasFactory, SoftDeletes;

    public const GATEWAY_YOOKASSA = 'yookassa';
    public const GATEWAY_STRIPE = 'stripe';

    protected $fillable = [
        'user_id', 'gateway', 'payment_method_id', 
        'card_type', 'last4', 'expiry_month', 'expiry_year',
        'is_active', 'is_default'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ============================================
    // ХЕЛПЕРЫ БИЗНЕС-ЛОГИКИ
    // ============================================
    public function makeDefault(): bool
    {
        return DB::transaction(function () {
            static::where('user_id', $this->user_id)
                ->where('id', '!=', $this->id)
                ->update(['is_default' => false]);

            return $this->update(['is_default' => true]);
        });
    }

    public function isExpired(): bool
    {
        if (!$this->expiry_year || !$this->expiry_month) {
            return false;
        }

        $expiryDate = Carbon::createFromDate($this->expiry_year, $this->expiry_month, 1)->endOfMonth();
        return $expiryDate->isPast();
    }
}