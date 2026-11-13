<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация обновления headline — статуса пользователя (PATCH /api/profile/headline).
 *
 * Поле: headline — опциональная строка до 200 символов.
 */
class UpdateProfileHeadlineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // роут под auth:sanctum
    }

    public function rules(): array
    {
        return [
            'headline' => 'nullable|string|max:200',
        ];
    }
}
