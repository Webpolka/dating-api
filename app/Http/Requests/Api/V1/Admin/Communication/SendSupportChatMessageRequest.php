<?php

namespace App\Http\Requests\Admin\V1\Support;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация отправки сообщения от staff в чат поддержки.
 *
 * POST /api/admin/support-chats/{chatId}/send
 * (throttle:admin-support-actions)
 *
 * Поле `body` — текст сообщения (до 2000 символов).
 */
class SendSupportChatMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role.
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => 'required|string|max:2000',
        ];
    }
}
