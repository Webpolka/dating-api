<?php

namespace App\Http\Requests\Admin\V1\Users;

/**
 * Параметры для таба «История входов» пользователя.
 *
 * GET /api/admin/users/{user}/auth-logs?outcome=all&page=2
 *
 * Записи user_auth_logs (user_id = userId) — включая неудачные
 * попытки входа (антифрод-контекст).
 *
 * Варианты outcome:
 *   all     — все записи
 *   success — только успешные входы (is_successful = true)
 *   failed  — только неудачные (is_successful = false)
 */
class UserAuthLogsRequest extends UserListRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'outcome' => ['nullable', 'in:all,success,failed'],
        ]);
    }

    public function outcome(string $default = 'all'): string
    {
        $validated = $this->validated();
        $outcome = isset($validated['outcome']) ? (string) $validated['outcome'] : $default;
        return in_array($outcome, ['all', 'success', 'failed'], true)
            ? $outcome
            : $default;
    }
}
