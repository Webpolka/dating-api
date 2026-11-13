<?php

namespace App\Observers;

use App\Models\UserProfile;
use App\Services\UserSearchService;
use Illuminate\Support\Facades\Log;
use App\Jobs\InvalidateAllSearchCacheJob;

class UserProfileObserver
{
    public function __construct(
        private readonly UserSearchService $searchService
    ) {}

    /**
     * Поля, изменение которых влияет на ВИДИМОСТЬ юзера в выдаче ДРУГИХ.
     * Например: сменил пол → другие юзеры ищут "только женщин",
     * и этот юзер теперь попадает/не попадает в их выдачу.
     *
     * Требуют invalidateAllCache (асинхронно через job на проде!).
     */
    private const VISIBILITY_IMPACTED_FIELDS = [
        'gender',
        'birth_date',
        'deleted_at', // soft-delete — юзер исчезает из выдач других
    ];

    /**
     * Поля, изменение которых влияет только на карточку юзера в выдаче ДРУГИХ.
     * (Сам юзер в выдаче остаётся, просто его карточка выглядит по-другому.)
     *
     * На самом деле эти поля НЕ требуют инвалидации кэша вообще —
     * карточка берётся из БД при каждом cache miss, и кэш на 60 сек даст
     * обновление автоматически.
     *
     * Но мы всё равно инвалидируем кэш САМОГО юзера (его cache key
     * не меняется, но его собственная выдача зависит от фильтров,
     * которые могут учитывать пол/возраст — а это уже VISIBILITY_IMPACTED).
     */
    private const SELF_DISPLAY_FIELDS = [
        'city_id',
        'country_id',
        'dating_goals',
        'interest_ids',
        'languages',
        'sports',
        'body_decorations',
        'body_type',
        'eye_color',
        'hair_color',
        'height',
        'weight',
        'relationship_status',
        'children_status',
        'pets',
        'housing',
        'has_car',
        'smoking',
        'alcohol',
        'zodiac_sign',
        'education_level',
        'income',
        'location',
        'address',
        'headline',
        'bio',
        'looking_for',
        'self_portrait',
    ];

    public function updated(UserProfile $profile): void
    {
        $changes = $profile->getChanges() ? array_keys($profile->getChanges()) : [];

        $visibilityChanged = array_intersect(self::VISIBILITY_IMPACTED_FIELDS, $changes);
        $displayChanged = array_intersect(self::SELF_DISPLAY_FIELDS, $changes);

        // Ничего важного не изменилось (например, updated_at) — выходим
        if (empty($visibilityChanged) && empty($displayChanged)) {
            return;
        }

        Log::info('profile.updated', [
            'user_id' => $profile->user_id,
            'visibility_changed' => array_values($visibilityChanged),
            'display_changed' => array_values($displayChanged),
        ]);

        // 1. ВСЕГДА инвалидируем кэш САМОГО юзера
        //    (cache key включает gender, birth_date, city_id, country_id —
        //    при их изменении cache key автоматически станет другим, НО
        //    старый кэш останется "висеть" до истечения TTL.
        //    Поэтому инвалидируем явно — освободим память.)
        $this->searchService->invalidateUserCache($profile->user_id);

        // 2. Если изменились VISIBILITY-поля — юзер может исчезнуть/появиться
        //    в выдачах ДРУГИХ юзеров → сбрасываем ВЕСЬ кэш.
        //
        //    ВАЖНО: на миллионах юзеров — это дорого.
        //    На проде вынести в background job:
        //      InvalidateAllSearchCacheJob::dispatch()->onQueue('cache');
        if (!empty($visibilityChanged)) {
            InvalidateAllSearchCacheJob::dispatch();

            Log::warning('search.cache.all_invalidated', [
                'reason' => 'profile_visibility_change',
                'user_id' => $profile->user_id,
                'fields' => array_values($visibilityChanged),
                'note' => 'Consider dispatching a background job on production',
            ]);
        }
    }
}