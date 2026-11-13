<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация обновления основных данных профиля (PATCH /api/profile/main).
 *
 * Поля: name — имя юзера (обязательно, 1-16 символов);
 * birth_date — дата рождения (опционально, юзер 18-80 лет, не в будущем);
 * city_id — город (опционально, должен существовать в cities).
 */
class UpdateProfileMainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // роут под auth:sanctum
    }

    public function rules(): array
    {
        return [
            // Имя — обязательно, 1-16 символов
            'name' => 'required|string|min:1|max:16',

            // Дата рождения — необязательно (юзер может не менять).
            // Если указана — реальная дата, не в будущем, юзер 18-80 лет.
            'birth_date' => [
                'nullable',
                'date',
                'before:today',
                'after:' . now()->subYears(80)->format('Y-m-d'),
            ],

            // Город — необязательно, но если указан — должен существовать в cities
            'city_id' => 'nullable|integer|exists:cities,id',
        ];
    }
}
