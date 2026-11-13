<?php

namespace App\Http\Requests\Admin\V1\Moderation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Публикация записи дневника (POST /api/admin/diary/{id}/approve).
 *
 * Вместе со сменой статуса на published можно обновить
 * мета-настройки записи: рубрику и флаг разрешения комментариев.
 */
class ApproveDiaryRequest extends FormRequest
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
