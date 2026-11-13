<?php

namespace App\Http\Requests\Api\V1\Admin\Finance;

use App\Enums\V1\GiftCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum as EnumRule;

/**
 * Валидация создания подарка в каталоге
 * (POST /api/admin/gifts).
 *
 * Поля: name, slug (уникальный, a-z0-9-_), image_url, price,
 * category (enum GiftCategory), type (main/premium/services), is_active.
 */
class StoreGiftRequest extends FormRequest
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
            'slug'      => ['required', 'string', 'max:255', 'regex:/^[a-z0-9\-_]+$/', Rule::unique('gifts', 'slug')],
            'image_url' => 'required|string|max:255',
            'price'     => 'required|integer|min:1',
            'category'  => ['nullable', new EnumRule(GiftCategory::class)],
            'type'      => ['required', 'string', 'in:main,premium,services'],
            'is_active' => 'boolean',
        ];
    }
}
