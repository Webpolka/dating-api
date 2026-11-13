<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Ресурс фото для админ-таба пользователя.
 *
 * Заменяет приватный PhotoController::formatPhoto() (который остаётся
 * для глобальной очереди модерации /admin/photos — там немного другая
 * структура с режимами queue/grid).
 *
 * Поля выровнены по формату, который уже потребляет фронтовый
 * PhotoCard (hooks/admin/use-photos.ts → PhotoItem):
 *   - status, is_primary, is_intimate, type, reject_reason, trashed
 *   - moderated_at, created_at, updated_at (ISO-8601)
 *   - thumb_url, medium_url, large_url, original_url — accessor'ы из Photo::$appends
 *   - album: {id, name} | null
 *
 * ВАЖНО про accessor'ы URL: thumb_url/medium_url/large_url/original_url
 * объявлены в Photo::$appends и вычисляются через getThumbUrlAttribute() и т.д.
 * (читают path_thumb/path_medium/path_large/path_original, с фоллбэком).
 * Селектить их в SQL КАТЕГОРИЧЕСКИ нельзя — это не колонки БД, и PostgreSQL
 * упадёт с `column "thumb_url" does not exist` (тот класс бага, что фикшен
 * в UserController::PHOTO_AVATAR_COLUMNS). В контроллере photos()
 * селектим только реальные колонки — accessor'ы отработают сами.
 *
 * `user` НЕ включаем: в per-user табе пользователь и так известен
 * (он в шапке страницы), тянуть его на каждое фото — лишний шум.
 * Глобальной очереди нужен user на карточке (PhotoController::formatPhoto
 * с withUser=true), здесь — нет.
 */
class PhotoResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var \App\Models\Photo $p */
        $p = $this->resource;

        return [
            'id'            => $p->id,
            'user_id'       => $p->user_id,
            'status'        => $p->status,
            'is_primary'    => (bool) $p->is_primary,
            'is_intimate'   => (bool) $p->is_intimate,
            'type'          => $p->type,
            'reject_reason' => $p->reject_reason,
            'trashed'       => $p->trashed(),
            'moderated_at'  => $p->moderated_at?->toIso8601String(),
            'created_at'    => $p->created_at?->toIso8601String(),
            'updated_at'    => $p->updated_at?->toIso8601String(),
            // URL accessor'ы из $appends — вычисляются в PHP из path_* колонок.
            'thumb_url'     => $p->thumb_url,
            'medium_url'    => $p->medium_url,
            'large_url'     => $p->large_url,
            'original_url'  => $p->original_url,
            'album'         => $p->album ? ['id' => $p->album->id, 'name' => $p->album->name] : null,
        ];
    }
}
