<?php

namespace App\Http\Controllers\Api\V1\Web;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Country;
use Illuminate\Http\Request;

class GeoController extends Controller
{
        public function search(Request $request)
    {
        $searchTerm = $request->get('q', '');
        
        if (mb_strlen($searchTerm) < 2) {
            return response()->json([]);
        }

        $results = [];

        // Ищем города, джоиня таблицу переводов (locale = ru)
        $cities = City::select('cities.*', 'gt.name as translated_name')
            ->leftJoin('geo_translations as gt', function($join) {
                $join->on('cities.geoname_id', '=', 'gt.geoname_id')
                     ->where('gt.locale', 'ru');
            })
            ->where(function($query) use ($searchTerm) {
                $query->whereRaw('LOWER(cities.name) LIKE LOWER(?)', ["%{$searchTerm}%"])
                      ->orWhereRaw('LOWER(gt.name) LIKE LOWER(?)', ["%{$searchTerm}%"]);
            })
            ->with('state:id,name')
            ->limit(10)
            ->get();

        foreach ($cities as $city) {
            // Если есть русский перевод, берем его, иначе базовое имя
            $displayName = $city->translated_name ?: $city->name;
            
            $results[] = [
                'id' => $city->id,
                'type' => 'city',
                'name' => $displayName,
                'region' => $city->state?->name,
                // Добавляем "г. " перед названием города
                'label' => 'г. ' . $displayName . ($city->state ? ' (' . $city->state->name . ')' : ''),
            ];
        }

        // Ищем страны так же
        $countries = Country::select('countries.*', 'gt.name as translated_name')
            ->leftJoin('geo_translations as gt', function($join) {
                $join->on('countries.geoname_id', '=', 'gt.geoname_id')
                     ->where('gt.locale', 'ru');
            })
            ->where(function($query) use ($searchTerm) {
                $query->whereRaw('LOWER(countries.name) LIKE LOWER(?)', ["%{$searchTerm}%"])
                      ->orWhereRaw('LOWER(gt.name) LIKE LOWER(?)', ["%{$searchTerm}%"]);
            })
            ->limit(5)
            ->get();

        foreach ($countries as $country) {
            $displayName = $country->translated_name ?: $country->name;
            
            $results[] = [
                'id' => $country->id,
                'type' => 'country',
                'name' => $displayName,
                'region' => null,
                'label' => $displayName . ' (Вся страна)',
            ];
        }

        return response()->json($results);
    }
}