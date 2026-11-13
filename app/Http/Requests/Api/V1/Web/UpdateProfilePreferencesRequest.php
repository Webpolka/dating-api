<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация обновления секции «Познакомлюсь» (PATCH /api/profile/preferences).
 *
 * Поля: preferred_gender, preferred_age_min, preferred_age_max — хранятся
 * в user_preferences; dating_goals — JSONB-массив целей знакомства
 * в user_profiles (ключи совпадают с DATING_GOALS на фронте).
 */
class UpdateProfilePreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // роут под auth:sanctum
    }

    public function rules(): array
    {
        return [
            // preferred_gender НЕ валидируем жёстко через in: —
            // фронт сам подставляет на основе gender юзера (male → ищет female, и наоборот).
            // Бэк просто сохраняет что прислали. Если в БД хранится 'm'/'f' или 'male'/'female' —
            // всё сохранится как есть.
            'preferred_gender' => 'nullable|string|max:10',

            // Возраст — границы 18-99, без жёсткой привязки min <= max
            // (слайдер на фронте сам гарантирует min ≤ max).
            'preferred_age_min' => 'nullable|integer|min:18|max:99',
            'preferred_age_max' => 'nullable|integer|min:18|max:99',

            // Цели знакомства — массив строк.
            // ВАЖНО: ключи должны СОВПАДАТЬ с DATING_GOALS на фронте:
            //   friends, family, travel, romantic, casual
            'dating_goals'      => 'nullable|array',
            'dating_goals.*'    => 'string|in:friends,family,travel,romantic,casual',
        ];
    }
}
