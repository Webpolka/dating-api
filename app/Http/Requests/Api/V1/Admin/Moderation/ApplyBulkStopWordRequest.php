<?php

namespace App\Http\Requests\Admin\V1\Moderation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Массовое действие над выбранными стоп-словами
 * (POST /api/admin/stop-words/bulk).
 *
 * Body: { ids: number[], action: 'activate' | 'deactivate' | 'delete' }.
 */
class ApplyBulkStopWordRequest extends FormRequest
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
            'ids'    => ['required', 'array', 'min:1'],
            'ids.*'  => ['required', 'integer'],
            'action' => ['required', 'string', 'in:activate,deactivate,delete'],
        ];
    }
}
