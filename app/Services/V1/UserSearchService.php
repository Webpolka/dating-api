<?php

namespace App\Services;

use App\Models\Interest;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Contracts\Redis\Connection as RedisConnection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;

/**
 * Сервис поиска анкет пользователей.
 *
 * Архитектура:
 *  - search()              — возвращает Builder (без кэша, без пагинации).
 *  - searchPaginated()     — возвращает Paginator (без кэша). Используется контроллером.
 *  - invalidateUserCache()  — сброс кэша конкретного юзера.
 *  - invalidateAllCache()   — сброс ВСЕГО кэша (после bulk-обновлений).
 *
 * Кэширование — в FeedController (готовый JSON на 60 сек).
 * Здесь — только построение запроса.
 *
 * ВАЖНО про производительность:
 *  - Использует partial indexes из миграций #1, #2, #3 (users_search_candidates_idx,
 *    user_profiles_gender_birthdate_active_idx, user_prefs_search_visible_idx, и т.д.)
 *  - Интересы ищет через классический pivot user_interests + whereHas.
 *    Использует INDEX(user_id) и INDEX(interest_id) на pivot — работает на миллионах
 *    юзеров с 5-10 интересами у каждого.
 *  - Свайпы/блоки исключает через whereNotExists (NOT IN с NULL = бага).
 */
class UserSearchService
{
    /** Cache prefix — унифицированный, используется и здесь, и в FeedController. */
    private const CACHE_PREFIX = 'feed_search';

    /** Размер чанка при удалении ключей из Redis. */
    private const DEL_CHUNK_SIZE = 500;

    public const VISIBILITY_FIELDS = [
        'hide_from_search',
        'is_invisible',
        'visibility_gender',
        'visibility_age_min',
        'visibility_age_max',
    ];

    public const SEARCH_PREFERENCE_FIELDS = [
        'preferred_gender',
        'preferred_age_min',
        'preferred_age_max',
        'preferred_distance_km',
        'search_filters',
    ];

    // ============================================================
    // ОСНОВНОЙ ПОИСК
    // ============================================================

    public function search(?User $user, array $filters = []): Builder
    {
        $filters = $this->normalizeFilters($filters);

        $query = User::query()
            ->select('users.*')
            ->join('user_profiles', 'users.id', '=', 'user_profiles.user_id')
            ->join('user_preferences', 'users.id', '=', 'user_preferences.user_id')
            ->whereNull('users.deleted_at')
            ->whereNull('user_profiles.deleted_at')
            ->where('users.status', User::STATUS_ACTIVE)
            ->where('users.role', User::ROLE_USER)
            ->where('users.has_completed_onboarding', true)
            ->whereNotNull('user_profiles.gender')
            ->whereNotNull('user_profiles.birth_date')
            ->where('user_preferences.hide_from_search', false)
            ->where('user_preferences.is_invisible', false);

        if ($user) {
            $this->applyUserContext($query, $user);
        }

        $this->applyBaseFilters($query, $filters);
        $this->applyLocationFilter($query, $filters);
        $this->applyAdvancedFilters($query, $filters);
        $this->applySorting($query, $filters);

        $query->with([
            'profile.city',
            'photos' => fn($q) => $q->where('status', 'approved')
                ->orderByDesc('is_primary')
                ->limit(1),
        ])->withCount([
            'photos' => fn($q) => $q->where('status', 'approved'),
        ]);

        return $query;
    }

    public function searchPaginated(
        ?User $user,
        array $filters = [],
        int $page = 1,
        ?int $perPage = null
    ): LengthAwarePaginator {
        $perPage = $this->normalizePerPage($perPage);
        $page = max(1, $page);

        return $this->search($user, $filters)
            ->paginate($perPage, ['*'], 'page', $page);
    }

    // ============================================================
    // КОНТЕКСТ ТЕКУЩЕГО ЮЗЕРА
    // ============================================================

