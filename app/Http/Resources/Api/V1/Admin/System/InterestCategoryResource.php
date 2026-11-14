<?php

namespace App\Http\Resources\Api\V1\Admin\System;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Категория интересов для админки.
 *
 * Зеркало BlogCategoryResource, но для interest_categories.
 *
 * interests_count — from withCount в query (через whenCounted).
 * В store()/update() withCount не вызывается → interests_count будет 0
 * (по умолчанию в Resource). Это норм — фронт после создания перечитает список.
 */
class InterestCategoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'slug'            => $this->slug,
            'icon'            => $this->icon,
            'sort_order'      => (int) $this->sort_order,
            'interests_count' => $this->whenCounted('interests', fn () => (int) $this->interests_count, 0),
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];
    }
}
