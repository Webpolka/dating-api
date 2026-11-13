<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация обновления личной информации (PATCH /api/profile/personal-info).
 *
 * Поля хранятся в отдельных колонках user_profiles (не JSONB):
 * внешность (height, weight, body_type, eye_color, hair_color, body_decorations),
 * отношения (relationship_status, children_status), быт (pets, housing, has_car),
 * работа (education_level, institution, institution_year, income, activity,
 * position), привычки (smoking, alcohol), языки и спорт (languages, sports).
 */
class UpdateProfilePersonalInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // роут под auth:sanctum
    }

    public function rules(): array
    {
        return [
            // Внешность
            'height'             => 'nullable|integer|min:50|max:300',
            'weight'             => 'nullable|integer|min:20|max:500',
            'body_type'          => 'nullable|integer',
            'eye_color'          => 'nullable|integer',
            'hair_color'         => 'nullable|integer',
            'body_decorations'  => 'nullable|array',
            'body_decorations.*' => 'integer',
            // Отношения
            'relationship_status' => 'nullable|integer',
            'children_status'     => 'nullable|integer',
            // Быт
            'pets'     => 'nullable|integer',
            'housing'  => 'nullable|integer',
            'has_car'  => 'nullable|integer',
            // Работа
            'education_level'  => 'nullable|integer',
            'institution'      => 'nullable|string|max:255',
            'institution_year' => 'nullable|integer|min:1950|max:' . (date('Y') + 1),
            'income'          => 'nullable|integer',
            'activity'         => 'nullable|string|max:255',
            'position'         => 'nullable|string|max:255',
            // Привычки
            'smoking' => 'nullable|integer',
            'alcohol' => 'nullable|integer',
            // Массивы
            'languages'     => 'nullable|array',
            'languages.*'   => 'integer',
            'sports'        => 'nullable|array',
            'sports.*'      => 'integer',
        ];
    }
}
