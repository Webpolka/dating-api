<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserPreference extends Model
{
    use HasFactory;

    // Константы для значений enum-полей — чтобы в коде не использовать строки.
    public const GENDER_ANY = 'any';
    public const GENDER_MALE = 'male';
    public const GENDER_FEMALE = 'female';

    public const THEME_LIGHT = 'light';
    public const THEME_DARK = 'dark';

    // Дефолтные значения для filter-полей.
    // Используются когда БД возвращает null или пустую строку.
    public const DEFAULT_SEARCH_FILTERS = [
        'body_type' => null,
        'eye_color' => null,
        'hair_color' => null,
        'height_from' => null,
        'height_to' => null,
        'education' => null,
        'zodiac_sign' => null,
        'is_verified_only' => false,
        'is_premium_only' => false,
    ];

    public const DEFAULT_CHAT_FILTERS = [
        'gender' => 'any',
        'age_from' => 18,
        'age_to' => 99,
        'is_verified_only' => false,
        'is_premium_only' => false,
    ];

    public const DEFAULT_EMAIL_SETTINGS = [
        'on_message' => true,
        'on_like' => true,
        'on_view' => false,
        'on_gift' => true,
        'on_event' => true,
        'on_broadcast' => true,
        'sub_new_faces' => true,
        'sub_popular' => false,
    ];

    protected $fillable = [
        'user_id',
        'locale',
        'theme',
        'preferred_age_min',
        'preferred_age_max',
        'preferred_gender',
        'preferred_distance_km',
        'search_filters',
        'chat_filter_enabled',
        'chat_filter_settings',
        'is_invisible',
        'hide_intimate',
        'disable_photo_comments',
        'hide_from_search',
        'push_enabled',
        'email_enabled',
        'email_settings',
        'show_quick_replies',
        'chat_widget_enabled',
        'chat_sound_enabled',
        'visibility_gender',
        'visibility_age_min',
        'visibility_age_max',
        'push_auto_recommendations',
        'email_auto_recommendations',
        'allow_auto_messages',
    ];

    protected $casts = [
        'preferred_age_min' => 'integer',
        'preferred_age_max' => 'integer',
        'preferred_distance_km' => 'integer',
        'chat_filter_enabled' => 'boolean',
        'is_invisible' => 'boolean',
        'hide_intimate' => 'boolean',
        'disable_photo_comments' => 'boolean',
        'hide_from_search' => 'boolean',
        'show_quick_replies' => 'boolean',
        'chat_widget_enabled' => 'boolean',
        'chat_sound_enabled' => 'boolean',
        'push_enabled' => 'boolean',
        'email_enabled' => 'boolean',
        'visibility_age_min' => 'integer',
        'visibility_age_max' => 'integer',
        'push_auto_recommendations' => 'boolean',
        'email_auto_recommendations' => 'boolean',
        'allow_auto_messages' => 'boolean',
        // ВАЖНО: search_filters, chat_filter_settings, email_settings НЕ добавляем в $casts.
        // У них есть кастомные accessor'ы ниже, которые возвращают defaults при null.
        // Если добавить в casts — accessor'ы перестанут работать (Laravel их не вызовет).
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ============================================
    // СКОПЫ ДЛЯ АВТОМАТИЗАЦИИ
    // ============================================
    
    public function scopeWantsPushRecommendations(Builder $query): Builder
    {
        return $query->where('push_enabled', true)
            ->where('push_auto_recommendations', true);
    }

    public function scopeWantsEmailRecommendations(Builder $query): Builder
    {
        return $query->where('email_enabled', true)
            ->where('email_auto_recommendations', true);
    }

    public function scopeWantsAutoMessages(Builder $query): Builder
    {
        return $query->where('allow_auto_messages', true);
    }

    /**
     * НОВЫЙ SCOPE: юзеры, которые видны в поиске.
     * Используется для подсчёта "сколько кандидатов доступно".
     *
     * ИСПОЛЬЗОВАНИЕ:
     *   UserPreference::searchVisible()->count();
     *
     * Использует partial индекс user_prefs_search_visible_idx (миграция #3).
     */
    public function scopeSearchVisible(Builder $query): Builder
    {
        return $query->where('hide_from_search', false)
            ->where('is_invisible', false);
    }

    // ============================================
    // АКСЕССОРЫ С ДЕФОЛТАМИ
    // ============================================

    /**
     * Search filters — расширенные фильтры поиска.
     *
     * Возвращает defaults + реальные значения из БД (merged).
     * Если в БД null или не массив — возвращает только defaults.
     */
    public function getSearchFiltersAttribute($value): array
    {
        $filters = $this->decodeJsonb($value);
        return array_merge(self::DEFAULT_SEARCH_FILTERS, $filters);
    }

    /**
     * Chat filter settings — кто может писать юзеру.
     */
    public function getChatFilterSettingsAttribute($value): array
    {
        $filters = $this->decodeJsonb($value);
        return array_merge(self::DEFAULT_CHAT_FILTERS, $filters);
    }

    /**
     * Email settings — гранулярные настройки email-уведомлений.
     */
    public function getEmailSettingsAttribute($value): array
    {
        $settings = $this->decodeJsonb($value);
        return array_merge(self::DEFAULT_EMAIL_SETTINGS, $settings);
    }

    /**
     * Универсальный декодер JSONB — обрабатывает string/array/null.
     *
     * PostgreSQL через Eloquent может вернуть:
     *   - string (если cast не указан) — нужно json_decode
     *   - array (если есть cast 'array', но мы не ставим чтобы accessor работал)
     *   - null (если значение NULL в БД)
     */
    private function decodeJsonb($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        if (is_array($value)) {
            return $value;
        }
        return [];
    }

    // ============================================
    // HELPERS ДЛЯ ПОИСКА И МАТЧИНГА
    // ============================================

    /**
     * Проверка: подходит ли пол кандидата под предпочтения юзера?
     *
     * ИСПОЛЬЗОВАНИЕ:
     *   if ($user->preferences->acceptsGender($candidate->profile->gender)) { ... }
     *
     * @param string|null $candidateGender — 'male' или 'female' (или null если не указан)
     */
    public function acceptsGender(?string $candidateGender): bool
    {
        if (!$candidateGender) {
            // Кандидат без указанного пола — показываем только если preferred_gender='any'.
            return $this->preferred_gender === self::GENDER_ANY;
        }
        return $this->preferred_gender === self::GENDER_ANY 
            || $this->preferred_gender === $candidateGender;
    }

    /**
     * Проверка: подходит ли возраст кандидата под предпочтения юзера?
     *
     * ИСПОЛЬЗОВАНИЕ:
     *   if ($user->preferences->acceptsAge(25)) { ... }
     *
     * @param int|null $candidateAge — возраст кандидата (или null если неизвестен)
     */
    public function acceptsAge(?int $candidateAge): bool
    {
        if ($candidateAge === null) {
            // Кандидат без указанного возраста — показываем (лучше пусть увидят).
            // Можно изменить на false если хочешь строго фильтровать.
            return true;
        }
        return $candidateAge >= $this->preferred_age_min 
            && $candidateAge <= $this->preferred_age_max;
    }

    /**
     * Проверка: видит ли юзер $other в своём поиске?
     *
     * Это ВАЖНО — комбинирует preferred_gender + preferred_age.
     * Используется в контроллере поиска для фильтрации.
     *
     * ИСПОЛЬЗОВАНИЕ:
     *   if ($user->preferences->acceptsInSearch($otherUser)) { ... }
     */
    public function acceptsInSearch(User $otherUser): bool
    {
        $profile = $otherUser->profile;
        if (!$profile) {
            return false;
        }
        return $this->acceptsGender($profile->gender) 
            && $this->acceptsAge($profile->age);
    }

    /**
     * REVERSE-ФИЛЬТР: может ли $other юзер видеть МЕНЯ?
     *
     * Это обратный фильтр поиска. Если у меня:
     *   visibility_gender = 'female'
     *   visibility_age_min = 25, visibility_age_max = 35
     *
     * То мужчина 40 лет меня НЕ увидит в поиске.
     *
     * ВАЖНО: это частая причина "пустого поиска" — все кандидаты отсекаются
     * их собственными visibility-настройками.
     *
     * ИСПОЛЬЗОВАНИЕ:
     *   // Проверка: может ли текущий юзер (мужчина 30) видеть этого кандидата?
     *   if ($candidate->preferences->isVisibleTo($currentUser)) { ... }
     */
    public function isVisibleTo(User $viewer): bool
    {
        $viewerProfile = $viewer->profile;
        if (!$viewerProfile) {
            return false;
        }

        // Пол тем, кто видит — если не 'any' и не совпадает с полом viewer'а → нельзя.
        if ($this->visibility_gender !== self::GENDER_ANY 
            && $this->visibility_gender !== $viewerProfile->gender) {
            return false;
        }

        // Возраст viewer'а должен попадать в visibility-диапазон.
        $viewerAge = $viewerProfile->age;
        if ($viewerAge !== null) {
            if ($viewerAge < $this->visibility_age_min || $viewerAge > $this->visibility_age_max) {
                return false;
            }
        }

        return true;
    }

    /**
     * Helper: виден ли юзер в поиске?
     * Комбинация hide_from_search + is_invisible.
     */
    public function isSearchable(): bool
    {
        return !$this->hide_from_search && !$this->is_invisible;
    }

    /**
     * Helper: разрешено ли $other писать этому юзеру?
     * Проверка chat_filter_enabled + chat_filter_settings.
     *
     * ИСПОЛЬЗОВАНИЕ:
     *   if ($user->preferences->allowsMessageFrom($otherUser)) { ... }
     */
    public function allowsMessageFrom(User $sender): bool
    {
        if (!$this->chat_filter_enabled) {
            return true;
        }

        $filters = $this->chat_filter_settings; // accessor уже вернул defaults + real values

        $senderProfile = $sender->profile;
        if (!$senderProfile) {
            return false;
        }

        // Пол
        if ($filters['gender'] !== self::GENDER_ANY 
            && $filters['gender'] !== $senderProfile->gender) {
            return false;
        }

        // Возраст
        $senderAge = $senderProfile->age;
        if ($senderAge !== null) {
            if ($senderAge < $filters['age_from'] || $senderAge > $filters['age_to']) {
                return false;
            }
        }

        // Verified only
        if ($filters['is_verified_only'] && !$sender->is_verified) {
            return false;
        }

        // Premium only
        if ($filters['is_premium_only'] && !$sender->has_active_premium) {
            return false;
        }

        return true;
    }

    // ============================================
    // СОБЫТИЯ МОДЕЛИ — ВАЛИДАЦИЯ ПЕРЕД СОХРАНЕНИЕМ
    // ============================================

    protected static function booted()
    {
        // ДОБАВЛЕНО: валидация preferred_age_min <= preferred_age_max.
        // CHECK constraint в БД (миграция #3) отклонит инверсию, но это даст исключение
        // с непонятным сообщением. Свопаем в коде — дружелюбнее.
        static::saving(function (UserPreference $pref) {
            if ($pref->preferred_age_min > $pref->preferred_age_max) {
                [$pref->preferred_age_min, $pref->preferred_age_max] = 
                    [$pref->preferred_age_max, $pref->preferred_age_min];
            }

            if ($pref->visibility_age_min > $pref->visibility_age_max) {
                [$pref->visibility_age_min, $pref->visibility_age_max] = 
                    [$pref->visibility_age_max, $pref->visibility_age_min];
            }

            // Защита от preferred_distance_km = 0 (ничего не найдёт в поиске).
            if ($pref->preferred_distance_km < 1) {
                $pref->preferred_distance_km = 1;
            }
            if ($pref->preferred_distance_km > 1000) {
                $pref->preferred_distance_km = 1000;
            }

            // Защита от preferred_age_min < 18 (несовершеннолетние в дейтинге недопустимы).
            if ($pref->preferred_age_min < 18) {
                $pref->preferred_age_min = 18;
            }
            if ($pref->visibility_age_min < 18) {
                $pref->visibility_age_min = 18;
            }
        });
    }
}