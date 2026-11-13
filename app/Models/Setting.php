<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class Setting extends Model
{
    use HasFactory;

    public const TYPE_TEXT = 'text';
    public const TYPE_TEXTAREA = 'textarea';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_SELECT = 'select';
    public const TYPE_JSON = 'json';

    protected $fillable = [
        'key', 'value', 'group', 'label', 'description', 'type', 'options', 'is_public'
    ];

    protected $casts = [
        'options' => 'array',
        'is_public' => 'boolean',
    ];

    // ============================================
    // КЭШИРОВАНИЕ
    // ============================================

    /**
     * Получить все настройки (закэшированные).
     *
     * При падении Redis — fallback на прямой запрос к БД.
     * Сайт продолжает работать, просто без кэширования (медленнее).
     */
    public static function getAllCached(): Collection
    {
        try {
            $cachedArray = Cache::rememberForever('settings_v1', function () {
                return static::all()->keyBy('key')->map(function ($item) {
                    return $item->getAttributes();
                })->toArray();
            });

            return collect($cachedArray);
        } catch (\Throwable $e) {
            Log::warning('Setting: cache failed, fetching from DB', [
                'error' => $e->getMessage(),
            ]);

            // Fallback: тот же запрос к БД, но без кэша
            $dbArray = static::all()->keyBy('key')->map(function ($item) {
                return $item->getAttributes();
            })->toArray();

            return collect($dbArray);
        }
    }

    /**
     * Сбросить кэш настроек.
     * При падении Redis — silent (лог warning).
     */
    public static function flushCache(): void
    {
        try {
            Cache::forget('settings_v1');
        } catch (\Throwable $e) {
            Log::warning('Setting: cache forget failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    // ============================================
    // ПОЛУЧЕНИЕ ЗНАЧЕНИЙ ПО УМОЛЧАНИЮ ИЗ КОНФИГА
    // ============================================
    public static function getDefault(string $key): mixed
    {
        return config("settings.{$key}.default");
    }

    // ============================================
    // МАГИЧЕСКИЕ МЕТОДЫ ПОЛУЧЕНИЯ ЗНАЧЕНИЙ
    // ============================================
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::getAllCached()->get($key);

        if (!$setting) {
            return $default;
        }

        return self::castValue($setting);
    }

    public static function set(string $key, mixed $value, array $attributes = []): self
    {
        $type = $attributes['type'] ?? self::TYPE_TEXT;
        if ($type === self::TYPE_JSON && is_array($value)) {
            $value = json_encode($value);
        }

        $setting = static::updateOrCreate(
            ['key' => $key],
            array_merge(['value' => $value], $attributes)
        );

        static::flushCache();
        return $setting;
    }

    public static function getGroup(string $group): Collection
    {
        return static::getAllCached()->filter(fn($item) => $item['group'] === $group);
    }

    public static function getPublic(): array
    {
        return static::getAllCached()
            ->filter(fn($item) => $item['is_public'])
            ->mapWithKeys(fn($item) => [$item['key'] => self::castValue($item)])
            ->toArray();
    }

    private static function castValue(array $setting): mixed
    {
        $value = $setting['value'] ?? null;
        $type = $setting['type'] ?? self::TYPE_TEXT;

        return match ($type) {
            self::TYPE_BOOLEAN => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            self::TYPE_INTEGER => (int) $value,
            self::TYPE_JSON    => json_decode($value, true),
            default           => $value,
        };
    }

    // ============================================
    // СОБЫТИЯ МОДЕЛИ
    // ============================================
    protected static function booted()
    {
        static::saved(function () {
            static::flushCache();
        });

        static::deleted(function () {
            static::flushCache();
        });
    }
}
