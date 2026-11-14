<?php

namespace App\Http\Resources\Api\V1\Admin\System;

use App\Models\Setting;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Настройка системы (Setting).
 *
 * Заменяет inline-маппинг в SettingController::index().
 *
 * Особенности:
 *  - value — type-casted по type поля:
 *    * TYPE_BOOLEAN → (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN)
 *    * TYPE_INTEGER → (int) $value
 *    * TYPE_JSON → string (json_encode) если value это array, иначе string as-is
 *    * TYPE_STRING (default) → string as-is
 *  Это чтобы фронт получал консистентные типы — boolean для toggle, int для
 *  number-input, string для text, JSON-string для textarea (фронт парсит).
 */
class SettingResource extends JsonResource
{
    public function toArray($request): array
    {
        $value = $this->value;

        if ($this->type === Setting::TYPE_BOOLEAN) {
            $value = (bool) filter_var($value, FILTER_VALIDATE_BOOLEAN);
        } elseif ($this->type === Setting::TYPE_INTEGER) {
            $value = (int) $value;
        } elseif ($this->type === Setting::TYPE_JSON) {
            // JSON храним как string — фронт парсит сам (для textarea-редактора).
            $value = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return [
            'id'          => $this->id,
            'key'         => $this->key,
            'value'       => $value,
            'type'        => $this->type,
            'label'       => $this->label,
            'description' => $this->description,
            'options'     => $this->options,
            'is_public'   => $this->is_public,
        ];
    }
}
