<?php

namespace App\Http\Resources\Api\V1\Admin\Moderation;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Рубрика дневника для модерации (только системные — user_id=null).
 *
 * Заменяет DiaryController::formatRubric().
 *
 * diaries_count — from withCount в query, через whenCounted.
 *
 * user — всегда null для системных рубрик (user_id IS NULL), но поле оставлено
 * для совместимости с фронтом (он может ожидать user: null).
 */
class DiaryRubricResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'slug'          => $this->slug,
            'description'   => $this->description,
            'is_active'     => (bool) $this->is_active,
            'sort_order'    => (int) $this->sort_order,
            'user_id'       => $this->user_id,
            'diaries_count' => $this->whenCounted('diaries', fn () => (int) $this->diaries_count, 0),
            // user — всегда null для системных рубрик. Поле оставлено для
            // совместимости со старым API — фронт может ждать.
            'user'          => null,
        ];
    }
}
