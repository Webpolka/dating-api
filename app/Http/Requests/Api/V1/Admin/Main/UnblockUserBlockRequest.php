<?php

namespace App\Http\Requests\Admin\V1\Users;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация принудительного снятия блокировки между пользователями.
 *
 * POST /api/admin/users/{user}/blocks/unblock
 *
 * Поле `block_id` — ID записи в user_blocks. Её связь с пользователем
 * (blocker или blocked) проверяется уже в контроллере.
 */
class UnblockUserBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role.
        return true;
    }

    public function rules(): array
    {
        return [
            'block_id' => ['required', 'integer'],
        ];
    }
}
