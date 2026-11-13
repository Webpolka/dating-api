<?php

namespace App\Http\Requests\Admin\V1\Support;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация создания шаблона быстрого ответа поддержки.
 *
 * POST /api/admin/support-templates (throttle:admin-templates-actions)
 *
 * Поля: category_id (существующая категория), title, body,
 * is_active (по умолчанию true), sort_order (по умолчанию 0).
 */
class StoreSupportTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role.
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id' => 'required|exists:support_template_categories,id',
            'title'       => 'required|string|max:150',
            'body'        => 'required|string|max:5000',
            'is_active'   => 'boolean',
            'sort_order'  => 'integer|min:0',
        ];
    }
}
