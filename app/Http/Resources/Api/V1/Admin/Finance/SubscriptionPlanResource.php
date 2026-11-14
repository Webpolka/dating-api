<?php

namespace App\Http\Resources\Api\V1\Admin\Finance;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Тариф подписки (Premium / VIP).
 *
 * Заменяет SubscriptionPlanController::formatPlan().
 *
 * Особенности:
 *  - price — кастится в float (храним в БД как numeric/decimal).
 *  - old_price — nullable float (для UI "старая цена зачёркнута").
 *  - apple_product_id / google_product_id — nullable string (для in-app purchases).
 *    В контроллере они нормализуются через ?: null — здесь просто отдаём как есть.
 */
class SubscriptionPlanResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                 => $this->id,
            'tier'               => $this->tier,
            'name'               => $this->name,
            'slug'               => $this->slug,
            'price'              => (float) $this->price,
            'old_price'          => $this->old_price !== null ? (float) $this->old_price : null,
            'currency'           => $this->currency,
            'duration_days'      => (int) $this->duration_days,
            'apple_product_id'   => $this->apple_product_id,
            'google_product_id'  => $this->google_product_id,
            'is_active'          => (bool) $this->is_active,
            'sort_order'         => (int) $this->sort_order,
            'created_at'         => $this->created_at?->toIso8601String(),
            // B2: updated_at — UI может показывать "последнее обновление"
            'updated_at'         => $this->updated_at?->toIso8601String(),
        ];
    }
} 