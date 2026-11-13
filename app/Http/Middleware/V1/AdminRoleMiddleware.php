<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Единый middleware для защиты админ-роутов.
 *
 * Использование:
 * 1. Route::middleware(['auth:sanctum', 'admin.role'])
 *    — пускает всех staff (admin, moderator, support)
 * 2. Route::middleware(['auth:sanctum', 'admin.role:admin'])
 *    — пускает ТОЛЬКО admin
 * 3. Route::middleware(['auth:sanctum', 'admin.role:admin,moderator'])
 *    — пускает admin и moderator
 *
 * ------------------------------------------------------------
 * ПРО FOUNDER'ОВ (Вариант B):
 * ------------------------------------------------------------
 * Founders (id из APP_FOUNDERS) — это просто admin-аккаунты с
 * дополнительной защитой от случайной потери доступа:
 *   - их нельзя забанить или понизить через админку
 *     (см. StaffController::promote/demote/batchUpdate)
 *   - при банах через другие контроллеры они защищены
 *     (см. ToggleUserBanAction)
 *
 * Founder НЕ обходит middleware. Если у founder'а role=user или
 * status=banned — он НЕ получит доступ к админке. Это устраняет
 * скрытый бэкдор: компрометация founder-аккаунта с пониженной
 * ролью больше не даёт полного доступа.
 *
 * Чтобы восстановить доступ founder'у, если его случайно понизили:
 *   UPDATE users SET role = 'admin', status = 'active'
 *   WHERE id IN (...founder ids...);
 * через tinker/SQL напрямую в БД.
 */
class AdminRoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Неавторизован.'], 401);
        }

        // Если аккаунт забанен — выкидываем и убиваем сессию.
        // Это закрывает дыру: если admin'а забанили ПОСЛЕ логина,
        // его сессия перестаёт работать сразу, а не по истечению
        // SESSION_LIFETIME. Проверка делается здесь, в middleware,
        // а не только в AuthController::login.
        if ($user->isBanned()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'message' => 'Аккаунт заблокирован. Обратитесь к владельцу.',
            ], 401);
        }

        // Если роли не переданы в параметрах (admin.role) — пускаем любого staff
        if (empty($roles)) {
            if (!$user->isStaff()) {
                return response()->json([
                    'message' => 'Доступ запрещён. Требуется роль сотрудника.',
                ], 403);
            }
            return $next($request);
        }

        // Если роли переданы (admin.role:admin) — проверяем строго по списку
        if (!in_array($user->role, $roles, true)) {
            return response()->json([
                'message' => 'Доступ запрещён. Требуется роль: ' . implode(', ', $roles),
            ], 403);
        }

        return $next($request);
    }
}
