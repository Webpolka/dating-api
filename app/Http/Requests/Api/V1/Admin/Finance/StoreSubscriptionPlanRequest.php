<?php

namespace App\Http\Requests\Api\V1\Admin\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Валидация создания тарифа подписки (Premium / VIP)
 * (POST /api/admin/subscription-plans).
 *
 * Поля: tier, name, slug (уникальный), price, old_price,
 * duration_days, sort_order, apple_product_id, google_product_id, is_active.
 */
class StoreSubscriptionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role.
        return true;
    }

    public function rules(): array
    {
        return [
            'tier'              => ['required', 'string', 'in:premium,vip'],
            'name'              => 'required|string|max:255',
            'slug'              => ['required', 'string', 'max:255', 'regex:/^[a-z0-9\-_]+$/', Rule::unique('subscription_plans', 'slug')],
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
