<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация отправки жалобы на пользователя (POST /api/users/{user}/report).
 *
 * Поля: reason — обязательная строка (причина жалобы);
 * description — опциональное пояснение до 1000 символов.
 */
class ReportUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // роут под auth:sanctum
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string'],
            'description' => 'nullable|string|max:1000',
        ];
    }
}
