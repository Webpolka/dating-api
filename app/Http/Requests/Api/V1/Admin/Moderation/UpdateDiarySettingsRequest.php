<?php

namespace App\Http\Requests\Api\V1\Admin\Moderation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Обновление настроек записи дневника без смены статуса
 * (POST /api/admin/diary/{id}/settings).
 *
 * Меняются только рубрика и флаг разрешения комментариев.
 */
class UpdateDiarySettingsRequest extends FormRequest
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
            'diary_rubric_id' => 'nullable|integer|exists:diary_rubrics,id',
            'is_comments_enabled' => 'boolean',
        ];
    }
}
