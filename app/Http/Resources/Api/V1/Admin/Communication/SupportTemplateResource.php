<?php

namespace App\Http\Resources\Api\V1\Admin\Communication;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Шаблон ответа поддержки.
 *
 * Заменяет SupportTemplateController::formatTemplate() (~17 строк).
 *
 * category — null-safe через whenLoaded: если связь не загружена,
 * поле отсутствует в ответе. Если загружена но равна null (шаблон без
 * категории) — вернёт null. Это различие важно: при создании/обновлении
 * мы eager-load'им category, в list'е тоже — category всегда есть в ответе.
 */
class SupportTemplateResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'          => $this->id,
            'title'       => $this->title,
            'body'        => $this->body,
            'is_active'   => (bool) $this->is_active,
            'sort_order'  => (int) $this->sort_order,
            'category_id' => $this->category_id,
            'category'    => $this->whenLoaded('category', fn () => $this->category ? [
                'id'   => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ] : null),
            'created_at'  => $this->created_at?->toIso8601String(),
            'updated_at'  => $this->updated_at?->toIso8601String(),
        ];
    }
}
