<?php

namespace App\Http\Requests\Admin\V1\Users;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация принудительного завершения одной сессии пользователя.
 *
 * POST /api/admin/users/{user}/sessions/kill
 *
 * Поле `session_id` — идентификатор сессии из списка
 * getUserSessions() (Laravel session ID, строка).
 */
class KillUserSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role.
        return true;
    }

    public function rules(): array
    {
        return [
            'session_id' => ['required', 'string', 'max:255'],
        ];
    }
}
