<?php

namespace App\Http\Resources\Api\V1\Admin\System;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GeoIP локация (страна / регион / город) для управления блокировками.
 *
 * Заменяет GeoIPLocationController::formatItem().
 *
 * parent_name — computed: parent.name_ru ?: parent.name (или null если нет parent).
 * name — computed: name_ru ?: name (русское название или английское как fallback).
 * original_name — оригинальное английское name (для админки, чтобы видеть оба).
 */
class GeoIPLocationResource extends JsonResource
{
    public function toArray($request): array
    {
        // parent уже загружен через with('parent') в controller — без N+1.
        $parentName = null;
        if ($this->parent_id && $this->parent) {
            $parentName = $this->parent->name_ru ?: $this->parent->name;
        }

        return [
            'id'                      => $this->id,
            'type'                    => $this->type,
            'name'                    => $this->name_ru ?: $this->name,
            'original_name'           => $this->name,
            'iso_code'                => $this->iso_code,
            'latitude'                => $this->latitude,
            'longitude'               => $this->longitude,
            'parent_name'             => $parentName,
            'is_registration_blocked' => (bool) $this->is_registration_blocked,
            'is_feed_blocked'         => (bool) $this->is_feed_blocked,
        ];
    }
} 