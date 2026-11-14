<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Resources\Api\V1\Admin\System\AdminLogResource;
use App\Models\AdminLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Журнал аудита (AdminLog) — кто что делал в админке.
 *
 * Read-only endpoint: index + refresh (cache invalidate).
 *
 * Фильтрация по:
 *  - cat (категория action) — префикс до точки или underscore
 *  - adm (admin_id)
 *  - date (одна конкретная дата)
 *  - q (поиск по action/ip/loggable_type, или по ID — точное совпадение)
 *
 * Computed-поля (в Resource):
 *  - object_type — class_basename(loggable_type) или "Система"/"Массовая операция"
 *  - object_url — URL для клика в админке (по мапе роутов Next.js)
 *  - is_deleted / is_system — флаги для UI (показать разные иконки/цвета)
 *
 * Cache: 3 ключа на 60 сек (stats / admins / total_entries). refresh() чистит все.
 */
class AdminLogController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $search   = $request->string('q', '')->toString();
        $category = $request->string('cat', 'all')->toString();
        $adminId  = $request->string('adm', '')->toString();
        $date     = $request->string('date', '')->toString();
        $perPage  = min(max((int) $request->integer('per_page', 15), 1), 100);
        $isId     = $search !== '' && ctype_digit($search);

        $logs = AdminLog::with([
            'admin'    => fn($q) => $q->select(['id', 'name', 'email', 'role', 'last_seen'])->withAvatar(),
            'loggable' => fn($q) => $q->withTrashed(),
        ])
            // Фильтр по категории (префикс action).
            ->when($category !== 'all', function ($q) use ($category) {
                if ($category === 'blog_category') {
                    $q->where('action', 'like', 'blog_category.%');
                } else {
                    $q->where(function ($q) use ($category) {
                        $q->where('action', 'like', $category . '.%')
                            ->orWhere('action', 'like', $category . '_%');
                    });
                }
            })
            ->when(!empty($adminId), fn($q) => $q->where('admin_id', (int) $adminId))
            ->when($date, fn($q) => $q
                ->where('created_at', '>=', \Carbon\Carbon::parse($date)->startOfDay())
                ->where('created_at', '<=', \Carbon\Carbon::parse($date)->endOfDay()))
            ->when($search !== '', function ($q) use ($search, $isId) {
                $q->where(function ($q) use ($search, $isId) {
                    $q->where('action', 'ilike', "%{$search}%")
                        ->orWhere('ip_address', 'ilike', "%{$search}%")
                        ->orWhere('loggable_type', 'ilike', "%{$search}%");
                    if ($isId) {
                        $q->orWhere('id', (int) $search)
                            ->orWhere('loggable_id', (int) $search);
                    }
                });
            })
            ->when($isId, fn($q) => $q->orderByRaw(
                'CASE WHEN id = ? THEN 0 WHEN loggable_id = ? THEN 1 ELSE 2 END',
                [(int) $search, (int) $search],
            ))
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'data'          => AdminLogResource::collection($logs->getCollection())->resolve(request()),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'per_page'     => $logs->perPage(),
                'total'        => $logs->total(),
                'from'         => $logs->firstItem(),
                'to'           => $logs->lastItem(),
            ],
            'stats'         => $this->getStats(),
            'admins'        => $this->getAdmins(),
            'total_entries' => $this->getTotalEntries(),
        ]);
    }

    /**
     * POST /api/admin/system/logs/refresh — инвалидация кэшей stats/admins/total.
     * Фронт дёргает после mutation (например, после выполнения действия которое
     * создаёт новую AdminLog запись), чтобы обновить счётчики.
     */
    public function refresh(): JsonResponse
    {
        Cache::forget('admin_audit_category_stats');
        Cache::forget('admin_audit_admins_list');
        Cache::forget('admin_audit_total_entries');

        return response()->json([
            'stats'         => $this->getStats(),
            'admins'        => $this->getAdmins(),
            'total_entries' => $this->getTotalEntries(),
        ]);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getStats(): array
    {
        return Cache::remember('admin_audit_category_stats', 60, function () {
            $driver = config('database.default');
            $expression = $driver === 'pgsql'
                ? "SPLIT_PART(action, '.', 1)"
                : "SUBSTRING_INDEX(action, '.', 1)";

            $stats = AdminLog::selectRaw("{$expression} as category, COUNT(*) as count")
                ->groupBy('category')
                ->orderBy('category')
                ->pluck('count', 'category')
                ->toArray();

            $result = ['all' => array_sum($stats)];
            foreach ($stats as $key => $value) {
                $result[$key] = $value;
            }

            return $result;
        });
    }

    private function getAdmins(): array
    {
        return Cache::remember('admin_audit_admins_list', 60, function () {
            return User::whereHas('adminLogs')
                ->select('id', 'name')
                ->orderBy('name')
                ->get()
                ->map(fn($u) => ['id' => $u->id, 'name' => $u->name])
                ->toArray();
        });
    }

    private function getTotalEntries(): int
    {
        return Cache::remember('admin_audit_total_entries', 60, function () {
            return AdminLog::count();
        });
    }
}
