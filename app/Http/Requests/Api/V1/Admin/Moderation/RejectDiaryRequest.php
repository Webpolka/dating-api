<?php

namespace App\Http\Requests\Admin\V1\Moderation;

use App\Enums\DiaryRejectReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Отклонение записи дневника (POST /api/admin/diary/{id}/reject).
 *
 * reason — пользовательская причина из DiaryRejectReason;
 * вместе со сменой статуса можно обновить рубрику и флаг
 * разрешения комментариев.
 */
class RejectDiaryRequest extends FormRequest
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
            'reason' => ['required', 'string', Rule::in(DiaryRejectReason::userValues())],
            'diary_rubric_id' => 'nullable|integer|exists:diary_rubrics,id',
            'is_comments_enabled' => 'boolean',
        ];
    }
}
