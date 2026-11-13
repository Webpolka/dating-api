<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use ZipArchive;

class ImportGeoData extends Command
{
    protected $signature = 'geo:import';
    protected $description = 'Импорт стран, регионов РФ, городов РФ и русских переводов из GeoNames';

    public function handle(): void
    {
        $this->info('🌍 Начинаем импорт гео-данных...');

        // 1. Отвязываем профили
        $this->info('🗑️ Очистка старых гео-данных...');
        DB::table('user_profiles')->update([
            'city_id' => null,
            'country_id' => null,
        ]);

        // 2. Удаляем старые данные
        DB::table('cities')->delete();
        DB::table('states')->delete();
        DB::table('countries')->delete();

        // ============================================
        // 1. ИМПОРТ СТРАН
        // ============================================
        $this->info('🌎 Загружаем страны...');
        $data = file_get_contents('http://download.geonames.org/export/dump/countryInfo.txt');
        $lines = explode("\n", $data);
        $countryData = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, '#') || empty(trim($line))) continue;
            $row = explode("\t", $line);

            $countryData[] = [
                'name' => $row[4],
                'iso2' => $row[0],
                'iso3' => $row[1],
                'phone_code' => $row[12] ?? null,
                'geoname_id' => $row[16] ?? null,
                'created_at' => now(),
                'updated_at' => now()
            ];

            if (count($countryData) >= 100) {
                DB::table('countries')->insert($countryData);
                $countryData = [];
            }
        }
        if (!empty($countryData)) DB::table('countries')->insert($countryData);
        $this->info("   ✅ Стран импортировано: " . DB::table('countries')->count());

        // ============================================
        // 2. ИМПОРТ РЕГИОНОВ РОССИИ
        // ============================================
        $this->info('🗺️ Загружаем регионы России...');
        $url = 'http://download.geonames.org/export/dump/admin1CodesASCII.txt';
        $data = file_get_contents($url);

        if (empty($data)) {
            $this->error('❌ Не удалось скачать файл регионов!');
            return;
        }

        $lines = explode("\n", $data);
        $russia = DB::table('countries')->where('iso2', 'RU')->first();
        $stateData = [];

        foreach ($lines as $line) {
            $line = trim($line); // Чистим \r и пробелы
            if (empty($line)) continue;

            $row = explode("\t", $line);
            if (!isset($row[0]) || !str_starts_with($row[0], 'RU.')) continue;

            $stateCode = str_replace('RU.', '', $row[0]);
            $stateData[] = [
                'country_id' => $russia->id,
                'name' => $row[1] ?? 'Без названия',
                'state_code' => $stateCode,
                'geoname_id' => $row[3] ?? null,
                'created_at' => now(),
                'updated_at' => now()
            ];
        }

        if (!empty($stateData)) {
            DB::table('states')->insert($stateData);
        }
        $this->info("   ✅ Регионов РФ импортировано: " . DB::table('states')->count());

        // ============================================
        // 3. ИМПОРТ ГОРОДОВ РОССИИ
        // ============================================
        $this->info('🏙️ Загружаем города России...');
        $url = 'http://download.geonames.org/export/dump/cities5000.zip';
        $tempZip = storage_path('app/cities5000.zip');
        file_put_contents($tempZip, file_get_contents($url));

        $zip = new ZipArchive;
        if ($zip->open($tempZip) === true) {
            $zip->extractTo(storage_path('app/'));
            $zip->close();
        }

        $filePath = storage_path('app/cities5000.txt');
        $handle = fopen($filePath, 'r');
        $cityData = [];

        $stateMap = DB::table('states')->where('country_id', $russia->id)->pluck('id', 'state_code')->toArray();

        while (($line = fgets($handle)) !== false) {
            $row = explode("\t", $line);
            if (!isset($row[8]) || $row[8] !== 'RU') continue;

            $stateCode = $row[10] ?? null;
            $stateId = $stateMap[$stateCode] ?? null;

            $cityData[] = [
                'country_id' => $russia->id,
                'state_id' => $stateId,
                'name' => $row[1], // Временно сохраняем латиницу (позже заменим на русский)
                'timezone' => $row[17] ?? null,
                'latitude' => $row[4] ?? null,
                'longitude' => $row[5] ?? null,
                'geoname_id' => $row[0] ?? null,
                'created_at' => now(),
                'updated_at' => now()
            ];

            if (count($cityData) >= 1000) {
                DB::table('cities')->insert($cityData);
                $this->line("   ⏳ Залито городов: " . DB::table('cities')->count());
                $cityData = [];
            }
        }
        fclose($handle);
        if (!empty($cityData)) DB::table('cities')->insert($cityData);

        unlink($tempZip);
        unlink($filePath);

        $this->info("   ✅ Городов РФ импортировано: " . DB::table('cities')->count());

        // ============================================
        // 4. ИМПОРТ РУССКИХ ПЕРЕВОДОВ
        // ============================================
        $this->info('🇷🇺 Загружаем русские переводы...');
        $this->applyRussianTranslations();

        $this->info('🎉 Импорт гео-данных завершен успешно!');
    }

    /**
     * Скачивает alternateNames.zip и обновляет названия на русские
     */
    private function applyRussianTranslations(): void
    {
        $tempZip = storage_path('app/alternateNames.zip');
        $filePath = storage_path('app/alternateNames.txt');

        // 1. Проверяем, есть ли уже локальный файл переводов
        if (!file_exists($filePath)) {
            $this->info('   Скачиваем архив с переводами (200MB)... Это нужно только 1 раз!');
            file_put_contents($tempZip, file_get_contents('http://download.geonames.org/export/dump/alternateNames.zip'));

            $zip = new ZipArchive;
            if ($zip->open($tempZip) === true) {
                $zip->extractTo(storage_path('app/'));
                $zip->close();
            }
            unlink($tempZip); // Удаляем зип, оставляем только txt
        } else {
            $this->info('   ℹ️ Используем локальный файл переводов (200MB уже скачан ранее)...');
        }

        // Быстро считаем строки
        $this->info('   Подсчет строк в файле переводов...');
        $maxLines = (int) trim(shell_exec('wc -l < ' . escapeshellarg($filePath)));

        $handle = fopen($filePath, 'r');
        $ruNames = [];

        $this->output->progressStart($maxLines);

        while (($line = fgets($handle)) !== false) {
            $this->output->progressAdvance();

            $row = explode("\t", $line);
            // row[2] - это код языка. Нам нужен только 'ru'
            if (!isset($row[2]) || $row[2] !== 'ru') continue;

            $geonameId = $row[1] ?? null;
            $ruName = trim($row[3] ?? '');

            if ($geonameId && $ruName) {
                // Берем только первое найденное русское название
                if (!isset($ruNames[$geonameId])) {
                    $ruNames[$geonameId] = $ruName;
                }
            }
        }
        fclose($handle);
        $this->output->progressFinish();

        $this->info("   ✅ Найдено русских переводов: " . count($ruNames) . ". Обновляем базу...");

        // 1. Создаем временную таблицу
        DB::statement('CREATE TEMP TABLE geo_translations (geoname_id BIGINT, ru_name TEXT)');

        // 2. Вставляем пачками по 1000 строк (тоже с прогресс-баром)
        $chunks = array_chunk($ruNames, 1000, true);
        $this->output->progressStart(count($chunks));

        foreach ($chunks as $chunk) {
            $this->output->progressAdvance();
            $data = [];
            foreach ($chunk as $geonameId => $ruName) {
                $data[] = ['geoname_id' => $geonameId, 'ru_name' => $ruName];
            }
            DB::table('geo_translations')->insert($data);
        }
        $this->output->progressFinish();

        // 3. Делаем 3 массивных UPDATE через JOIN (за доли секунды!)
        $this->info('   Применяем переводы к базе...');
        DB::statement('UPDATE cities c SET name = t.ru_name FROM geo_translations t WHERE c.geoname_id = t.geoname_id');
        DB::statement('UPDATE countries c SET name = t.ru_name FROM geo_translations t WHERE c.geoname_id = t.geoname_id');
        DB::statement('UPDATE states s SET name = t.ru_name FROM geo_translations t WHERE s.geoname_id = t.geoname_id');

        // 4. Удаляем временную таблицу
        DB::statement('DROP TABLE geo_translations');

        $this->info("   ✅ База обновлена идеальными русскими названиями!");

        // Применяем хардкод-мапу для городов без перевода
        $this->applyManualTranslations();
    }

    /**
     * Применяет ручные переводы для городов, у которых нет русского перевода в GeoNames.
     */
    private function applyManualTranslations(): void
    {
        $this->info('   Применяем ручные переводы для непереведённых городов...');

        // ✅ Берём мапу из конфига
        $manualRuNames = config('geo_ip_extra_translations.cities', []);

        $updated = 0;
        foreach ($manualRuNames as $engName => $ruName) {
            // Обновляем только если текущее имя всё ещё на английском
            $affected = DB::table('cities')
                ->where('name', $engName)
                ->update(['name' => $ruName]);

            $updated += $affected;
        }

        if ($updated > 0) {
            $this->info("   ✅ Исправлено городов вручную: {$updated}");
        } else {
            $this->info("   ℹ️ Все города уже переведены, ручная правка не потребовалась.");
        }
    }
}
