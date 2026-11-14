<?php

namespace App\Http\Requests\Api\V1\Admin\Main;

/**
 * Параметры для таба «Финансы» пользователя.
 *
 * GET /api/admin/users/{user}/finances?trans_page=1&sub_page=2
 *
 * Composite-ответ в одном запросе:
 *   - balance   — текущий баланс юзера (credits, superlikes_remaining)
 *   - statuses  — активные Premium/VIP (premium_expires_at, vip_expires_at,
 *                 has_active_premium, has_active_vip)
 *   - transactions — история транзакций (Transaction, по 5 на страницу)
 *   - subscriptions — история подписок (UserSubscription, по 5 на страницу)
 *
 * Две независимые пагинации (trans_page / sub_page) — паттерн идентичен
 * UserReportsRequest / UserDiaryCommentsRequest. balance и statuses —
 * скаляры, без пагинации.
 *
 * Раньше эндпоинт назывался /transactions и был single-list. Blade-эталон
 * требует единый «финансовый дашборд» с балансом/статусами сверху и двумя
 * колонками снизу — поэтому переехали на composite /finances.
 */
class UserFinancesRequest extends UserListRequest
{
    public function rules(): array
    {
        return [
            'trans_page' => ['nullable', 'integer', 'min:1'],
            'sub_page'   => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function transPage(int $default = 1): int
    {
        $validated = $this->validated();
        return isset($validated['trans_page']) ? (int) $validated['trans_page'] : $default;
    }

    public function subPage(int $default = 1): int
    {
        $validated = $this->validated();
        return isset($validated['sub_page']) ? (int) $validated['sub_page'] : $default;
    }
}
