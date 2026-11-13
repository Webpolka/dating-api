<?php

namespace App\Observers\V1;

use App\Models\Swipe;
use App\Services\V1\UserSearchService;
use Illuminate\Support\Facades\Log;

class SwipeObserver
{
    public function __construct(
        private readonly UserSearchService $searchService
    ) {}

    /**
     * Юзер сделал свайп → он больше не должен видеть target в выдаче.
     */
    public function created(Swipe $swipe): void
    {
        // Свайпер больше не видит target в выдаче
        $this->searchService->invalidateUserCache($swipe->user_id);

        Log::info('search.cache.invalidated', [
            'reason' => 'swipe_created',
            'user_id' => $swipe->user_id,
            'target_user_id' => $swipe->target_user_id,
        ]);

        // Target может тоже не видеть свайпера, если у target есть фильтры,
        // которые зависят от пола/возраста свайпера.
        // НО: инвалидируем только если target_user_id не null
        // (защита от свайпа по удалённому юзеру с FK SET NULL).
        if ($swipe->target_user_id !== null) {
            $this->searchService->invalidateUserCache($swipe->target_user_id);
        }
    }

    /**
     * Если юзер отмотал свайп (rewind) — он снова может видеть target.
     */
    public function updated(Swipe $swipe): void
    {
        // Проверяем что изменилось поле rewinded_at и оно стало не null.
        if (!$swipe->wasChanged('rewinded_at') || !$swipe->rewinded_at) {
            return;
        }

        $this->searchService->invalidateUserCache($swipe->user_id);

        Log::info('search.cache.invalidated', [
            'reason' => 'swipe_rewound',
            'user_id' => $swipe->user_id,
            'target_user_id' => $swipe->target_user_id,
        ]);
    }

    /**
     * Если свайп удалили (например, через админку) — оба юзера снова видят друг друга.
     *
     * C3 FIX: Раньше инвалидовался только $swipe->user_id (свайпер).
     * target_user_id НЕ инвалидовался → target user видел устаревший feed,
     * где свайпер "уже свайпнул меня" — хотя свайп удалён.
     * Теперь инвалидируем обоих, как в created().
     */
    public function deleted(Swipe $swipe): void
    {
        // Свайпер снова видит target в выдаче
        $this->searchService->invalidateUserCache($swipe->user_id);

        // Target тоже видит свайпера снова (если target не удалён — FK SET NULL)
        if ($swipe->target_user_id !== null) {
            $this->searchService->invalidateUserCache($swipe->target_user_id);
        }

        Log::info('search.cache.invalidated', [
            'reason' => 'swipe_deleted',
            'user_id' => $swipe->user_id,
            'target_user_id' => $swipe->target_user_id,
        ]);
    }
}
