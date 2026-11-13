<?php

namespace App\Http\Requests\Api\V1\Web;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация обновления раздела «О себе» (PATCH /api/profile/bio).
 *
 * Поле: bio — опциональная строка до 1000 символов.
 */
class UpdateProfileBioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // роут под auth:sanctum
    }

    public function rules(): array
    {
        return [
            'bio' => 'nullable|string|max:1000',
        ];
    }
}
