<?php

namespace App\Http\Resources\Api\V1\Admin\Communication;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Стоп-слово для модерационного фильтра контента.
 *
 * Заменяет StopWordController::formatWord() (~13 строк).
 *
 * Особенности:
 *  - category / action — PHP-enum, в JSON уходит как value (строка) +
 *    отдельное *_label поле для UI-рендера (человекочитаемое).
 *  - action_variant — из accessor'а actionBadge на модели StopWord,
 *    возвращает {variant: 'red'|'orange'|'blue'} для цветового бейджа
 *    в UI (зависит от action: reject/mask/alert).
 */
class StopWordResource extends JsonResource
{
    public function toArray($request): array
    {
        $actionBadge = $this->actionBadge;

        return [
            'id'             => $this->id,
            'word'           => $this->word,
            'category'       => $this->category->value,
            'category_label' => $this->category->label(),
            'action'         => $this->action->value,
            'action_label'   => $this->action->label(),
            'action_variant' => $actionBadge['variant'],
            'replacement'    => $this->replacement,
            'is_active'      => (bool) $this->is_active,
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
