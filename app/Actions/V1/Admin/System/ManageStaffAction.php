<?php

namespace App\Actions\Admin\System;

use App\Models\AdminLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Управление staff-ролями: promote / demote / массовое изменение.
 *
 * Извлечено из System\StaffController (S5-7).
 * Контроллер оставляет себе: валидацию (FormRequests), разрешение моделей
 * (404), guard-проверки (founders / self, 403) и формирование JSON-ответов.
 *
 * ПРИМЕЧАНИЕ: promote/demote — одиночные UPDATE + AdminLog, транзакции не
 * требуют; batchUpdate обёрнут в DB::transaction (B2) — частичные изменения
 * откатываются целиком.
 */
class ManageStaffAction
{
    /**
     * Повысить юзера до роли $newRole.
     * Логируется в AdminLog для аудита (кто кого повысил до какой роли).
     */
    public function promote(User $user, User $admin, string $newRole): void
    {
        $before = ['role' => $user->getOriginal('role')];

        $user->update(['role' => $newRole]);

        // B1: логируем promote в AdminLog.
        AdminLog::record('staff.promote', $user, $admin, $before, [
            'role' => $newRole,
            'context' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'old_role' => $before['role'],
                'new_role' => $newRole,
                'promoted_by' => $admin->id,
            ],
        ], participants: [$user->id]);
    }

    /**
     * Разжаловать юзера в обычные юзеры (ROLE_USER).
     * Логируется в AdminLog для аудита.
     */
    public function demote(User $user, User $admin): void
    {
        $before = ['role' => $user->getOriginal('role')];

        $user->update(['role' => User::ROLE_USER]);

        // B1: логируем demote в AdminLog.
        AdminLog::record('staff.demote', $user, $admin, $before, [
            'role' => User::ROLE_USER,
            'context' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'old_role' => $before['role'],
                'new_role' => User::ROLE_USER,
                'demoted_by' => $admin->id,
            ],
        ], participants: [$user->id]);
    }

    /**
     * Массовое сохранение ролей.
     *
     * B2: обёрнуто в DB::transaction — если упадёт на середине, все изменения
     * откатятся. Раньше часть ролей могла измениться, часть нет.
     *
     * AdminLog пишется ПОСЛЕ успешного коммита, по одной записи на изменение.
     *
     * @param array<int|string, string> $roles [user_id => new_role]
     * @return int Количество реально изменённых ролей.
     */
    public function batchUpdate(array $roles, User $admin): int
    {
        $founders = config('app.founders', []);
        $authId = $admin->id;
        $updatedCount = 0;
        $logEntries = [];

        DB::transaction(function () use ($roles, $founders, $authId, &$updatedCount, &$logEntries) {
            foreach ($roles as $userId => $newRole) {
                $userId = (int) $userId;

                // Пропускаем founders и себя
                if (in_array($userId, $founders, true) || $userId === $authId) {
                    continue;
                }

                $user = User::find($userId);
                if (!$user || $user->role === $newRole) {
                    continue;
                }

                $before = ['role' => $user->getOriginal('role')];
                $user->update(['role' => $newRole]);
                $updatedCount++;

                // Готовим запись для лога — запишем после коммита (внешний массив).
                $logEntries[] = [
                    'user' => $user,
                    'before' => $before,
                    'after' => ['role' => $newRole],
                ];
            }
        });

        // B1: логируем после успешного коммита. По одной записи на каждое изменение.
        foreach ($logEntries as $entry) {
            AdminLog::record('staff.batch_update', $entry['user'], $admin, $entry['before'], [
                'role' => $entry['after']['role'],
                'context' => [
                    'user_id' => $entry['user']->id,
                    'user_name' => $entry['user']->name,
                    'old_role' => $entry['before']['role'],
                    'new_role' => $entry['after']['role'],
                    'batch_by' => $authId,
                ],
            ], participants: [$entry['user']->id]);
        }

        return $updatedCount;
    }
}
