<?php

namespace App\Http\Resources\Api\V1\Admin\System;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Интерес для админки.
 *
 * Зеркало BlogPostResource (list-режим), но проще — без body/cover/author.
 *
 * category — from whenLoaded('category'); в list/index подгружаем всегда.
 * users_count — обновляется триггером user_interests_update_count.
 * search_boost — ручной буст выдачи (админ поднимает топ-интересы).
 */
class InterestResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'slug'         => $this->slug,
            'category_id'  => $this->category_id,
            'category'     => $this->whenLoaded('category', fn () => $this->category ? [
                'id'   => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ] : null),
            'search_boost' => (int) $this->search_boost,
            'users_count'  => (int) $this->users_count,
            'created_at'   => $this->created_at?->toIso8601String(),
            'updated_at'   => $this->updated_at?->toIso8601String(),
        ];
    }
}
