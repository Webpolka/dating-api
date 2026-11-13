<?php

namespace App\Http\Requests\Admin\V1\Users;

/**
 * Параметры пагинации для таба "Жалобы" пользователя.
 *
 * GET /api/admin/users/{user}/reports?made_page=1&received_page=2
 *
 * В одном запросе две независимые пагинации: жалобы ПОЛЬЗОВАТЕЛЯ
 * (made, reporter_id=userId) и НА ПОЛЬЗОВАТЕЛЯ (received, reported_id=userId).
 * Нельзя использовать один `page` — иначе переключение одной колонки
 * сбрасывало бы вторую.
 */
class UserReportsRequest extends UserListRequest
{
    public function rules(): array
    {
        return [
            'made_page'     => ['nullable', 'integer', 'min:1'],
            'received_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function madePage(int $default = 1): int
    {
        $validated = $this->validated();
        return isset($validated['made_page']) ? (int) $validated['made_page'] : $default;
    }

    public function receivedPage(int $default = 1): int
    {
        $validated = $this->validated();
        return isset($validated['received_page']) ? (int) $validated['received_page'] : $default;
    }
}
