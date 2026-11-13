<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('⚙️  Синхронизируем настройки сайта из config/settings.php...');

        $settingsConfig = config('settings', []);
        $created = 0;
        $updated = 0;

        foreach ($settingsConfig as $key => $data) {
            // 1. Достаем дефолтное значение и удаляем ключ 'default' из массива,
            // чтобы он не попал в базу (там нет такой колонки).
            $defaultValue = $data['default'] ?? null;
            unset($data['default']);
            
            // 2. Формируем массив для вставки: ключ + метаданные (group, label, type и т.д.)
            $dbData = array_merge(['key' => $key], $data);
            
            // 3. При СОЗДАНИИ новой записи кладем дефолтное значение в колонку `value`
            $dbData['value'] = $defaultValue;

            $model = Setting::firstOrCreate(
                ['key' => $key],
                $dbData // Если записи нет, создаем со всеми данными (включая value = default)
            );

            if ($model->wasRecentlyCreated) {
                $created++;
            } else {
                // Если запись уже была, обновляем только метаданные (group, label, type), 
                // но НЕ трогаем 'value', чтобы не сбросить настройки, которые админ уже поменял руками!
                $model->fill($data)->save();
                $updated++;
            }
        }

        Setting::flushCache();
        $this->command->info('   🗑️ Кеш настроек сброшен');

        $this->command->newLine();
        $this->command->info('✅ Настройки синхронизированы:');
        $this->command->info("   - Создано новых: {$created}");
        $this->command->info("   - Обновлено метаданных: {$updated}");
        $this->command->info("   - Всего в базе: " . Setting::count());
    }
}