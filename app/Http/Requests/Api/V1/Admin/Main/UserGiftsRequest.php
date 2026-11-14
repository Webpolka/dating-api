<?php

namespace App\Http\Requests\Api\V1\Admin\Main;

/**
 * Параметры для таба «Подарки» пользователя.
 *
 * GET /api/admin/users/{user}/gifts?filter=all&page=2
 *
 * Единый список подарков (UserGift) с фильтром по направлению:
 *   all       (дефолт) — и отправленные, и полученные
 *   sent               — только отправленные юзером (sender_id = userId)
 *   received           — только полученные юзером (receiver_id = userId)
 *
 * Паттерн идентичен UserDiariesRequest (rubric-фильтр): single-list с
 * кнопками-фильтрами, одна пагинация. Раньше тут был composite sent/received
 * с двумя пагинациями — но blade-эталон использует именно single-list с
 * фильтром (оператору удобнее переключать направление, чем скроллить две
 * колонки). withTrashed() на бэке — чтобы видеть подарки, скрытые юзером.
 */
class UserGiftsRequest extends UserListRequest
{
    public function rules(): array
    {
        return [
            'filter' => ['nullable', 'in:all,sent,received'],
            'page'   => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filter(string $default = 'all'): string
    {
        $validated = $this->validated();
        $filter = isset($validated['filter']) ? (string) $validated['filter'] : $default;
        return in_array($filter, ['all', 'sent', 'received'], true) ? $filter : $default;
    }
}
