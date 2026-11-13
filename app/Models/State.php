<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class State extends Model
{
    use HasFactory;

    protected $table = 'states';

    protected $fillable = ['name', 'country_id', 'state_code'];
    
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }
    // Добавь в app/models/Country.php, State.php, City.php
    public function translations()
    {
        return $this->hasMany(\App\Models\GeoTranslation::class, 'geoname_id', 'geoname_id');
    }

    // Хелпер для получения перевода
    public function getLocalizedName(string $locale = 'ru'): string
    {
        $translation = $this->translations->where('locale', $locale)->first();
        return $translation ? $translation->name : $this->name;
    }
}