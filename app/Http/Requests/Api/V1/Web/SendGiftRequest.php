<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация отправки подарка пользователю (POST /api/users/{user}/gift).
 *
 * Поля: gift_id — обязательный ID подарка из каталога gifts;
 * message — опциональное сопроводительное сообщение до 150 символов.
 */
class SendGiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // роут под auth:sanctum
    }

    public function rules(): array
    {
        return [
            'gift_id' => 'required|exists:gifts,id',
            'message' => 'nullable|string|max:150'
        ];
    }
}