    private function applyUserContext(Builder $query, User $user): void
    {
        $query->where('users.id', '!=', $user->id);

        // Исключаем засвайпанных — whereNotExists (нечувствителен к NULL)
        $query->whereNotExists(function ($q) use ($user) {
            $q->select(DB::raw(1))
                ->from('swipes')
                ->whereColumn('swipes.target_user_id', 'users.id')
                ->where('swipes.user_id', $user->id);
        });

        // Исключаем заблокированных
        $query->whereNotExists(function ($q) use ($user) {
            $q->select(DB::raw(1))
                ->from('user_blocks')
                ->whereColumn('user_blocks.blocked_id', 'users.id')
                ->where('user_blocks.blocker_id', $user->id);
        });

        // Reverse-фильтр: visibility-настройки других юзеров
        $userGender = $user->profile?->gender;
        $userAge = $user->profile?->age;

        $query->where(function ($q) use ($userGender) {
            $q->whereNull('user_preferences.visibility_gender')
              ->orWhere('user_preferences.visibility_gender', UserPreference::GENDER_ANY);
            if ($userGender !== null) {
                $q->orWhere('user_preferences.visibility_gender', $userGender);
            }
        });

        if ($userAge !== null) {
            $query->where(function ($q) use ($userAge) {
                $q->whereNull('user_preferences.visibility_age_min')
                  ->orWhere('user_preferences.visibility_age_min', '<=', $userAge);
            });

            $query->where(function ($q) use ($userAge) {
                $q->whereNull('user_preferences.visibility_age_max')
                  ->orWhere('user_preferences.visibility_age_max', '>=', $userAge);
            });
        }
    }

    // ============================================================
    // БАЗОВЫЕ ФИЛЬТРЫ
    // ============================================================

