<?php

namespace App\Http\Requests\Api\V1\Admin\System;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Валидация обновления категории интересов
 * (PUT /api/admin/interests/categories/{category}).
 *
 * Все поля необязательны (sometimes-семантика): обновляем только переданное.
 * Slug пересчитывается в контроллере при смене name.
 */
class UpdateInterestCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $categoryId = $this->route('category')?->id;

        return [
            'name'       => ['sometimes', 'string', 'max:50', Rule::unique('interest_categories', 'name')->ignore($categoryId)],
            'icon'       => ['nullable', 'string', 'max:50'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.max'    => 'Имя слишком длинное (макс. 50 символов)',
            'name.unique' => 'Категория с таким именем уже существует',
            'icon.max'    => 'Иконка слишком длинная (макс. 50 символов)',
        ];
    }
}
