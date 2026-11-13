<?php

namespace App\Http\Controllers\Admin\V1\System;

use App\Actions\Admin\System\ManageStaffAction;
use App\Http\Controllers\AdminController;
use App\Http\Requests\Admin\V1\System\BatchUpdateRolesRequest;
use App\Http\Requests\Admin\V1\System\PromoteUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends AdminController
{
    /**
     * GET /api/admin/staff
     * Список текущих сотрудников (с пагинацией)
     */
    public function index(Request $request): JsonResponse
    {
        $founders = config('app.founders', []);

        // Сортировка: сначала founders, потом по ID
        $founderCase = !empty($founders)
            ? "CASE WHEN id IN (" . implode(',', array_map('intval', $founders)) . ") THEN 0 ELSE 1 END"
            : "1";

        $staff = User::withTrashed()
            ->whereNot('role', User::ROLE_USER)
            ->with(['photos' => fn($q) => $q->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb'])->orderByDesc('is_primary')->limit(1)])
            ->orderByRaw($founderCase)
            ->orderBy('id', 'asc')
            ->paginate(20);

        // Форматируем данные для фронтенда
        $staff->getCollection()->transform(function (User $user) use ($request, $founders) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                // B3: отдаём ISO — фронт сам отформатирует через formatDateTime.
                // Это дата СОЗДАНИЯ юзера (registration), а не "дата назначения"
                // (роль может меняться много раз). Подпись в UI — "Дата регистрации".
                'created_at' => $user->created_at?->toIso8601String(),
                'avatar_url' => $user->avatar_url,
                'is_online' => $user->is_online,
                'is_founder' => in_array($user->id, $founders, true),
                'is_self' => $user->id === $request->user()->id,
                // Поля для UserBadge
                'status' => $user->status,
                'deleted_at' => $user->deleted_at,
                'has_active_premium' => $user->has_active_premium,
                'has_active_vip' => $user->has_active_vip,
                'is_verified' => $user->is_verified,
            ];
        });

        return response()->json($staff);
    }

    /**
     * GET /api/admin/staff/search?search=...
     * Поиск обычных юзеров для повышения
     */
    public function search(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('search', ''));

        if (empty($search)) {
            return response()->json(['data' => []]);
        }

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isId = ctype_digit($search);

        $candidates = User::excludeStaff()
            ->where(function ($q) use ($searchOperator, $search, $isId) {
                if ($isId) {
                    $q->where('id', (int) $search);
                } else {
                    $q->where('name', $searchOperator, "%{$search}%")
                        ->orWhere('email', $searchOperator, "%{$search}%");
                }
            })
            ->with(['photos' => fn($q) => $q->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb'])->orderByDesc('is_primary')->limit(1)])
            ->withTrashed()
            ->limit(10)
            ->get(['id', 'name', 'email', 'status', 'premium_expires_at', 'vip_expires_at', 'last_seen', 'is_verified', 'deleted_at']);

        $candidates->transform(function (User $user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar_url' => $user->avatar_url,
                'is_online' => $user->is_online,
                'status' => $user->status,
                'deleted_at' => $user->deleted_at,
                'has_active_premium' => $user->has_active_premium,
                'has_active_vip' => $user->has_active_vip,
                'is_verified' => $user->is_verified,
            ];
        });

        return response()->json(['data' => $candidates]);
    }

    /**
     * POST /api/admin/staff/{userId}/promote
     *
     * Аудит (кто кого повысил до какой роли) — в ManageStaffAction::promote().
     */
    public function promote(PromoteUserRequest $request, int $userId, ManageStaffAction $action): JsonResponse
    {
        $admin = $request->user();
        $user = User::withTrashed()->find($userId);
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден.'], 404);
        }

        $founders = config('app.founders', []);
        if (in_array($user->id, $founders, true)) {
            return response()->json(['message' => 'Владельцев проекта нельзя изменять!'], 403);
        }

        $newRole = $request->input('role');

        $action->promote($user, $admin, $newRole);

        return response()->json([
            'message' => "{$user->name} повышен до «{$newRole}»!",
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'role' => $user->role,
            ]
        ]);
    }

    /**
     * POST /api/admin/staff/{userId}/demote
     *
     * Аудит — в ManageStaffAction::demote().
     */
    public function demote(Request $request, int $userId, ManageStaffAction $action): JsonResponse
    {
        $admin = $request->user();
        $user = User::withTrashed()->find($userId);
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден.'], 404);
        }

        $founders = config('app.founders', []);
        if (in_array($user->id, $founders, true)) {
            return response()->json(['message' => 'Владельцев проекта нельзя разжаловать!'], 403);
        }

        if ($user->id === $admin->id) {
            return response()->json(['message' => 'Нельзя разжаловать самого себя!'], 403);
        }

        $action->demote($user, $admin);

        return response()->json([
            'message' => "{$user->name} разжалован в обычные юзеры."
        ]);
    }

    /**
     * PATCH /api/admin/staff/batch-update
     * Массовое сохранение ролей
     *
     * B2: транзакция + аудит (по одной записи на изменение) —
     * в ManageStaffAction::batchUpdate().
     */
    public function batchUpdate(BatchUpdateRolesRequest $request, ManageStaffAction $action): JsonResponse
    {
        $updatedCount = $action->batchUpdate($request->input('roles', []), $request->user());

        if ($updatedCount > 0) {
            return response()->json([
                'message' => "Успешно сохранено! Ролей изменено: {$updatedCount}",
                'updated_count' => $updatedCount
            ]);
        }

        return response()->json([
            'message' => 'Изменений для сохранения нет.',
            'updated_count' => 0
        ]);
    }
}
