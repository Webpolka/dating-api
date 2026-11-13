<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class GeoTranslation extends Model
{
    use HasFactory;

    protected $fillable = ['geoname_id', 'locale', 'name'];
}