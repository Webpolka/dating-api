<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoIPLocation extends Model
{
    use HasFactory;

    public const TYPE_COUNTRY = 'country';
    public const TYPE_REGION = 'region';
    public const TYPE_CITY = 'city';

    protected $table = 'geoip_locations';

    protected $fillable = [
        'parent_id',
        'type',
        'name',
        'iso_code',
        'latitude',
        'longitude',
        'is_registration_blocked',
        'is_feed_blocked',
    ];

    protected $casts = [
        'is_registration_blocked' => 'boolean',
        'is_feed_blocked' => 'boolean',
    ];

    // ============================================
    // СВЯЗИ (Дерево)
    // ============================================
    public function parent(): BelongsTo
    {
        return $this->belongsTo(GeoIPLocation::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(GeoIPLocation::class, 'parent_id');
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopeCountries(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_COUNTRY);
    }

    public function scopeRegistrationBlocked(Builder $query): Builder
    {
        return $query->where('is_registration_blocked', true);
    }

    public function scopeFeedBlocked(Builder $query): Builder
    {
        return $query->where('is_feed_blocked', true);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================
    public function isCountry(): bool
    {
        return $this->type === self::TYPE_COUNTRY;
    }

    public function isRegion(): bool
    {
        return $this->type === self::TYPE_REGION;
    }

    public function isCity(): bool
    {
        return $this->type === self::TYPE_CITY;
    }
}
