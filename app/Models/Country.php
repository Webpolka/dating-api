<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    use HasFactory;

    protected $table = 'countries';

    protected $fillable = ['name', 'iso2', 'iso3', 'phone_code'];

    public function cities(): HasMany
    {
        return $this->hasMany(City::class);
    }

    public function states(): HasMany
    {
        return $this->hasMany(State::class);
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
