<?php

namespace App\Http\Requests\Api\V1\Web;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация обновления автопортрета (PATCH /api/profile/self-portrait).
 *
 * 19 опциональных текстовых полей (nullable|string|max:500), которые хранятся
 * в одном JSONB поле user_profiles.self_portrait: education, musical, film,
 * hero, dish, city, pastime, place, revelation, worse, quality, mistake,
 * religion, character, demerit, job, adventure, tv, mat.
 */
class UpdateProfileSelfPortraitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // роут под auth:sanctum
    }

    public function rules(): array
    {
        return [
            'education'  => 'nullable|string|max:500',
            'musical'    => 'nullable|string|max:500',
            'film'       => 'nullable|string|max:500',
            'hero'       => 'nullable|string|max:500',
            'dish'       => 'nullable|string|max:500',
            'city'       => 'nullable|string|max:500',
            'pastime'    => 'nullable|string|max:500',
            'place'      => 'nullable|string|max:500',
            'revelation' => 'nullable|string|max:500',
            'worse'      => 'nullable|string|max:500',
            'quality'    => 'nullable|string|max:500',
            'mistake'    => 'nullable|string|max:500',
            'religion'   => 'nullable|string|max:500',
            'character'  => 'nullable|string|max:500',
            'demerit'    => 'nullable|string|max:500',
            'job'        => 'nullable|string|max:500',
            'adventure'  => 'nullable|string|max:500',
            'tv'         => 'nullable|string|max:500',
            'mat'        => 'nullable|string|max:500',
        ];
    }
}
