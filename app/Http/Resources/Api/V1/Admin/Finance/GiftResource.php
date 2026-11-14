<?php

namespace App\Http\Resources\Api\V1\Admin\Finance;

use App\Enums\V1\GiftCategory;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Подарок из каталога (Gift).
 *
 * Заменяет GiftController::formatGift().
 *
 * Особенности:
 *  - raw_image — РЕАЛЬНЫЙ путь в storage (через getRawOriginal), для UI редактирования.
 *    В отличие от image_url (accessor), который отдаёт полный URL через Storage::url().
 *    Оба поля в ответе — фронт использует image_url для отображения, raw_image для формы.
 *  - category_label / category_color — computed через GiftCategory enum.
 *    Если категория невалидная (tryFrom вернёт null) — поля null.
 */
class GiftResource extends JsonResource
{
    public function toArray($request): array
    {
        $catEnum = GiftCategory::tryFrom($this->category ?? '');

        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'slug'            => $this->slug,
            'image_url'       => $this->image_url, // accessor: полный URL через Storage::url()
            'raw_image'       => $this->getRawOriginal('image_url'), // путь в storage (для формы редактирования)
            'price'           => (int) $this->price,
            'category'        => $this->category,
            'category_label'  => $catEnum?->label(),
            'category_color'  => $catEnum?->color(),
            'type'            => $this->type,
            'is_active'       => (bool) $this->is_active,
            'created_at'      => $this->created_at?->toIso8601String(),
            // B3: updated_at — для UI "последнее обновление"
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }
}
