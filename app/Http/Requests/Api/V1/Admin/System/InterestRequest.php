<?php

namespace App\Http\Requests\Api\V1\Admin\System;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Валидация создания/обновления интереса
 * (POST /api/admin/interests, PUT /api/admin/interests/{interest}).
 *
 * Один запрос на оба действия — как BlogPostRequest.
 * При update игнорируем свой slug при unique-проверке через route-model-binding.
 *
 * Поля:
 *   - name         — уникальное имя интереса (citext → case-insensitive).
 *   - category_id  — nullable, FK на interest_categories (при удалении категории
 *                    интерес остаётся "без категории").
 *   - search_boost — 0..65535 (unsignedSmallInteger в schema); ручной буст выдачи.
 *
 * Slug генерируется в контроллере (cocur/slugify + russian), fallback 'interest-{time()}'.
 * users_count НЕ валидируется — обновляется только триггером user_interests_update_count.
 */
class InterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $interestId = $this->route('interest')?->id;

        return [
            'name'         => ['required', 'string', 'max:100', Rule::unique('interests', 'name')->ignore($interestId)],
            'category_id'  => ['nullable', 'integer', 'exists:interest_categories,id'],
            'search_boost' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'        => 'Имя интереса обязательно',
            'name.max'             => 'Имя слишком длинное (макс. 100 символов)',
            'name.unique'          => 'Интерес с таким именем уже существует',
            'category_id.exists'   => 'Выбранная категория не существует',
            'search_boost.integer' => 'Буст должен быть целым числом',
            'search_boost.min'     => 'Буст не может быть отрицательным',
            'search_boost.max'     => 'Буст слишком большой (макс. 65535)',
        ];
    }
}
