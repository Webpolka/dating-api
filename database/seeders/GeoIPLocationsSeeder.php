<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
use App\Models\GeoIPLocation;
use App\Models\State;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class GeoIPLocationsSeeder extends Seeder
{
    private const BATCH_SIZE = 500;

    public function run(): void
    {
        $this->command->info('Заполнение geoip_locations (batch insert)...');

        // ✅ Очищаем таблицу полностью (быстро и чисто)
        $this->command->warn('Очищаем таблицу geoip_locations...');
        DB::statement('TRUNCATE TABLE geoip_locations RESTART IDENTITY CASCADE');

        $now = now();
        $defaultRow = [
            'is_registration_blocked' => false,
            'is_feed_blocked'         => false,
            'latitude'                => null,
            'longitude'               => null,
            'created_at'              => $now,
            'updated_at'              => $now,
        ];

        // Хардкод-перевод для городов, у которых нет ru-перевода в GeoNames из конфиг файла
        $manualRuNames = config('geo_ip_extra_translations.cities', []);

        // Заранее подготовим маппу ru-переводов по geoname_id
        $ruNames = DB::table('geo_translations')
            ->where('locale', 'ru')
            ->pluck('name', 'geoname_id')
            ->toArray();

        // ============================================
        // 1. СТРАНЫ
        // ============================================
        $this->command->info('Обрабатываем страны...');

        $countryBatch = [];
        $countryMap = []; // [countries.id => geoip_locations.id]
        $seenCountries = []; // для дедупликации по name
        $countries = Country::orderBy('name')->get();
        $countryBar = $this->command->getOutput()->createProgressBar($countries->count());
        $countryBar->start();
        $nextCountryId = 1;

        foreach ($countries as $country) {
            // Дедупликация по имени страны
            if (isset($seenCountries[$country->name])) {
                $countryBar->advance();
                continue;
            }
            $seenCountries[$country->name] = true;

            $nameRu = $country->geoname_id ? ($ruNames[$country->geoname_id] ?? null) : null;

            $countryBatch[] = array_merge($defaultRow, [
                'type'      => GeoIPLocation::TYPE_COUNTRY,
                'parent_id' => null,
                'name'      => $country->name,
                'name_ru'   => $nameRu ?? $country->name,
                'iso_code'  => $country->iso2,
            ]);

            // Временно сохраняем порядковый номер (потом заменим на реальный ID)
            $countryMap[$country->id] = $nextCountryId;
            $nextCountryId++;

            if (count($countryBatch) >= self::BATCH_SIZE) {
                GeoIPLocation::insert($countryBatch);
                $countryBatch = [];
            }

            $countryBar->advance();
        }

        if (!empty($countryBatch)) {
            GeoIPLocation::insert($countryBatch);
        }

        $countryBar->finish();
        $this->command->line('');

        // Перезагружаем карту стран с реальными ID
        $this->command->info('Обновляем карту стран...');
        $allGeoCountries = GeoIPLocation::query()
            ->where('type', GeoIPLocation::TYPE_COUNTRY)
            ->whereNull('parent_id')
            ->pluck('id', 'name')
            ->toArray();

        $countryMap = [];
        foreach ($countries as $country) {
            if (isset($allGeoCountries[$country->name])) {
                $countryMap[$country->id] = $allGeoCountries[$country->name];
            }
        }

        // ============================================
        // 2. РЕГИОНЫ
        // ============================================
        $this->command->info('Обрабатываем регионы...');

        $stateBatch = [];
        $stateMap = []; // [states.id => geoip_locations.id]
        $seenStates = []; // дедупликация по "parent_id|name"
        $states = State::orderBy('name')->get();
        $stateBar = $this->command->getOutput()->createProgressBar($states->count());
        $stateBar->start();
        $skippedStates = 0;

        foreach ($states as $state) {
            $parentId = $countryMap[$state->country_id] ?? null;
            if (!$parentId) {
                $skippedStates++;
                $stateBar->advance();
                continue;
            }

            $key = "{$parentId}|{$state->name}";
            if (isset($seenStates[$key])) {
                $stateBar->advance();
                continue;
            }
            $seenStates[$key] = true;

            $nameRu = $state->geoname_id ? ($ruNames[$state->geoname_id] ?? null) : null;

            $stateBatch[] = array_merge($defaultRow, [
                'type'      => GeoIPLocation::TYPE_REGION,
                'parent_id' => $parentId,
                'name'      => $state->name,
                'name_ru'   => $nameRu ?? $state->name,
            ]);

            if (count($stateBatch) >= self::BATCH_SIZE) {
                GeoIPLocation::insert($stateBatch);
                $stateBatch = [];
            }

            $stateBar->advance();
        }

        if (!empty($stateBatch)) {
            GeoIPLocation::insert($stateBatch);
        }

        $stateBar->finish();
        $this->command->line('');

        if ($skippedStates > 0) {
            $this->command->warn("Пропущено регионов без родителя: {$skippedStates}");
        }

        // Перезагружаем карту регионов
        $this->command->info('Обновляем карту регионов...');
        $allGeoStates = GeoIPLocation::query()
            ->where('type', GeoIPLocation::TYPE_REGION)
            ->whereNotNull('parent_id')
            ->get()
            ->keyBy(fn($item) => "{$item->parent_id}|{$item->name}")
            ->map(fn($item) => $item->id)
            ->toArray();

        $stateMap = [];
        foreach ($states as $state) {
            $parentId = $countryMap[$state->country_id] ?? null;
            if (!$parentId) continue;
            $key = "{$parentId}|{$state->name}";
            if (isset($allGeoStates[$key])) {
                $stateMap[$state->id] = $allGeoStates[$key];
            }
        }

        // ============================================
        // 3. ГОРОДА
        // ============================================
        $this->command->info('Обрабатываем города...');

        $cityBatch = [];
        $seenCities = []; // отслеживаем дубли по "parent_id|name"
        $cities = City::orderBy('name')->get();
        $cityBar = $this->command->getOutput()->createProgressBar($cities->count());
        $cityBar->start();
        $skippedCities = 0;
        $duplicates = 0;

        foreach ($cities as $city) {
            $parentId = null;
            if ($city->state_id && isset($stateMap[$city->state_id])) {
                $parentId = $stateMap[$city->state_id];
            } elseif ($city->country_id && isset($countryMap[$city->country_id])) {
                $parentId = $countryMap[$city->country_id];
            }

            if (!$parentId) {
                $skippedCities++;
                $cityBar->advance();
                continue;
            }

            // ✅ 1. Ищем перевод в БД
            $nameRu = $city->geoname_id ? ($ruNames[$city->geoname_id] ?? null) : null;

            // ✅ 2. Если перевода нет — берём из хардкод-мапы
            if (!$nameRu) {
                $nameRu = $manualRuNames[$city->name] ?? null;
            }

            $displayName = $nameRu ?? $city->name;

            $key = "{$parentId}|{$city->name}";
            $cityName = $city->name;
            $cityNameRu = $displayName;

            // ✅ Если дубль — добавляем суффикс с координатами
            // (Сравниваем по оригинальному имени, чтобы дубли разных источников считались)
            if (isset($seenCities[$key])) {
                $coordsSuffix = sprintf("(%.4f, %.4f)", $city->latitude ?? 0, $city->longitude ?? 0);
                $cityName = $city->name . " " . $coordsSuffix;
                $cityNameRu = $displayName . " " . $coordsSuffix;
                $duplicates++;
            } else {
                $seenCities[$key] = true;
            }

            $cityBatch[] = array_merge($defaultRow, [
                'type'      => GeoIPLocation::TYPE_CITY,
                'parent_id' => $parentId,
                'name'      => $cityName,
                'name_ru'   => $cityNameRu,
                'latitude'  => $city->latitude,
                'longitude' => $city->longitude,
            ]);

            if (count($cityBatch) >= self::BATCH_SIZE) {
                GeoIPLocation::insert($cityBatch);
                $cityBatch = [];
            }

            $cityBar->advance();
        }

        if (!empty($cityBatch)) {
            GeoIPLocation::insert($cityBatch);
        }

        $cityBar->finish();
        $this->command->line('');

        if ($skippedCities > 0) {
            $this->command->warn("Пропущено городов без родителя: {$skippedCities}");
        }
        if ($duplicates > 0) {
            $this->command->info("Добавлено суффиксов координат для дублей: {$duplicates}");
        }

        // ============================================
        // ИТОГИ
        // ============================================
        $this->command->info('========================================');
        $this->command->info('Готово! geoip_locations заполнена.');
        $this->command->info('Всего записей: ' . GeoIPLocation::count());
        $this->command->info('Стран: ' . GeoIPLocation::where('type', 'country')->count());
        $this->command->info('Регионов: ' . GeoIPLocation::where('type', 'region')->count());
        $this->command->info('Городов: ' . GeoIPLocation::where('type', 'city')->count());
        $this->command->info('========================================');
    }
}
