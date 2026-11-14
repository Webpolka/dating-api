<?php

namespace App\Http\Resources\Api\V1\Admin\System;

use App\Models\Media;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Медиа-файл для списка (grid в админке).
 *
 * Заменяет inline-маппинг в MediaController::index().
 *
 * is_processing — bool, computed: type=image и variants пустой → ещё обрабатывается
 * Job'ой (ProcessMediaUploadJob). Когда Job завершится — variants заполнится.
 *
 * thumb_url / sm_url / lg_url — варианты, через Media::getVariantUrl().
 * url — accessor на модели (Storage::url(disk_path)).
 */
class MediaListItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'            => $this->id,
            'file_name'     => $this->file_name,
            'disk_path'     => $this->disk_path,
            'url'           => $this->url,
            'thumb_url'     => $this->getVariantUrl('thumb'),
            'sm_url'        => $this->getVariantUrl('sm'),
            'lg_url'        => $this->getVariantUrl('lg'),
            'collection'    => $this->collection,
            'is_processing' => $this->type === Media::TYPE_IMAGE && empty($this->variants),
            'size'          => $this->size,
            'mime_type'     => $this->mime_type,
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }
}
