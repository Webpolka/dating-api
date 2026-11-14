<?php

namespace App\Http\Resources\Api\V1\Admin\System;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Блог-пост для модерации.
 *
 * Заменяет BlogPostController::formatPost($post, $detailed).
 *
 * detail-поля (через withDetails()):
 *  - body — HTML контент поста (тяжёлый, не нужен в list).
 *  - cover_sm_url — средний размер обложки (для detail-режима).
 *  - author — {id, name} (для detail; в list не отдаём).
 *
 * status_label / status_variant — from $post->status_badge accessor
 * (computed match на модели BlogPost).
 *
 * cover_url — accessor на BlogPost.
 * cover_thumb — cover?->getVariantUrl('thumb') — может быть null если cover не загружен.
 *
 * Использование:
 *   // list:
 *   BlogPostResource::collection($posts)
 *   // detail:
 *   (new BlogPostResource($post))->withDetails()
 */
class BlogPostResource extends JsonResource
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
            'id'              => $this->id,
            'title'           => $this->title,
            'slug'            => $this->slug,
            'excerpt'         => $this->excerpt,
            'status'          => $this->status,
            'status_label'    => $this->status_badge['label'],
            'status_variant'  => $this->status_badge['variant'],
            'is_featured'     => $this->is_featured,
            'views_count'     => $this->views_count,
            'category_id'     => $this->category_id,
            'cover_media_id'  => $this->cover_media_id,
            'category'        => $this->whenLoaded('category', fn () => $this->category ? [
                'id'   => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ] : null),
            'cover_url'       => $this->cover_url,
            'cover_thumb'     => $this->whenLoaded('cover', fn () => $this->cover?->getVariantUrl('thumb')),
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
        ];

        if ($this->withDetails) {
            $data['body']         = $this->body;
            $data['cover_sm_url'] = $this->whenLoaded('cover', fn () => $this->cover?->getVariantUrl('sm'));
            $data['author']       = $this->whenLoaded('author', fn () => $this->author ? [
                'id'   => $this->author->id,
                'name' => $this->author->name,
            ] : null);
        }

        return $data;
    }
} 