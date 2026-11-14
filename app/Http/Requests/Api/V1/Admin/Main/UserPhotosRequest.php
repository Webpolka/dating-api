<?php

namespace App\Http\Requests\Api\V1\Admin\Main;

/**
 * Параметры для таба «Фото» пользователя.
 *
 * GET /api/admin/users/{user}/photos
 *
 * Таб БЕЗ модерации — группировка по альбомам (один альбом = одна карточка).
 * Без фильтра статусов и без пагинации (альбомов у юзера немного — все разом).
 * Родительский UserListRequest даёт page/per_page, но метод photos() их не
 * использует (отдаёт все альбомы целиком) — оставлены для совместимости с
 * общей фабрикой useUserListing на фронте.
 *
 * Модерация фото живёт в глобальной очереди /moderation/photos (со статусами
 * pending/approved/rejected/quarantine и масс-действиями). Здесь — только
 * просмотр фото, сгруппированных по альбомам.
 */
class UserPhotosRequest extends UserListRequest
{
    public function rules(): array
    {
        // Без status-фильтра — таб без модерации. page/per_page наследуем от UserListRequest
        // (не используются методом, но валидируются — на случай будущего расширения).
        return parent::rules();
    }
}
