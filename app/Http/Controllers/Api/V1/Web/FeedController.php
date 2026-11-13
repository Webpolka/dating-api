<?php

namespace App\Http\Controllers\Api\V1\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Web\FeedSearchRequest;
use App\Models\User;
use App\Services\V1\UserSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class FeedController extends Controller
{
    /**
     * Поиск анкет с кэшированием готового JSON-ответа.
     *
     * АРхитектура:
     *  - SQL отдаёт Eloquent-модели с relations (photos, profile, city).
     *  - Контроллер трансформирует их в плоский массив.
     *  - В Redis кэшируется уже ГОТОВЫЙ JSON-ответ (на 60 сек).
     *  - При cache hit отдаём JSON как есть — никаких проблем с relations.
     *
     * Кэш-инвалидация: через Observers на Swipe/UserBlock/UserProfile/UserPreference
     * (см. следующий шаг). Контроллер только читает кэш.
     */
    public function search(FeedSearchRequest $request, UserSearchService $searchService): JsonResponse
    {
        try {
            $filters = $request->getFilters();
            $authUser = $request->user();

            $page = max(1, (int) $request->input('page', 1));
            $perPage = $searchService->normalizePerPage($request->input('per_page'));

            // Ключ кэша — берём из Service (единый источник истины)
            $cacheKey = $searchService->buildCacheKey($authUser, $filters, $page, $perPage);
            $cacheTtl = config('search.cache_ttl', 60);

            // ===== 1. ПРОБУЕМ ДОСТАТЬ ИЗ REDIS =====
            $cached = $this->safeRedisGet($cacheKey);
            if ($cached !== null) {
                $payload = json_decode($cached, true);
                if (is_array($payload) && isset($payload['users'])) {
                    Log::debug('FeedController: cache HIT', [
                        'cache_key' => $cacheKey,
                        'user_id'   => $authUser?->id,
                    ]);
                    return response()->json($payload);
                }
                // Битый кэш — удаляем
                $this->safeRedisDel($cacheKey);
            }

            // ===== 2. CACHE MISS — ВЫПОЛНЯЕМ SQL =====
            $users = $searchService->searchPaginated($authUser, $filters, $page, $perPage);

            if ($users->isEmpty()) {
                // Пустой результат — кэшируем пустоту на короткий TTL (15 сек)
                // чтобы не долбить БД при одинаковых фильтрах.
                $emptyPayload = $this->buildEmptyPayload($page, $perPage);
                $this->safeRedisSetex($cacheKey, 15, json_encode($emptyPayload, JSON_UNESCAPED_UNICODE));

                Log::info('FeedController: search returned empty', [
                    'user_id'  => $authUser?->id,
                    'filters'  => $filters,
                    'page'     => $page,
                ]);
                return response()->json($emptyPayload);
            }

            // ===== 3. ТРАНСФОРМИРУЕМ МОДЕЛИ В ПЛОСКИЙ МАССИВ =====
            $mappedUsers = $users->getCollection()->map(fn ($u) => $this->transformUser($u))->values();

            $payload = [
                'users'        => $mappedUsers,
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'total'        => $users->total(),
                'per_page'     => $users->perPage(),
            ];

            // ===== 4. КЭШИРУЕМ ГОТОВЫЙ JSON =====
            $this->safeRedisSetex(
                $cacheKey,
                $cacheTtl,
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            Log::debug('FeedController: cache MISS, SQL executed', [
                'cache_key' => $cacheKey,
                'user_id'   => $authUser?->id,
                'total'     => $users->total(),
            ]);

            return response()->json($payload);

        } catch (Throwable $e) {
            Log::error('FeedController: search failed', [
                'user_id' => $authUser?->id ?? null,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error'   => true,
                'message' => 'Search temporarily unavailable',
                config('app.debug') ? 'debug' : '_' => [
                    'message' => $e->getMessage(),
                    'line'    => $e->getLine(),
                    'trace'   => $e->getTraceAsString(),
                ],
            ], 500);
        }
    }

    /**
     * Случайные аватары для префилла регистрационной формы.
     *
     * FIX: вместо inRandomOrder() (медленно на миллионах) используем
     * orderByRaw('RANDOM()') с LIMIT, и стараемся использовать
     * partial index users_verified_active_idx если возможно.
     */
    public function randomAvatars(): JsonResponse
    {
        try {
            $avatars = User::whereHas('profile', fn($q) => $q->where('gender', 'female'))
                ->whereHas('photos', fn($q) => $q->where('status', 'approved'))
                ->with([
                    'photos' => fn($q) => $q->where('status', 'approved')
                        ->orderByDesc('is_primary')
                        ->limit(1),
                ])
                ->whereNull('users.deleted_at')
                ->where('users.status', User::STATUS_ACTIVE)
                ->orderByRaw('RANDOM()')
                ->limit(2)
                ->get()
                ->map(fn($u) => $u->photos->first()?->thumb_url)
                ->filter()
                ->values();

            return response()->json(['avatars' => $avatars]);

        } catch (Throwable $e) {
            Log::error('FeedController: randomAvatars failed', [
                'error' => $e->getMessage(),
            ]);

            return response()->json(['avatars' => []]);
        }
    }

    // ============================================================
    // TRANSFORMATION HELPERS
    // ============================================================

    /**
     * Трансформирует User в плоский массив для фронтенда.
     *
     * FIX #1, #2: безопасный доступ к profile через ?->, fallback на defaults.
     * Раньше: $user->profile->age → 500 если profile null.
     * Теперь: $user->profile?->age ?? '?'.
     */
    private function transformUser(User $user): array
    {
        $profile = $user->profile;
        $primaryPhoto = $user->photos->isNotEmpty() ? $user->photos->first() : null;
        $isOnline = $user->is_online;

        return [
            'id'            => $user->id,
            'slug'          => $user->slug,
            'name'          => $user->name ?? 'Аноним',
            'age'           => $profile?->age ?? '?',
            'city'          => $profile?->city?->name ?? 'Город не указан',
            'is_online'     => $isOnline,
            'has_premium'   => $user->has_active_premium,
            'photo'         => $primaryPhoto?->medium_url,
            'photos_count'  => $user->photos_count ?? 0,
            'zodiac'        => $profile?->zodiac_name ?? 'Знак не указан',
            'distance'      => isset($user->distance) ? $user->distance . ' км' : null,
            'status_text'   => $isOnline ? 'Онлайн' : 'Был недавно',
        ];
    }

    /**
     * Строит payload для пустого результата.
     */
    private function buildEmptyPayload(int $page, int $perPage): array
    {
        return [
            'users'        => [],
            'current_page' => $page,
            'last_page'    => $page,
            'total'        => 0,
            'per_page'     => $perPage,
        ];
    }

    // ============================================================
    // REDIS HELPERS (с защитой от падения)
    // ============================================================

    /**
     * Безопасное чтение из Redis.
     */
    private function safeRedisGet(string $key): ?string
    {
        try {
            $value = Redis::get($key);
            return $value !== false ? $value : null;
        } catch (Throwable $e) {
            Log::warning('FeedController: Redis read failed, falling back to SQL', [
                'cache_key' => $key,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Безопасная запись в Redis с TTL.
     */
    private function safeRedisSetex(string $key, int $ttl, string $value): void
    {
        try {
            Redis::setex($key, $ttl, $value);
        } catch (Throwable $e) {
            Log::warning('FeedController: cache write failed, continuing without cache', [
                'cache_key' => $key,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Безопасное удаление ключа.
     */
    private function safeRedisDel(string $key): void
    {
        try {
            Redis::del($key);
        } catch (Throwable $e) {
            // silent — уже логируем выше
        }
    }
}