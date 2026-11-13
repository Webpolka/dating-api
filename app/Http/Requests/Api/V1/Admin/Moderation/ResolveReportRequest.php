<?php

namespace App\Http\Requests\Admin\V1\Moderation;

use App\Enums\ReportResolution;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Принятие жалобы модератором (POST /api/admin/reports/{id}/resolve).
 *
 * resolution — одно из значений enum ReportResolution
 * (warn / ban / temp_ban / shadowban / photo_deleted / no_action),
 * note — необязательный комментарий модератора.
 */
class ResolveReportRequest extends FormRequest
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
            'resolution' => ['required', 'string', 'in:' . implode(',', array_column(ReportResolution::cases(), 'value'))],
            'note' => 'nullable|string',
        ];
    }
}
