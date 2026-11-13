<?php

namespace App\Http\Requests\Api\V1\Admin\Communication;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация обновления шаблона быстрого ответа поддержки.
 *
 * PUT /api/admin/support-templates/{id} (throttle:admin-templates-actions)
 *
 * Правила идентичны созданию (StoreSupportTemplateRequest):
 * шаблон редактируется целиком, частичного PATCH-семантики нет.
 */
class UpdateSupportTemplateRequest extends FormRequest
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
