<?php

namespace App\Http\Requests\Api\V1\Admin\Moderation;

use App\Enums\V1\CommentRejectReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Отклонение комментария к дневнику
 * (POST /api/admin/diary-comments/{id}/reject).
 *
 * Причина должна быть пользовательской (не системной): parent_rejected
 * сюда не проходит — её ставит только каскад при reject родителя.
 */
class RejectDiaryCommentRequest extends FormRequest
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
            'reason' => ['required', 'string', Rule::in(CommentRejectReason::userValues())],
        ];
    }
}
