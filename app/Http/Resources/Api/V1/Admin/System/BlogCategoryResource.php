<?php

namespace App\Http\Resources\Api\V1\Admin\System;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Категория блога.
 *
 * Заменяет inline-маппинг в BlogCategoryController::index() и store()/update().
 *
 * posts_count — from withCount в query, через whenCounted. В store()/update()
 * withCount не вызывается → posts_count будет 0 (по умолчанию в Resource).
 */
class BlogCategoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'slug'        => $this->slug,
            'is_active'   => (bool) $this->is_active,
            'sort_order'  => (int) $this->sort_order,
            'posts_count' => $this->whenCounted('posts', fn () => (int) $this->posts_count, 0),
        ];
    }
} 