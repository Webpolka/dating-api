<?php

namespace App\Http\Requests\Api\V1\Admin\Finance;

use App\Enums\V1\RefundReason;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация оформления возврата средств по транзакции
 * (POST /api/admin/transactions/{id}/refund).
 *
 * Поля: reason (одно из значений enum RefundReason),
 * comment (опциональный комментарий, 5-500 символов).
 */
class RefundTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role.
        return true;
    }

    public function rules(): array
    {
        return [
            'reason'  => ['required', 'string', 'in:' . implode(',', array_column(RefundReason::cases(), 'value'))],
            'comment' => ['nullable', 'string', 'min:5', 'max:500'],
        ];
    }
}
