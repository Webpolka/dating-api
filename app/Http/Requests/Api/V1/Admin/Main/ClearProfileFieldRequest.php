<?php

namespace App\Http\Requests\Admin\V1\Users;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация очистки отдельного поля профиля пользователя модерацией.
 *
 * POST /api/admin/users/{user}/profile/clear-field
 *
 * Поле `field` — какое из текстовых полей профиля очистить:
 * headline / bio / looking_for (модерация снимает неприемлемый
 * контент точечно, не трогая остальной профиль и фото).
 */
class ClearProfileFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role.
        return true;
    }

    public function rules(): array
    {
        return [
            'field' => ['required', 'in:headline,bio,looking_for'],
        ];
    }
}
