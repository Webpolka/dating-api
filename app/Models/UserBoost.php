<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserBoost extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
   
    protected $fillable = [
        'user_id', 'transaction_id', 'photo_id', 'type', 'starts_at', 'ends_at', 'status'
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function transaction(): BelongsTo { return $this->belongsTo(Transaction::class); }
    public function photo(): BelongsTo { return $this->belongsTo(Photo::class); }

    // ============================================
    // СКОПЫ И ХЕЛПЕРЫ
    // ============================================
    public function scopeActive($query) 
    { 
        return $query->where('status', self::STATUS_ACTIVE)->where('ends_at', '>', now()); 
    }

    public function scopeOverdue($query) 
    { 
        return $query->where('status', self::STATUS_ACTIVE)->where('ends_at', '<=', now()); 
    }

    public function isActive(): bool 
    { 
        return $this->status === self::STATUS_ACTIVE && $this->ends_at->isFuture(); 
    }
    
    public function expire(): bool 
    { 
        return $this->update(['status' => self::STATUS_EXPIRED]); 
    }
}