    private function applyBaseFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['gender']) && $filters['gender'] !== UserPreference::GENDER_ANY) {
            $query->where('user_profiles.gender', $filters['gender']);
        }

        [$ageFrom, $ageTo] = $this->normalizeAgeRange(
            (int)($filters['age_from'] ?? config('search.age_min', 18)),
            (int)($filters['age_to'] ?? config('search.age_max', 99))
        );

        $maxDate = Carbon::now()->subYears($ageFrom)->format('Y-m-d');
        $minDate = Carbon::now()->subYears($ageTo)->format('Y-m-d');
        $query->whereBetween('user_profiles.birth_date', [$minDate, $maxDate]);

        if (!empty($filters['country_id'])) {
            $query->where('user_profiles.country_id', (int)$filters['country_id']);
        }
        if (!empty($filters['city_id'])) {
            $query->where('user_profiles.city_id', (int)$filters['city_id']);
        }

        if (!empty($filters['dating_goal']) && $filters['dating_goal'] !== 'any') {
            $query->whereJsonContains('user_profiles.dating_goals', $filters['dating_goal']);
        }

        if (!empty($filters['activity'])) {
            if ($filters['activity'] === 'online') {
                $query->where('users.last_seen', '>=', now()->subMinutes(5));
            } elseif ($filters['activity'] === 'recently') {
                $query->where('users.last_seen', '>=', now()->subDays(1));
            }
        }

        if ($this->isTruthy($filters['is_new'] ?? null)) {
            $days = config('search.new_users_days', 7);
            $query->where('users.created_at', '>=', now()->subDays($days));
        }

        if ($this->isTruthy($filters['verified_only'] ?? null)) {
            $query->where('users.is_verified', true);
        }
    }

    private function applyLocationFilter(Builder $query, array $filters): void
    {
        $lat = isset($filters['lat']) ? (float)$filters['lat'] : null;
        $lng = isset($filters['lng']) ? (float)$filters['lng'] : null;
        $radiusKm = isset($filters['radius_km']) ? (int)$filters['radius_km'] : null;

        if ($lat !== null && ($lat < -90 || $lat > 90)) {
            $lat = null;
        }
        if ($lng !== null && ($lng < -180 || $lng > 180)) {
            $lng = null;
        }

        if ($lat !== null && $lng !== null && $radiusKm !== null && $radiusKm > 0) {
            $radiusMeters = $radiusKm * 1000;

            $query->whereNotNull('user_profiles.location')
                ->whereRaw(
                    'ST_DWithin(user_profiles.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
                    [$lng, $lat, $radiusMeters]
                )
                ->selectRaw(
                    'ROUND(ST_DistanceSphere(user_profiles.location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geometry) / 1000, 1) as distance',
                    [$lng, $lat]
                );
        }
    }

    /**
     * Расширенные фильтры: рост, вес, атрибуты профиля, интересы.
     *
     * ИНТЕРЕСЫ — через классический pivot user_interests + whereHas.
     * Использует INDEX(user_id) и INDEX(interest_id) на pivot —
     * работает на миллионах юзеров с 5-10 интересами у каждого.
     */
    private function applyAdvancedFilters(Builder $query, array $filters): void
    {
        $this->applyRangeFilter($query, $filters, 'height', 'user_profiles.height', 0, 300);
        $this->applyRangeFilter($query, $filters, 'weight', 'user_profiles.weight', 0, 500);

        $scalarArrayFilters = [
            'body_type', 'eye_color', 'hair_color', 'relationship_status',
            'children_status', 'pets', 'housing', 'has_car',
            'education_level', 'income', 'smoking', 'alcohol', 'zodiac_sign',
        ];
        foreach ($scalarArrayFilters as $field) {
            if (!empty($filters[$field]) && is_array($filters[$field])) {
                $intValues = array_filter(array_map('intval', $filters[$field]));
                if (!empty($intValues)) {
                    $query->whereIn("user_profiles.{$field}", $intValues);
                }
            }
        }

        // languages, sports, body_decorations — через нативный whereJsonContains
        $jsonArrayFilters = ['languages', 'sports', 'body_decorations'];
        foreach ($jsonArrayFilters as $field) {
            if (!empty($filters[$field]) && is_array($filters[$field])) {
                $intValues = array_filter(array_map('intval', $filters[$field]));
                if (!empty($intValues)) {
                    foreach ($intValues as $value) {
                        $query->whereJsonContains("user_profiles.{$field}", $value);
                    }
                }
            }
        }

        // ИНТЕРЕСЫ — через pivot user_interests (whereHas).
        // Генерирует WHERE EXISTS (SELECT 1 FROM user_interests ... WHERE ... IN (...))
        if (!empty($filters['interests']) && is_array($filters['interests'])) {
            $interestIds = array_filter(array_map('intval', $filters['interests']));
            if (!empty($interestIds)) {
                $query->whereHas('interests', function ($q) use ($interestIds) {
                    $q->whereIn('interests.id', $interestIds);
                    // УБРАНО: ->where('interests.status', Interest::STATUS_APPROVED)
                    // Больше нет колонки status — все интересы валидны по умолчанию
                });
            }
        }
    }

    private function applyRangeFilter(
        Builder $query,
        array $filters,
        string $fieldKey,
        string $column,
        int $min,
        int $max
    ): void {
        $from = $filters["{$fieldKey}_from"] ?? null;
        $to = $filters["{$fieldKey}_to"] ?? null;

        if ($from === null && $to === null) {
            return;
        }

        $fromVal = $from !== null ? max($min, (int)$from) : $min;
        $toVal = $to !== null ? min($max, (int)$to) : $max;

        if ($fromVal > $toVal) {
            [$fromVal, $toVal] = [$toVal, $fromVal];
        }

        $query->whereBetween($column, [$fromVal, $toVal]);
    }

    private function applySorting(Builder $query, array $filters): void
    {
        $sort = $filters['sort'] ?? 'last_seen';

        switch ($sort) {
            case 'distance':
                if ($this->hasDistanceColumn($query)) {
                    $query->orderBy('distance', 'asc');
                } else {
                    $query->orderByDesc('users.last_seen');
                }
                break;
            case 'new':
                $query->orderByDesc('users.created_at');
                break;
            case 'last_seen':
            default:
                $query->orderByDesc('users.last_seen');
                break;
        }
    }

    // ============================================================
    // ИНВАЛИДАЦИЯ КЭША
    // ============================================================

    public function invalidateUserCache(int $userId): void
    {
        $this->invalidateByPattern(
            self::CACHE_PREFIX . ':u' . $userId . ':*',
            ['user_id' => $userId]
        );
    }

    public function invalidateAllCache(): void
    {
        $this->invalidateByPattern(self::CACHE_PREFIX . ':*');
    }

    private function invalidateByPattern(string $pattern, array $logCtx = []): void
    {
        try {
            $keys = $this->scanKeys($pattern);
            if (empty($keys)) {
                return;
            }

            $totalCleared = 0;
            foreach (array_chunk($keys, self::DEL_CHUNK_SIZE) as $chunk) {
                $deleted = Redis::del($chunk);
                $totalCleared += is_int($deleted) ? $deleted : 0;
            }

            Log::info('UserSearch: cache invalidated', array_merge($logCtx, [
                'pattern' => $pattern,
                'keys_cleared' => $totalCleared,
            ]));
        } catch (\Throwable $e) {
            Log::warning('UserSearch: cache invalidation failed', array_merge($logCtx, [
                'pattern' => $pattern,
                'error' => $e->getMessage(),
            ]));
        }
    }

    private function scanKeys(string $pattern): array
    {
        $keys = [];
        $iterator = null;

        try {
            /** @var RedisConnection $connection */
            $connection = Redis::connection();

            while (true) {
                /** @noinspection PhpUndefinedMethodInspection */
                $batch = $connection->scan($iterator, $pattern, 100);

                if ($batch === false) {
                    break;
                }

                if (!is_array($batch)) {
                    break;
                }

                if (isset($batch[0], $batch[1]) && is_array($batch[1]) && count($batch) === 2) {
                    $keys = array_merge($keys, $batch[1]);
                    $iterator = $batch[0];
                } else {
                    $keys = array_merge($keys, $batch);
                }

                if (!$iterator || (int)$iterator === 0) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('UserSearch: SCAN failed, falling back to KEYS', [
                'pattern' => $pattern,
                'error' => $e->getMessage(),
            ]);

            try {
                $fallback = Redis::keys($pattern);
                $keys = is_array($fallback) ? $fallback : [];
            } catch (\Throwable $e2) {
                $keys = [];
            }
        }

        return $keys;
    }

    // ============================================================
    // ВСПОМОГАТЕЛЬНЫЕ МЕТОДЫ
    // ============================================================

    private function normalizeFilters(array $filters): array
    {
        $filters = array_filter($filters, function ($value) {
            return $value !== null && $value !== '' && $value !== [];
        });

        if (!empty($filters['interests']) && is_array($filters['interests'])) {
            $filters['interests'] = array_filter(array_map('intval', $filters['interests']));
        }

        $scalarArrayFilters = [
            'body_type', 'eye_color', 'hair_color', 'relationship_status',
            'children_status', 'pets', 'housing', 'has_car',
            'education_level', 'income', 'smoking', 'alcohol', 'zodiac_sign',
        ];
        foreach ($scalarArrayFilters as $field) {
            if (!empty($filters[$field]) && is_array($filters[$field])) {
                $filters[$field] = array_filter(array_map('intval', $filters[$field]));
            }
        }

        $jsonArrayFilters = ['languages', 'sports', 'body_decorations'];
        foreach ($jsonArrayFilters as $field) {
            if (!empty($filters[$field]) && is_array($filters[$field])) {
                $filters[$field] = array_filter(array_map('intval', $filters[$field]));
            }
        }

        return $filters;
    }

    private function normalizeAgeRange(int $from, int $to): array
    {
        $min = config('search.age_min', 18);
        $max = config('search.age_max', 99);

        $from = max($min, min($max, $from));
        $to = max($min, min($max, $to));

        if ($from > $to) {
            return [$to, $from];
        }
        return [$from, $to];
    }

    public function normalizePerPage(?int $perPage): int
    {
        $default = config('search.per_page', 20);
        $max = config('search.max_per_page', 50);

        if ($perPage === null || $perPage < 1) {
            return $default;
        }

        return min($perPage, $max);
    }

    private function isTruthy(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }
        if (is_bool($value)) {
            return $value;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    private function hasDistanceColumn(Builder $query): bool
    {
        $columns = $query->getQuery()->columns;
        if (empty($columns)) {
            return false;
        }
        foreach ($columns as $column) {
            $columnStr = is_string($column) ? $column : ($column->getValue() ?? '');
            if (str_contains($columnStr, 'distance')) {
                return true;
            }
        }
        return false;
    }

    public function buildCacheKey(?User $user, array $filters, int $page, int $perPage): string
    {
        $userId = $user?->id ?? 'guest';

        $profileHash = 'none';
        if ($user && $user->profile) {
            $profileHash = md5(implode('|', [
                $user->profile->gender ?? '',
                $user->profile->birth_date ?? '',
                $user->profile->city_id ?? '',
                $user->profile->country_id ?? '',
            ]));
        }

        $preferencesHash = 'none';
        if ($user && $user->preferences) {
            $preferencesHash = md5(implode('|', [
                $user->preferences->preferred_gender ?? '',
                $user->preferences->preferred_age_min ?? '',
                $user->preferences->preferred_age_max ?? '',
                $user->preferences->preferred_distance_km ?? '',
                json_encode($user->preferences->search_filters ?? []),
            ]));
        }

        ksort($filters);
        $filtersHash = md5(json_encode(
            $filters,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return sprintf(
            '%s:u%s:p%s:pr%s:f%s:p%d:s%d',
            self::CACHE_PREFIX,
            $userId,
            substr($profileHash, 0, 8),
            substr($preferencesHash, 0, 8),
            $filtersHash,
            $page,
            $perPage
        );
    }
}