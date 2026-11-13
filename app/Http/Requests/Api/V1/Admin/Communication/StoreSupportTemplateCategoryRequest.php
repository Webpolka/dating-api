<?php

namespace App\Http\Requests\Admin\V1\Support;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация создания категории шаблонов поддержки.
 *
 * POST /api/admin/support-templates/categories
 *
 * Поля: name, slug (опционален — автогенерация из name,
 * только латиница/цифры/дефис), is_active, sort_order.
 */
class StoreSupportTemplateCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'       => 'required|string|max:100',
            'slug'       => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9-]+$/'],
            'is_active'  => 'boolean',
            'sort_order' => 'integer|min:0',
        ];
    }
}
