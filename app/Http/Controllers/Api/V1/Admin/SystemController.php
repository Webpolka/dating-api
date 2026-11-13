<?php

namespace App\Http\Controllers\Admin\V1\System;

use App\Http\Controllers\AdminController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class SystemController extends AdminController
{
    /**
     * POST /api/admin/system/clear-cache
     * Очистка серверного кэша (Redis) — только для админов
     */
    public function clearCache(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Двойная проверка (middleware уже проверил, но подстрахуемся)
        if ($user->role !== 'admin' && !in_array($user->id, config('app.founders', []))) {
            return response()->json(['message' => 'Доступ запрещён.'], 403);
        }

        try {
            // 1. Очищаем конкретные ключи админки (точечно)
            Cache::forget('admin_dashboard_live_v2');
            Cache::forget('admin_dashboard_metrics_v8');
            Cache::forget('admin_dashboard_activity_v1');
            Cache::forget('admin_sidebar_stats');

            // 2. Полная очистка всего Redis-кэша через Artisan
            Artisan::call('cache:clear');

            Log::info('Кэш Redis очищен администратором', [
                'admin_id' => $user->id,
                'admin_name' => $user->name,
            ]);

            return response()->json([
                'message' => 'Кэш Redis успешно очищен!',
            ]);
        } catch (\Exception $e) {
            Log::error('Ошибка очистки кэша', [
                'error' => $e->getMessage(),
                'admin_id' => $user->id,
            ]);

            return response()->json([
                'message' => 'Ошибка при очистке кэша: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/admin/system/clear-search-cache
     * Очистка кэша поиска анкет (Redis)
     */
    public function clearSearchCache(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->role !== 'admin' && !in_array($user->id, config('app.founders', []))) {
            return response()->json(['message' => 'Доступ запрещён.'], 403);
        }

        try {
            $deleted = 0;

            // Ищем все ключи feed_search:* и удаляем через SCAN
            $redis = Cache::getRedis();
            $iterator = null;

            while ($keys = $redis->scan($iterator, 'feed_search:*', 100)) {
                if (!empty($keys)) {
                    $redis->del($keys);
                    $deleted += count($keys);
                }
                if ($iterator === 0) break;
            }

            Log::info('Кэш поиска очищен администратором', [
                'admin_id' => $user->id,
                'keys_deleted' => $deleted,
            ]);

            return response()->json([
                'message' => "Кэш поиска очищен! Удалено ключей: {$deleted}",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Ошибка: ' . $e->getMessage(),
            ], 500);
        }
    }
}
