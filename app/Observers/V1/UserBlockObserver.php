<?php

namespace App\Observers;

use App\Models\UserBlock;
use App\Services\UserSearchService;
use Illuminate\Support\Facades\Log;

class UserBlockObserver
{
    public function __construct(
        private readonly UserSearchService $searchService
    ) {}

    public function created(UserBlock $block): void
    {
        // Blocker больше не видит заблокированного
        $this->searchService->invalidateUserCache($block->blocker_id);

        // Заблокированный тоже не видит блокера (взаимный блок)
        $this->searchService->invalidateUserCache($block->blocked_id);

        Log::info('search.cache.invalidated', [
            'reason' => 'user_blocked',
            'blocker_id' => $block->blocker_id,
            'blocked_id' => $block->blocked_id,
        ]);
    }

    /**
     * При разблокировке — оба юзера снова могут видеть друг друга.
     * ВАЖНО: без этого события кэш остаётся старым, юзеры не увидят друг друга
     * до истечения TTL (60 сек).
     */
    public function deleted(UserBlock $block): void
    {
        $this->searchService->invalidateUserCache($block->blocker_id);
        $this->searchService->invalidateUserCache($block->blocked_id);

        Log::info('search.cache.invalidated', [
            'reason' => 'user_unblocked',
            'blocker_id' => $block->blocker_id,
            'blocked_id' => $block->blocked_id,
        ]);
    }
}