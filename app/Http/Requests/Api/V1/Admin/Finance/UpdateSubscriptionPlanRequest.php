<?php

namespace App\Http\Requests\Admin\V1\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Валидация обновления тарифа подписки
 * (PUT /api/admin/subscription-plans/{id}).
 *
 * Как при создании, но без tier (тариф не меняет уровень)
 * и slug: unique игнорирует текущий id тарифа из роута.
 */
class UpdateSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role.
        return true;
    }

    public function rules(): array
    {
        return [
            'name'              => 'required|string|max:255',
            'slug'              => ['required', 'string', 'max:255', 'regex:/^[a-z0-9\-_]+$/', Rule::unique('subscription_plans', 'slug')->ignore((int) $this->route('id'))],
            'price'             => 'required|numeric|min:0',
            'old_price'         => 'nullable|numeric|min:0',
            'duration_days'     => 'required|integer|min:1',
            'sort_order'        => 'required|integer|min:0',
            'apple_product_id'  => 'nullable|string|max:255',
            'google_product_id'  => 'nullable|string|max:255',
            'is_active'          => 'boolean',
        ];
    }
}
