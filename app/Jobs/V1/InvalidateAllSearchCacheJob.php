<?php

namespace App\Jobs\V1;

use App\Services\V1\UserSearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class InvalidateAllSearchCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly ?int $userId = null
    ) {}

    public function handle(UserSearchService $searchService): void
    {
        if ($this->userId) {
            $searchService->invalidateUserCache($this->userId);
        } else {
            $searchService->invalidateAllCache();
        }
    }
}