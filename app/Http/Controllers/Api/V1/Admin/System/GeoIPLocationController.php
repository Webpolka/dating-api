<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Resources\Api\V1\Admin\System\GeoIPLocationResource;
use App\Models\AdminLog;
use App\Models\GeoIPLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * GeoIP локации — управление блокировками стран/регионов/городов.
 *
 * Два типа блокировок:
 *  - is_registration_blocked — юзеры из этой локации не могут регистрироваться
 *  - is_feed_blocked — юзеры из этой локации не видны в ленте (но могут регистрироваться)
 *
 * Логируется в AdminLog: кто заблокировал/разблокировал, какую локацию.
 *
 * Cache: admin_geo_counts (60 сек). Инвалидация после toggleRegistration/toggleFeed.
 */
class GeoIPLocationController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $type          = (string) $request->string('type', 'country');
        $search        = (string) $request->string('q', '');
        $perPage       = min(max((int) $request->integer('per_page', 50), 10), 200);
        $isId          = $search !== '' && ctype_digit($search);
        $blockedFilter = (string) $request->string('blocked', 'none');

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        // eager-load parent — раньше был N+1 (loadMissing в каждом formatItem).
        $query = GeoIPLocation::query()->with('parent');

        // При поиске по ID: не фильтруем по type и не применяем blocked-фильтр,
        // сортируем нужный первым (нужно найти элемент, независимо от статуса).
        if ($isId) {
            $query->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
        } else {
            $query->where('type', $type);

            if ($blockedFilter === 'reg') {
                $query->where('is_registration_blocked', true);
            } elseif ($blockedFilter === 'feed') {
                $query->where('is_feed_blocked', true);
            }
        }

        $query->when($search !== '', function ($q) use ($search, $searchOperator, $isId) {
            $q->where(function ($q) use ($search, $searchOperator, $isId) {
                $q->where('name', $searchOperator, "%{$search}%")
                    ->orWhere('name_ru', $searchOperator, "%{$search}%")
                    ->orWhere('iso_code', $searchOperator, "%{$search}%");
                if ($isId) {
                    $q->orWhere('id', (int) $search);
                }
            });
        });

        $items = $query
            ->orderBy('name')
            ->paginate($perPage);

        // При поиске по ID — берём type первого найденного, чтобы фронт переключил таб.
        $actualType = $type;
        if ($isId && $items->isNotEmpty()) {
            $actualType = $items->first()->type;
        }

        return response()->json([
            'data' => GeoIPLocationResource::collection($items->getCollection())->resolve(request()),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'per_page'     => $items->perPage(),
                'total'        => $items->total(),
                'from'         => $items->firstItem(),
                'to'           => $items->lastItem(),
            ],
            'counts' => $this->getCounts(),
            'type'   => $actualType,
        ]);
    }

    public function counts(): JsonResponse
    {
        return response()->json(['data' => $this->getCounts()]);
    }

    /**
     * Переключить блокировку регистрации для локации.
     */
    public function toggleRegistration(Request $request, GeoIPLocation $location): JsonResponse
    {
        $admin = $request->user();
        $before = ['is_registration_blocked' => $location->getOriginal('is_registration_blocked')];

        $location->update(['is_registration_blocked' => !$location->is_registration_blocked]);

        AdminLog::record('geo.toggle_registration', $location, $admin, $before, [
            'is_registration_blocked' => $location->is_registration_blocked,
            'context' => [
                'location_id'   => $location->id,
                'location_name' => $location->name_ru ?: $location->name,
                'location_type' => $location->type,
                'admin_id'      => $admin->id,
            ],
        ]);

        Cache::forget('admin_geo_counts');

        return $this->message(
            $location->is_registration_blocked ? 'Регистрация заблокирована' : 'Регистрация разрешена',
            200,
            ['is_registration_blocked' => $location->is_registration_blocked],
        );
    }

    /**
     * Переключить блокировку ленты для локации.
     */
    public function toggleFeed(Request $request, GeoIPLocation $location): JsonResponse
    {
        $admin = $request->user();
        $before = ['is_feed_blocked' => $location->getOriginal('is_feed_blocked')];

        $location->update(['is_feed_blocked' => !$location->is_feed_blocked]);

        AdminLog::record('geo.toggle_feed', $location, $admin, $before, [
            'is_feed_blocked' => $location->is_feed_blocked,
            'context' => [
                'location_id'   => $location->id,
                'location_name' => $location->name_ru ?: $location->name,
                'location_type' => $location->type,
                'admin_id'      => $admin->id,
            ],
        ]);

        Cache::forget('admin_geo_counts');

        return $this->message(
            $location->is_feed_blocked ? 'Локация скрыта из ленты' : 'Локация видна в ленте',
            200,
            ['is_feed_blocked' => $location->is_feed_blocked],
        );
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getCounts(): array
    {
        return Cache::remember('admin_geo_counts', 60, function () {
            return [
                'country' => [
                    'total'        => GeoIPLocation::where('type', 'country')->count(),
                    'blocked_reg'  => GeoIPLocation::where('type', 'country')->where('is_registration_blocked', true)->count(),
                    'blocked_feed' => GeoIPLocation::where('type', 'country')->where('is_feed_blocked', true)->count(),
                ],
                'region' => [
                    'total'        => GeoIPLocation::where('type', 'region')->count(),
                    'blocked_reg'  => GeoIPLocation::where('type', 'region')->where('is_registration_blocked', true)->count(),
                    'blocked_feed' => GeoIPLocation::where('type', 'region')->where('is_feed_blocked', true)->count(),
                ],
                'city' => [
                    'total'        => GeoIPLocation::where('type', 'city')->count(),
                    'blocked_reg'  => GeoIPLocation::where('type', 'city')->where('is_registration_blocked', true)->count(),
                    'blocked_feed' => GeoIPLocation::where('type', 'city')->where('is_feed_blocked', true)->count(),
                ],
            ];
        });
    }
} 