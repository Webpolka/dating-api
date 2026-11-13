<?php

namespace App\Http\Requests\Api\V1\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация входа администратора в админку
 * (POST /api/admin/login).
 *
 * Поля: email, password.
 */
class AdminLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Публичный роут логина — вместо admin.role защищён
        // throttle-middleware (throttle:admin-login и admin-login-by-email).
        return true;
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }
}
