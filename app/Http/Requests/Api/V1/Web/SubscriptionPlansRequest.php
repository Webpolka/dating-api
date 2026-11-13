<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация фильтра списка тарифов подписок (GET /api/subscriptions/plans).
 *
 * Поле: tier — опционально (premium или vip). Без tier возвращаются
 * все активные тарифы (premium + vip).
 */
class SubscriptionPlansRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // роут под auth:sanctum
    }

    public function rules(): array
    {
        return [
            'tier' => 'nullable|in:premium,vip',
        ];
    }
}
