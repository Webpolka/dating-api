<?php

namespace App\Http\Requests\Admin\V1\Users;

/**
 * Параметры для таба «Финансы» пользователя.
 *
 * GET /api/admin/users/{user}/transactions?status=all&page=2
 *
 * Транзакции пользователя (user_id = userId).
 *
 * Варианты status — зеркалит enum transactions.status и фильтр
 * глобального списка TransactionController::index():
 *   all | success | pending | failed | refunded
 */
class UserTransactionsRequest extends UserListRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'status' => ['nullable', 'in:all,success,pending,failed,refunded'],
        ]);
    }

    public function status(string $default = 'all'): string
    {
        $validated = $this->validated();
        $status = isset($validated['status']) ? (string) $validated['status'] : $default;
        return in_array($status, ['all', 'success', 'pending', 'failed', 'refunded'], true)
            ? $status
            : $default;
    }
}
