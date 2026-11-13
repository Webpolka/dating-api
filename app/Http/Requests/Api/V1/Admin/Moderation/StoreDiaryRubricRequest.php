<?php

namespace App\Http\Requests\Api\V1\Admin\Moderation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Создание системной рубрики дневников
 * (POST /api/admin/diary/rubrics).
 *
 * name уникально среди системных рубрик (user_id IS NULL);
 * slug генерируется в контроллере из name.
 */
class StoreDiaryRubricRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role —
        // здесь дополнительно проверять нечего.
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('diary_rubrics', 'name')->where(fn($q) => $q->whereNull('user_id')),
            ],
            'description' => 'nullable|string|max:2000',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0|max:65535',
        ];
    }
}
