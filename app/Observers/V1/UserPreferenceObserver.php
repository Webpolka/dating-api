<?php

namespace App\Observers\V1;

use App\Models\UserPreference;
use App\Services\V1\UserSearchService;
use Illuminate\Support\Facades\Log;
use App\Jobs\V1\InvalidateAllSearchCacheJob;

class UserPreferenceObserver
{
    public function __construct(
        private readonly UserSearchService $searchService
    ) {}

    /**
     * Поля, изменение которых влияет на ВИДИМОСТЬ юзера в выдаче ДРУГИХ.
     * Требуют invalidateAllCache (асинхронно через job на проде!).
     */
    private const VISIBILITY_IMPACTED_FIELDS = [
        'hide_from_search',
        'is_invisible',
        'visibility_gender',
        'visibility_age_min',
        'visibility_age_max',
    ];

    /**
     * Поля, изменение которых влияет на cache key САМОГО юзера
     * (он будет искать по другим критериям).
     * Требуют ТОЛЬКО invalidateUserCache.
     */
    private const SELF_SEARCH_FIELDS = [
        'preferred_gender',
        'preferred_age_min',
        'preferred_age_max',
        'preferred_distance_km',
        'search_filters',
    ];

    public function updated(UserPreference $preference): void
    {
        $changes = $preference->getChanges() ? array_keys($preference->getChanges()) : [];

        $visibilityChanged = array_intersect(self::VISIBILITY_IMPACTED_FIELDS, $changes);
        $searchChanged = array_intersect(self::SELF_SEARCH_FIELDS, $changes);

        // Если ничего важного не изменилось — выходим
        if (empty($visibilityChanged) && empty($searchChanged)) {
            return;
        }

        Log::info('preference.updated', [
            'user_id' => $preference->user_id,
            'visibility_changed' => array_values($visibilityChanged),
            'search_changed' => array_values($searchChanged),
        ]);

        // 1. ВСЕГДА инвалидируем кэш САМОГО юзера
        //    (его собственная выдача изменится в любом случае)
        $this->searchService->invalidateUserCache($preference->user_id);

        // 2. Если изменились visibility-поля — юзер может исчезнуть/появиться
        //    в выдачах ДРУГИХ юзеров → сбрасываем ВЕСЬ кэш.
        //
        //    ВАЖНО: на миллионах юзеров это ОПАСНО — тысячи ключей в Redis.
        //    На проде нужно вынести в background job:
        //      InvalidateAllSearchCacheJob::dispatch()->onQueue('cache');
        if (!empty($visibilityChanged)) {
            InvalidateAllSearchCacheJob::dispatch();

            Log::warning('search.cache.all_invalidated', [
                'reason' => 'preference_visibility_change',
                'user_id' => $preference->user_id,
                'fields' => array_values($visibilityChanged),
                'note' => 'Consider dispatching a background job on production',
            ]);
        }
    }
}