<?php

namespace App\Http\Requests\Api\V1\Admin\System;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Валидация обновления рубрики блога
 * (PUT /api/admin/blog/categories/{category}).
 *
 * Поля: name (уникальное, игнорирует текущую рубрику
 * из route-model binding), is_active, sort_order.
 */
class UpdateBlogCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'max:255', Rule::unique('blog_categories', 'name')->ignore($this->route('category')?->id)],
            'is_active'  => 'boolean',
            'sort_order' => 'integer|min:0',
        ];
    }
}
