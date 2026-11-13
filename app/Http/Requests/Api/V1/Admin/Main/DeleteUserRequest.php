<?php

namespace App\Http\Requests\Api\V1\Admin\Main;

use App\Enums\V1\DeletionReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Валидация запроса на удаление (деактивацию) пользователя.
 *
 * DELETE /api/admin/users/{userId}
 *
 * Деактивация = soft-delete + status='deactivated' + обнуление подписок.
 * Восстановление — отдельный эндпоинт POST /users/{id}/restore.
 */
class DeleteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Маршрут защищён admin.role:admin,moderator + throttle:admin-user-actions.
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', Rule::in(DeletionReason::cases())],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Причина удаления обязательна.',
            'reason.in'        => 'Недопустимая причина удаления.',
        ];
    }

    public function deletionReason(): string
    {
        return $this->validated()['reason'];
    }
}
