<?php

namespace App\Http\Requests\Admin\V1\Content;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация создания рубрики блога
 * (POST /api/admin/blog/categories).
 *
 * Поле: name — уникальное имя рубрики.
 */
class StoreBlogCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role.
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255|unique:blog_categories,name',
        ];
    }
}
