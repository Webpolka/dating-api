<?php

namespace App\Http\Controllers\Api\V1\Web;

use App\Http\Controllers\Controller;
use App\Services\V1\UserOnlineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class HeartbeatController extends Controller
{
    public function __construct(
        private readonly UserOnlineService $onlineService
    ) {}

    /**
     * POST /api/heartbeat
     *
     * Frontend пингует этот endpoint каждые 30 секунд.
     * Мы обновляем Redis-статус юзера как "онлайн".
     *
     * Throttling уже встроен в UserOnlineService (60 сек),
     * но добавим rate limiting на уровне роута для подстраховки.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        // Обновляем онлайн-статус в Redis (с throttling 60 сек)
        $this->onlineService->markOnline($user->id);

        // ВАЖНО: last_seen в БД обновляется через middleware (с throttling 5 мин),
        // не здесь — чтобы избежать дублирования логики.

        Log::debug('💓 [Heartbeat] Ping received', [
            'user_id' => $user->id,
        ]);

        return response()->json([
            'status' => 'ok',
            'online' => true,
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
