<?php

namespace App\Http\Middleware\V1;

use App\Models\User;
use App\Services\V1\UserOnlineService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class UpdateOnlineStatus
{
    public function __construct(
        private readonly UserOnlineService $onlineService
    ) {}

    /**
     * Обновляем онлайн-статус юзера на каждый API запрос.
     *
     * Стратегия (ГИБРИД):
     * 1. Redis обновляем с throttling 60 сек (встроено в UserOnlineService)
     * 2. last_seen в БД обновляем раз в 5 минут (через проверку $user->last_seen)
     *
     * ВАЖНО: Все операции с Redis обёрнуты в try-catch.
     * При падении Redis — middleware НЕ роняет запрос, продолжаем работу.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if ($user instanceof User) {
            // 1. Обновляем Redis (с внутренним throttling 60 сек)
            // Обёрнуто в try-catch: при падении Redis — просто пропускаем,
            // запрос продолжается. Онлайн-статус будет неточным, но сайт работает.
            try {
                $this->onlineService->markOnline($user->id);
            } catch (\Throwable $e) {
                Log::warning('UpdateOnlineStatus: Redis markOnline failed, skipping', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // 2. Обновляем last_seen в БД (throttling 5 минут)
            // БД не зависит от Redis — всегда работает.
            try {
                $this->updateLastSeenInDb($user);
            } catch (\Throwable $e) {
                Log::warning('UpdateOnlineStatus: DB last_seen update failed', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $next($request);
    }

    /**
     * Обновляем last_seen в БД, но не чаще чем раз в 5 минут.
     */
    private function updateLastSeenInDb(User $user): void
    {
        if ($user->last_seen && $user->last_seen->gt(now()->subMinutes(5))) {
            return;
        }

        User::where('id', $user->id)->update([
            'last_seen' => now(),
        ]);

        $user->last_seen = now();

        Log::debug('🕐 [Middleware] last_seen updated in DB', [
            'user_id' => $user->id,
        ]);
    }
}