<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Http\Controllers\Api\V1\Admin\AdminController;
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
 * Логируется в AdminLog (B4): кто заблокировал/разблокировал, какую локацию.
 */
class GeoIPLocationController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        // B2: убрал избыточный ->toString()
        $type = (string) $request->string('type', 'country');
        $search = (string) $request->string('q', '');
        $perPage = min(max((int) $request->integer('per_page', 50), 10), 200);
        $isId = $search !== '' && ctype_digit($search);
        $blockedFilter = (string) $request->string('blocked', 'none');

        // B3: оператор поиска через config, не хардкод 'ilike'
        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        // B1: eager-load parent — раньше был N+1 (loadMissing в каждом formatItem)
        $query = GeoIPLocation::query()->with('parent');

        // При поиске по ID: не фильтруем по type и не применяем blocked-фильтр,
        // сортируем нужный первым (нужно найти элемент, независимо от статуса)
        if ($isId) {
            $query->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
        } else {
            // Обычный режим: строго по выбранному типу
            $query->where('type', $type);

            // Применяем фильтр блокировки (только не при поиске по ID)
            if ($blockedFilter === 'reg') {
                $query->where('is_registration_blocked', true);
            } elseif ($blockedFilter === 'feed') {
                $query->where('is_feed_blocked', true);
            }
        }

        // Поиск
        if ($search !== '') {
            $query->where(function ($q) use ($search, $searchOperator, $isId) {
                $q->where('name', $searchOperator, "%{$search}%")
                    ->orWhere('name_ru', $searchOperator, "%{$search}%")
                    ->orWhere('iso_code', $searchOperator, "%{$search}%");
                if ($isId) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        $items = $query
            ->orderBy('name')
            ->paginate($perPage);

        // При поиске по ID — берём type первого найденного, чтобы фронт переключил таб
        $actualType = $type;
        if ($isId && $items->isNotEmpty()) {
            $actualType = $items->first()->type;
        }

        return response()->json([
            'data' => $items->map(fn(GeoIPLocation $loc) => $this->formatItem($loc)),
            'meta' => [
                'current_page' => $items->currentPage(),
                'last_page'    => $items->lastPage(),
                'per_page'     => $items->perPage(),
                'total'        => $items->total(),
                'from'         => $items->firstItem(),
                'to'           => $items->lastItem(),
            ],
            'counts' => $this->getCounts(),
            'type' => $actualType,
        ]);
    }

    public function counts(): JsonResponse
    {
        return response()->json(['data' => $this->getCounts()]);
    }

    /**
     * Переключить блокировку регистрации для локации.
     *
     * B4: логируется в AdminLog — кто заблокировал/разблокировал, какую локацию.
     */
    public function toggleRegistration(Request $request, GeoIPLocation $location): JsonResponse
    {
        $admin = $request->user();
        $before = ['is_registration_blocked' => $location->getOriginal('is_registration_blocked')];

        $location->update(['is_registration_blocked' => !$location->is_registration_blocked]);

        AdminLog::record('geo.toggle_registration', $location, $admin, $before, [
            'is_registration_blocked' => $location->is_registration_blocked,
            'context' => [
                'location_id' => $location->id,
                'location_name' => $location->name_ru ?: $location->name,
                'location_type' => $location->type,
                'admin_id' => $admin->id,
            ],
        ]);

        Cache::forget('admin_geo_counts');

        return response()->json([
            'message' => $location->is_registration_blocked
                ? 'Регистрация заблокирована'
                : 'Регистрация разрешена',
            'is_registration_blocked' => $location->is_registration_blocked,
        ]);
    }

    /**
     * Переключить блокировку ленты для локации.
     *
     * B4: логируется в AdminLog.
     */
    public function toggleFeed(Request $request, GeoIPLocation $location): JsonResponse
    {
        $admin = $request->user();
        $before = ['is_feed_blocked' => $location->getOriginal('is_feed_blocked')];

        $location->update(['is_feed_blocked' => !$location->is_feed_blocked]);

        AdminLog::record('geo.toggle_feed', $location, $admin, $before, [
            'is_feed_blocked' => $location->is_feed_blocked,
            'context' => [
                'location_id' => $location->id,
                'location_name' => $location->name_ru ?: $location->name,
                'location_type' => $location->type,
                'admin_id' => $admin->id,
            ],
        ]);

        Cache::forget('admin_geo_counts');

        return response()->json([
            'message' => $location->is_feed_blocked
                ? 'Локация скрыта из ленты'
                : 'Локация видна в ленте',
            'is_feed_blocked' => $location->is_feed_blocked,
        ]);
    }

    /**
     * Форматирование элемента для API.
     *
     * B1: parent уже загружен через with('parent') в index() —
     * loadMissing больше не нужен (был N+1 на 50 элементов).
     */
    private function formatItem(GeoIPLocation $loc): array
    {
        $parentName = null;
        if ($loc->parent_id && $loc->parent) {
            $parentName = $loc->parent->name_ru ?: $loc->parent->name;
        }

        return [
            'id'                     => $loc->id,
            'type'                   => $loc->type,
            'name'                   => $loc->name_ru ?: $loc->name,
            'original_name'          => $loc->name,
            'iso_code'               => $loc->iso_code,
            'latitude'               => $loc->latitude,
            'longitude'              => $loc->longitude,
            'parent_name'            => $parentName,
            'is_registration_blocked' => $loc->is_registration_blocked,
            'is_feed_blocked'        => $loc->is_feed_blocked,
        ];
    }

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
