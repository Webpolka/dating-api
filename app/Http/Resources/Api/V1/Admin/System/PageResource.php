<?php

namespace App\Http\Resources\Api\V1\Admin\System;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Статическая страница CMS.
 *
 * Заменяет inline-маппинг в PageController::index() и show().
 *
 * body — попадает в ответ ТОЛЬКО когда вызвано withDetails() (для show()).
 * В list-режиме body не нужен (большой HTML) — экономим трафик.
 *
 * meta_title — только в detail (через withDetails). В list отдаётся только
 * meta_description (короткое SEO-описание).
 *
 * Использование:
 *   // list:
 *   PageResource::collection($pages)
 *   // detail:
 *   (new PageResource($page))->withDetails()
 */
class PageResource extends JsonResource
{
    private bool $withDetails = false;

    public function withDetails(): static
    {
        $this->withDetails = true;
        return $this;
    }

    public function toArray($request): array
    {
        $data = [
            'id'               => $this->id,
            'title'            => $this->title,
            'slug'             => $this->slug,
            'meta_description' => $this->meta_description,
            'is_active'        => $this->is_active,
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];

        if ($this->withDetails) {
            $data['meta_title'] = $this->meta_title;
            $data['body']       = $this->body;
        }

        return $data;
    }
}
