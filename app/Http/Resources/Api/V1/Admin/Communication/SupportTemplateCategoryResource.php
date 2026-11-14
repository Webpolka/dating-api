<?php

namespace App\Http\Resources\Api\V1\Admin\Communication;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Категория шаблонов поддержки.
 *
 * Заменяет SupportTemplateController::formatCategory() (~9 строк).
 *
 * templates_count — доступен только когда вызван withCount('templates')
 * в query. Через whenCounted() (Laravel 9+) — если не вызван, поле
 * отсутствует в ответе. Если вызван — отдаём int.
 *
 * is_system — true для системной категории "Общие" (slug=general|obshhie).
 * Защита от изменения slug или удаления этой категории — в контроллере.
 */
class SupportTemplateCategoryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'slug'       => $this->slug,
            'is_active'  => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
            'is_system'  => in_array($this->slug, ['general', 'obshhie']),
            // templates_count появляется в response ТОЛЬКО когда вызван
            // withCount('templates') в query. Иначе поле отсутствует.
            'templates_count' => $this->whenCounted('templates', fn () => (int) $this->templates_count),
        ];
    }
}
