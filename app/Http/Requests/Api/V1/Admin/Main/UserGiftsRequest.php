<?php

namespace App\Http\Requests\Api\V1\Admin\Main;

/**
 * Параметры пагинации для таба «Подарки» пользователя.
 *
 * GET /api/admin/users/{user}/gifts?sent_page=1&received_page=2
 *
 * В одном запросе две независимые пагинации:
 *   - sent     — подарки, отправленные пользователем (sender_id = userId)
 *   - received — подарки, полученные пользователем (receiver_id = userId)
 *
 * Паттерн идентичен UserReportsRequest (made/received).
 */
class UserGiftsRequest extends UserListRequest
{
    public function rules(): array
    {
        return [
            'sent_page'     => ['nullable', 'integer', 'min:1'],
            'received_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function sentPage(int $default = 1): int
    {
        $validated = $this->validated();
        return isset($validated['sent_page']) ? (int) $validated['sent_page'] : $default;
    }

    public function receivedPage(int $default = 1): int
    {
        $validated = $this->validated();
        return isset($validated['received_page']) ? (int) $validated['received_page'] : $default;
    }
}
