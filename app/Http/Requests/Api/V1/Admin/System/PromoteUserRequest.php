<?php

namespace App\Http\Requests\Admin\V1\System;

use Illuminate\Foundation\Http\FormRequest;

class PromoteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Проверка роли идет в middleware
    }

    public function rules(): array
    {
        return [
            'role' => ['required', 'string', 'in:admin,moderator,support'],
        ];
    }

    public function messages(): array
    {
        return [
            'role.in' => 'Недопустимая роль. Разрешены: admin, moderator, support.',
        ];
    }
}