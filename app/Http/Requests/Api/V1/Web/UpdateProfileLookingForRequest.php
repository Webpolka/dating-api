<?php

namespace App\Http\Requests\Api\V1\Web;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация обновления раздела «Кого я хочу найти» (PATCH /api/profile/looking-for).
 *
 * Поле: looking_for — опциональная строка до 1000 символов.
 */
class UpdateProfileLookingForRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // роут под auth:sanctum
    }

    public function rules(): array
    {
        return [
            'looking_for' => 'nullable|string|max:1000',
        ];
    }
}
