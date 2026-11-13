<?php

namespace App\Http\Requests\Api\V1\Admin\Communication;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация обновления категории шаблонов поддержки.
 *
 * PUT /api/admin/support-templates/categories/{id}
 *
 * Правила идентичны созданию (StoreSupportTemplateCategoryRequest).
 * Защиту системной категории "Общие" (slug=general) выполняет контроллер.
 */
class UpdateSupportTemplateCategoryRequest extends FormRequest
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
