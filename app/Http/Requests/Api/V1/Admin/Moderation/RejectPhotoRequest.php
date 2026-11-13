<?php

namespace App\Http\Requests\Admin\V1\Moderation;

use App\Enums\PhotoRejectReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Отклонение фото модератором (POST /api/admin/photos/{id}/reject).
 *
 * Причина должна быть ПОЛЬЗОВАТЕЛЬСКОЙ (не системной):
 * file_missing/too_large/processing_error сюда не проходят —
 * их ставит только job'а обработки.
 */
class RejectPhotoRequest extends FormRequest
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
            'reason' => ['required', 'string', Rule::in(PhotoRejectReason::userValues())],
        ];
    }
}
