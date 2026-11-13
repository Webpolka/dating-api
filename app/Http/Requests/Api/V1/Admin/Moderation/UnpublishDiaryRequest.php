<?php

namespace App\Http\Requests\Api\V1\Admin\Moderation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Снятие записи дневника с публикации
 * (POST /api/admin/diary/{id}/unpublish).
 *
 * Запись возвращается в статус pending; вместе со сменой статуса
 * можно обновить рубрику и флаг разрешения комментариев.
 */
class UnpublishDiaryRequest extends FormRequest
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
