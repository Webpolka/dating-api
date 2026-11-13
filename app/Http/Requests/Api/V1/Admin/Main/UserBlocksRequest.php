<?php

namespace App\Http\Requests\Api\V1\Admin\Main;

/**
 * Параметры пагинации для таба "Блокировки" пользователя.
 *
 * GET /api/admin/users/{user}/blocks?blocked_page=1&blockers_page=2
 *
 * Аналогично reports — две независимые колонки: заблокированные
 * пользователем (blocked, blocker_id=userId) и заблокировавшие
 * пользователя (blockers, blocked_id=userId).
 */
class UserBlocksRequest extends UserListRequest
{
    public function rules(): array
    {
        return [
            'blocked_page'  => ['nullable', 'integer', 'min:1'],
            'blockers_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function blockedPage(int $default = 1): int
    {
        $validated = $this->validated();
        return isset($validated['blocked_page']) ? (int) $validated['blocked_page'] : $default;
    }

    public function blockersPage(int $default = 1): int
    {
        $validated = $this->validated();
        return isset($validated['blockers_page']) ? (int) $validated['blockers_page'] : $default;
    }
}
