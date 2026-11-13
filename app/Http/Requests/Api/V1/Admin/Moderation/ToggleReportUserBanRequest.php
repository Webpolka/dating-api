<?php

namespace App\Http\Requests\Api\V1\Admin\Moderation;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Бан/разбан пользователя из карточки жалобы
 * (POST /api/admin/reports/user/{userId}/ban).
 *
 * type — вид блокировки (permanent / temp / shadow);
 * reportId — необязательный ID жалобы, из которой берётся причина.
 */
class ToggleReportUserBanRequest extends FormRequest
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
            'type' => ['required', 'string', 'in:permanent,temp,shadow'],
            'reportId' => 'nullable|integer',
        ];
    }
}
