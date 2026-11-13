<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class City extends Model
{
    use HasFactory;

    protected $table = 'cities';

    protected $fillable = ['name', 'country_id', 'state_id', 'timezone'];
    
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class, 'state_id');
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