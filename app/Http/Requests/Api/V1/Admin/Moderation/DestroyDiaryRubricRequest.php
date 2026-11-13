<?php

namespace App\Http\Requests\Admin\V1\Moderation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Удаление системной рубрики дневников
 * (DELETE /api/admin/diary/rubrics/{id}).
 *
 * reassign_id — необязательная рубрика, куда перенести записи
 * удаляемой; не может совпадать с самой удаляемой (not_in:{id}).
 */
class DestroyDiaryRubricRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role —
        // здесь дополнительно проверять нечего.
        return true;
    }

    public function rules(): array
    {
        $id = (int) $this->route('id');

        return [
            'reassign_id' => 'nullable|integer|exists:diary_rubrics,id|not_in:' . $id,
        ];
    }
}
