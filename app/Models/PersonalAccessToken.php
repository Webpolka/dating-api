<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasFactory;

    protected $casts = [
        'abilities' => 'json',
        'last_used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function tokenable(): MorphTo
    {
        return $this->morphTo('tokenable');
    }

    // ============================================
    // СКОПЫ ДЛЯ КРОНА И АДМИНКИ
    // ============================================
    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<=', now());
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}