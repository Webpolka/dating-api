<?php

namespace App\Http\Requests\Admin\V1\Content;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация создания/обновления рассылки
 * (POST /api/admin/broadcasts, PUT /api/admin/broadcasts/{broadcast}).
 *
 * Поля: title, type (in_app/push/email), action_url, scheduled_at,
 * selected_user_id. Правила для message/email_body зависят от type:
 * для email обязателен email_body, иначе — message.
 */
class BroadcastRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role.
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'type' => 'required|in:in_app,push,email',
            'action_url' => 'nullable|url|max:500',
            'scheduled_at' => 'nullable|date|after_or_equal:now',
            'selected_user_id' => 'nullable|integer|exists:users,id',
        ];

        if ($this->input('type') === 'email') {
            $rules['email_body'] = 'required|string';
            $rules['message'] = 'nullable|string';
        } else {
            $rules['message'] = 'required|string|max:5000';
            $rules['email_body'] = 'nullable|string';
        }

        return $rules;
    }
}
