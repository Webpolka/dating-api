<?php

namespace App\Http\Requests\Api\V1\Admin\Main;

/**
 * Параметры для таба «Рассылки» пользователя.
 *
 * GET /api/admin/users/{user}/broadcasts?filter=all&page=2
 *
 * Единый список рассылок, адресованных юзеру (персональные + массовые «всем»),
 * с фильтром по каналу доставки:
 *   all     (дефолт) — все каналы
 *   in_app           — только in-app уведомления (Site)
 *   email             — только Email-рассылки
 *   push              — только Push-уведомления
 *
 * Паттерн идентичен UserGiftsRequest (single-list с кнопками-фильтром).
 */
class UserBroadcastsRequest extends UserListRequest
{
    public function rules(): array
    {
        return [
            'filter' => ['nullable', 'in:all,in_app,push,email'],
            'page'   => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filter(string $default = 'all'): string
    {
        $validated = $this->validated();
        $filter = isset($validated['filter']) ? (string) $validated['filter'] : $default;
        return in_array($filter, ['all', 'in_app', 'push', 'email'], true) ? $filter : $default;
    }
}
