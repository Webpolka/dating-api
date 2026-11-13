<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация свайпа пользователя (POST /api/users/{user}/swipe).
 *
 * Поле: type — обязательное направление свайпа (like или dislike).
 */
class StoreSwipeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // роут под auth:sanctum
    }

    public function rules(): array
    {
        return [
            'type' => 'required|in:like,dislike'
        ];
    }
}
