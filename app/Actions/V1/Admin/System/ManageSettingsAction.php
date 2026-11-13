<?php

namespace App\Actions\Admin\System;

use App\Models\AdminLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Сохранение / сброс настроек системы.
 *
 * Извлечено из System\SettingController (S5-7).
 * Контроллер оставляет себе: инлайн-валидацию (Validator facade) и
 * формирование JSON-ответов. Исключения из транзакции (\InvalidArgumentException
 * при невалидном JSON и пр.) пробрасываются наверх — контроллер мапит их в 422/500.
 */
class ManageSettingsAction
{
    /**
     * Сохранить пачку настроек группы.
     *
     * @param string $group Группа настроек.
     * @param array<int, array{id: int, value: mixed}> $settings Валидированные значения.
     *
     * @throws \InvalidArgumentException Невалидный JSON у TYPE_JSON-настройки.
     */
    public function save(string $group, array $settings, User $admin): void
    {
        $beforeValues = [];

        DB::transaction(function () use ($settings, &$beforeValues) {
            $ids = array_column($settings, 'id');
            $dbSettings = Setting::whereIn('id', $ids)->get()->keyBy('id');

            foreach ($settings as $item) {
                if (!isset($dbSettings[$item['id']])) continue;

                $setting = $dbSettings[$item['id']];
                $value = $item['value'];

                // B3: JSON валидация — если json_decode fails, выбрасываем исключение.
                if ($setting->type === Setting::TYPE_JSON) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \InvalidArgumentException("Невалидный JSON для настройки «{$setting->key}»: " . json_last_error_msg());
                    }
                    $value = json_encode($decoded, JSON_UNESCAPED_UNICODE);
                } elseif ($setting->type === Setting::TYPE_BOOLEAN) {
                    $value = $value ? '1' : '0';
                } elseif ($setting->type === Setting::TYPE_INTEGER) {
                    // Защита от отрицательных значений. Для дейтинг-проекта
                    // отрицательные лимиты не имеют смысла (лайки, таймауты,
                    // возраста — всё ≥ 0). Если кто-то введёт -10 через
                    // DevTools — сохранится 0.
                    $value = (string) max(0, (int) $value);
                }

                $beforeValues[$setting->key] = $setting->getOriginal('value');
                $setting->value = $value;
                $setting->save(); // Вызовет событие 'saved' → сброс кэша
            }
        });

        // B1: loggable = null (нет конкретной модели, логируем группу).
        AdminLog::record('settings.update', null, $admin, ['group' => $group, 'before' => $beforeValues], [
            'group' => $group,
            'context' => ['admin_id' => $admin->id, 'group' => $group],
        ]);
    }

    /**
     * Сбросить все настройки группы к значениям по умолчанию (config/settings.php).
     */
    public function reset(string $group, User $admin): void
    {
        DB::transaction(function () use ($group) {
            $settings = Setting::where('group', $group)->get();
            $configDefaults = config('settings', []);

            foreach ($settings as $setting) {
                if (isset($configDefaults[$setting->key]['default'])) {
                    $setting->value = $configDefaults[$setting->key]['default'];
                    $setting->save();
                }
            }
        });

        // B1: loggable = null.
        AdminLog::record('settings.reset_defaults', null, $admin, null, [
            'group' => $group,
            'context' => ['admin_id' => $admin->id, 'group' => $group],
        ]);
    }
}
