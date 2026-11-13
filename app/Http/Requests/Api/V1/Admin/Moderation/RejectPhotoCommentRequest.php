<?php

namespace App\Http\Requests\Admin\V1\Moderation;

use App\Enums\CommentRejectReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Отклонение комментария к фото (POST /api/admin/photo-comments/{id}/reject).
 *
 * Причина должна быть пользовательской (не системной): parent_rejected
 * сюда не проходит — её ставит только каскад при reject родителя.
 */
class RejectPhotoCommentRequest extends FormRequest
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
