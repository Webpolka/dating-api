<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Actions\V1\Admin\System\ManageStaffAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\System\BatchUpdateRolesRequest;
use App\Http\Requests\Api\V1\Admin\System\PromoteUserRequest;
use App\Http\Resources\Api\V1\Admin\System\StaffResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Управление сотрудниками админки (admin / moderator / support).
 *
 * Three operations:
 *  - index — список текущих staff (с пагинацией, founders первыми).
 *  - search — поиск обычных юзеров для повышения (autocomplete).
 *  - promote/demote/batchUpdate — изменение ролей.
 *
 * Founders (id из APP_FOUNDERS) — защищены: их нельзя разжаловать или повысить
 * (они уже admin). Founder ≠ role=admin — founder это admin-аккаунт с доп. защитой.
 *
 * Audit (кто кого повысил/понизил до какой роли) — в ManageStaffAction.
 */
class StaffController extends AdminController
{
    /**
     * GET /api/admin/staff — список сотрудников (с пагинацией).
     */
    public function index(Request $request): JsonResponse
    {
        $founders = config('app.founders', []);

        // Сортировка: сначала founders, потом по ID.
        $founderCase = !empty($founders)
            ? "CASE WHEN id IN (" . implode(',', array_map('intval', $founders)) . ") THEN 0 ELSE 1 END"
            : "1";

        $staff = User::withTrashed()
            ->whereNot('role', User::ROLE_USER)
            ->withAvatar()
            ->orderByRaw($founderCase)
            ->orderBy('id', 'asc')
            ->paginate(20);

        // is_self вычисляется в Resource через request()->user()->id — не надо
        // transform здесь. Resource::collection сам вызовет toArray с request.

        return response()->json([
            'data' => StaffResource::collection($staff->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $staff->currentPage(),
                'last_page'    => $staff->lastPage(),
                'per_page'     => $staff->perPage(),
                'total'        => $staff->total(),
                'from'         => $staff->firstItem(),
                'to'           => $staff->lastItem(),
            ],
        ]);
    }

    /**
     * GET /api/admin/staff/search?search=... — поиск обычных юзеров для повышения.
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
            ->when($isId, fn($q) => $q->where('id', (int) $search))
            ->when(!$isId, fn($q) => $q->where(function ($q) use ($searchOperator, $search) {
                $q->where('name', $searchOperator, "%{$search}%")
                    ->orWhere('email', $searchOperator, "%{$search}%");
            }))
            ->withAvatar()
            ->withTrashed()
            ->limit(10)
            ->get();

        // В search нет is_founder/is_self — это не staff, а кандидаты.
        // Возвращаем упрощённый inline-формат (как в старом коде).
        return response()->json([
            'data' => $candidates->map(fn(User $user) => [
                'id'                 => $user->id,
                'name'               => $user->name,
                'email'              => $user->email,
                'avatar_url'         => $user->avatar_url,
                'is_online'          => $user->is_online,
                'status'             => $user->status,
                'deleted_at'         => $user->deleted_at?->toIso8601String(),
                'has_active_premium' => $user->has_active_premium ?? false,
                'has_active_vip'     => $user->has_active_vip ?? false,
                'is_verified'        => $user->is_verified ?? false,
            ]),
        ]);
    }

    /**
     * POST /api/admin/staff/{userId}/promote — повысить юзера до staff-роли.
     *
     * Audit (кто кого повысил до какой роли) — в ManageStaffAction::promote().
     */
    public function promote(PromoteUserRequest $request, int $userId, ManageStaffAction $action): JsonResponse
    {
        $admin = $request->user();
        $user = User::withTrashed()->find($userId);
        if (!$user) {
            return $this->notFound('Пользователь');
        }

        $founders = config('app.founders', []);
        if (in_array($user->id, $founders, true)) {
            return $this->message('Владельцев проекта нельзя изменять!', 403);
        }

        $newRole = $request->input('role');

        $action->promote($user, $admin, $newRole);

        return $this->message("{$user->name} повышен до «{$newRole}»!", 200, [
            'user' => [
                'id'   => $user->id,
                'name' => $user->name,
                'role' => $user->role,
            ],
        ]);
    }

    /**
     * POST /api/admin/staff/{userId}/demote — разжаловать staff в обычные юзеры.
     *
     * Audit — в ManageStaffAction::demote().
     */
    public function demote(Request $request, int $userId, ManageStaffAction $action): JsonResponse
    {
        $admin = $request->user();
        $user = User::withTrashed()->find($userId);
        if (!$user) {
            return $this->notFound('Пользователь');
        }

        $founders = config('app.founders', []);
        if (in_array($user->id, $founders, true)) {
            return $this->message('Владельцев проекта нельзя разжаловать!', 403);
        }

        if ($user->id === $admin->id) {
            return $this->message('Нельзя разжаловать самого себя!', 403);
        }

        $action->demote($user, $admin);

        return $this->message("{$user->name} разжалован в обычные юзеры.");
    }

    /**
     * PATCH /api/admin/staff/batch-update — массовое сохранение ролей.
     *
     * B2: транзакция + аудит (по одной записи на изменение) — в ManageStaffAction::batchUpdate().
     */
    public function batchUpdate(BatchUpdateRolesRequest $request, ManageStaffAction $action): JsonResponse
    {
        $updatedCount = $action->batchUpdate($request->input('roles', []), $request->user());

        if ($updatedCount > 0) {
            return $this->message("Успешно сохранено! Ролей изменено: {$updatedCount}", 200, [
                'updated_count' => $updatedCount,
            ]);
        }

        return $this->message('Изменений для сохранения нет.', 200, [
            'updated_count' => 0,
        ]);
    }
} 