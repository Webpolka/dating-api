<?php

namespace App\Http\Requests\Admin\V1\Content;

use App\Enums\GiftCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;

/**
 * Валидация обновления подарка в каталоге
 * (PUT /api/admin/gifts/{id}).
 *
 * Те же поля, что и при создании, но slug: unique
 * игнорирует текущий id подарка из роута.
 */
class UpdateGiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'      => 'required|string|max:255',
            'slug'      => ['required', 'string', 'max:255', 'regex:/^[a-z0-9\-_]+$/', Rule::unique('gifts', 'slug')->ignore((int) $this->route('id'))],
            'image_url' => 'required|string|max:255',
            'price'     => 'required|integer|min:1',
            'category'  => ['nullable', new EnumRule(GiftCategory::class)],
            'type'      => ['required', 'string', 'in:main,premium,services'],
            'is_active' => 'boolean',
        ];
    }
}
