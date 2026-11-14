<?php

namespace App\Http\Requests\Api\V1\Admin\System;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация создания категории интересов
 * (POST /api/admin/interests/categories).
 *
 * Поля:
 *   - name       — уникальное имя категории (citext → case-insensitive unique).
 *   - icon       — необязательная иконка (строка-идентификатор, напр. "sport").
 *   - sort_order — необязательный порядок сортировки; если не передан,
 *                  контроллер выставит max+1.
 *
 * Slug генерируется в контроллере из name (cocur/slugify с russian ruleset),
 * с fallback 'interest-category-{time()}' для чисто кириллических имён.
 */
class StoreInterestCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'       => ['required', 'string', 'max:50', 'unique:interest_categories,name'],
            'icon'       => ['nullable', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Имя категории обязательно',
            'name.max'      => 'Имя слишком длинное (макс. 50 символов)',
            'name.unique'   => 'Категория с таким именем уже существует',
            'icon.max'      => 'Иконка слишком длинная (макс. 50 символов)',
        ];
    }
}
