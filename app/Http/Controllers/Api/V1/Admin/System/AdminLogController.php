<?php

namespace App\Http\Controllers\Admin\V1\System;

use App\Http\Controllers\AdminController;
use App\Models\AdminLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class AdminLogController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $search = $request->string('q', '')->toString();
        $category = $request->string('cat', 'all')->toString();
        $adminId = $request->string('adm', '')->toString();
        $date = $request->string('date', '')->toString();
        $perPage = min(max((int) $request->integer('per_page', 15), 1), 100);
        $isId = $search !== '' && ctype_digit($search);

        $avatarQuery = fn($q) => $q->select(['user_id', 'is_primary', 'path_thumb', 'path_medium'])
            ->orderByDesc('is_primary')
            ->limit(1);

        $query = AdminLog::with([
            'admin' => fn($q) => $q->select(['id', 'name', 'email', 'role', 'last_seen'])->with(['photos' => $avatarQuery]),
            'loggable' => fn($q) => $q->withTrashed()
        ]);

        // Фильтр по категории
        if ($category !== 'all') {
            if ($category === 'blog_category') {
                $query->where('action', 'like', 'blog_category.%');
            } else {
                $query->where(function ($q) use ($category) {
                    $q->where('action', 'like', $category . '.%')
                        ->orWhere('action', 'like', $category . '_%');
                });
            }
        }

        // Фильтр по админу
        if (!empty($adminId)) {
            $query->where('admin_id', (int) $adminId);
        }

        // Фильтр по дате
        if ($date) {
            $query->where('created_at', '>=', \Carbon\Carbon::parse($date)->startOfDay())
                ->where('created_at', '<=', \Carbon\Carbon::parse($date)->endOfDay());
        }

        // Поиск
        $query->when($search !== '', function ($q) use ($search, $isId) {
            $q->where(function ($q) use ($search, $isId) {
                $q->where('action', 'ilike', "%{$search}%")
                    ->orWhere('ip_address', 'ilike', "%{$search}%")
                    ->orWhere('loggable_type', 'ilike', "%{$search}%");
                if ($isId) {
                    $q->orWhere('id', (int) $search)
                        ->orWhere('loggable_id', (int) $search);
                }
            });
        });

        // ✅ Сортировка: при поиске по ID — нужный лог первым
        $query->when($isId, function ($q) use ($search) {
            $q->orderByRaw('CASE WHEN id = ? THEN 0 WHEN loggable_id = ? THEN 1 ELSE 2 END', [(int) $search, (int) $search]);
        })
            ->latest('created_at')
            ->latest('id');

        $logs = $query->paginate($perPage);

        return response()->json([
            'data' => $logs->map(fn(AdminLog $log) => $this->formatLog($log)),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
                'per_page'     => $logs->perPage(),
                'total'        => $logs->total(),
                'from'         => $logs->firstItem(),
                'to'           => $logs->lastItem(),
            ],
            'stats' => $this->getStats(),
            'admins' => $this->getAdmins(),
            'total_entries' => $this->getTotalEntries(),
        ]);
    }

    public function refresh(): JsonResponse
    {
        Cache::forget('admin_audit_category_stats');
        Cache::forget('admin_audit_admins_list');
        Cache::forget('admin_audit_total_entries');

        return response()->json([
            'stats' => $this->getStats(),
            'admins' => $this->getAdmins(),
            'total_entries' => $this->getTotalEntries(),
        ]);
    }

    private function formatLog(AdminLog $log): array
    {
        $admin = $log->admin;
        $loggable = $log->loggable;

        // Определяем тип объекта для отображения в журнале.
        // Для bulk-операций без loggable показываем «Массовая операция» вместо «Система».
        $isBulkOperation = !$log->loggable_type
            && !$log->loggable_id
            && str_starts_with($log->action, 'photo_comment.mass_');

        $type = $isBulkOperation
            ? 'Массовая операция'
            : ($log->loggable_type ? class_basename($log->loggable_type) : 'Система');

        $isSystemAction = in_array($log->loggable_type, ['settings', null]) || !$log->loggable_id;
        $isDeleted = !$isSystemAction && is_null($loggable);

        return [
            'id'             => $log->id,
            'created_at'     => $log->created_at?->toIso8601String(),
            'action'         => $log->action,
            'ip_address'     => $log->ip_address,
            'before'         => $log->before,
            'after'          => $log->after,
            'object_type'    => $type,
            'object_id'      => $log->loggable_id,
            'is_deleted'     => $isDeleted,
            'is_system'      => $isSystemAction,
            'object_url'     => $this->getObjectUrl($log),
            'admin' => $admin ? [
                'id'         => $admin->id,
                'name'       => $admin->name,
                'email'      => $admin->email,
                'role'       => $admin->role,
                'is_online'  => $admin->is_online,
                'last_seen'  => $admin->last_seen?->toIso8601String(),
                'avatar_url' => $admin->avatar_url,
                'status'     => $admin->status ?? 'active',
                'deleted_at' => $admin->deleted_at ?? null,
                'has_active_premium' => $admin->has_active_premium ?? false,
                'has_active_vip' => $admin->has_active_vip ?? false,
                'is_verified' => $admin->is_verified ?? false,
            ] : null,
        ];
    }

    private function getObjectUrl(AdminLog $log): ?string
    {
        $modelClass = $log->loggable_type;
        $id = $log->loggable_id;
        $action = $log->action;

        if (!$modelClass || !$id) return null;

        // ✅ Спец-логика для Chat: различаем по action
        if ($modelClass === \App\Models\Chat::class) {
            if (str_starts_with($action, 'support.')) {
                return "/communication/support?q={$id}";
            }
            return "/communication/chats?q={$id}";
        }

        // ✅ Спец-логика для модерации PhotoComment.
        // Single actions (approve/reject/soft_delete/restore/destroy) — клик ведёт на коммент,
        // в таб, соответствующий финальному статусу.
        if ($modelClass === \App\Models\PhotoComment::class) {
            $status = match (true) {
                str_starts_with($action, 'photo_comment.approve')               => 'approved',
                str_starts_with($action, 'photo_comment.reject')                => 'rejected',
                str_starts_with($action, 'photo_comment.soft_delete')           => 'karantine',
                str_starts_with($action, 'photo_comment.restore')               => 'pending',
                str_starts_with($action, 'photo_comment.destroy')               => 'all',
                default                                                          => 'all',
            };
            return "/moderation/photo-comments?status={$status}&q={$id}";
        }

        // Мапа роутов Next.js (путь => параметр поиска => доп. query-параметры)
        $routeMap = [
            \App\Models\User::class           => ['/admin/users', 'q'],
            \App\Models\Page::class           => ['/system/pages', 'q'],
            \App\Models\BlogPost::class       => ['/system/blog/posts', 'q'],
            \App\Models\BlogCategory::class   => ['/system/blog/posts', 'q'],
            \App\Models\Media::class          => ['/system/media', 'q'],
            \App\Models\GeoIPLocation::class  => ['/system/geo', 'q'],
            \App\Models\Setting::class        => ['/system/settings', null],
            \App\Models\Broadcast::class      => ['/system/broadcasts', 'q'],
            \App\Models\StopWord::class       => ['/communication/stop-words', 'q'],
            \App\Models\SupportTemplate::class => ['/communication/support-templates', 'q'],
            \App\Models\Gift::class           => ['/finance/gifts', 'q', ['tab' => 'catalog']],
            \App\Models\UserGift::class       => ['/finance/gifts', 'q', ['tab' => 'history']],
            \App\Models\Transaction::class    => ['/finance/transactions', 'q'],
            \App\Models\SubscriptionPlan::class    => ['/finance/subscription-plans', 'q'],
            // ✅ Фото — для bulk-операций по фото (mass_approve/mass_reject с loggable=Photo).
            // Бэкенд PhotoController умеет авто-detect статуса при поиске по ID.
            \App\Models\Photo::class          => ['/moderation/photos', 'q'],
        ];

        if (!isset($routeMap[$modelClass])) {
            return null;
        }

        $entry = $routeMap[$modelClass];
        $path = $entry[0];
        $paramName = $entry[1] ?? null;
        $extraParams = $entry[2] ?? [];

        if ($paramName === null) {
            return $path;
        }

        $params = array_merge($extraParams, [$paramName => $id]);
        return $path . '?' . http_build_query($params);
    }

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
