<?php

namespace App\Http\Resources\Api\V1\Admin\System;

use App\Enums\V1\MediaCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Детальный просмотр медиа (для /media/{id}).
 *
 * Заменяет inline return в MediaController::show().
 *
 * В отличие от MediaListItemResource — здесь:
 *  - variants[] — массив с конфигурацией каждого варианта (size, fit, format)
 *  - collection_label — label из enum MediaCollection
 *
 * variants[] строится из $media->variants (JSONB-поле) + конфига media.php:
 *   config("media.collections.{collection}.variants") — описания вариантов (size, fit, format).
 */
class MediaDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        $variants = [];
        if (!empty($this->variants)) {
            $config = config("media.collections.{$this->collection}.variants", []);
            foreach ($this->variants as $key => $path) {
                $variants[] = [
                    'key'    => $key,
                    'url'    => Storage::url($path),
                    'size'   => $config[$key]['size'] ?? '—',
                    'fit'    => $config[$key]['fit'] ?? '—',
                    'format' => $config[$key]['format'] ?? '—',
                ];
            }
        }

        $collectionEnum = MediaCollection::tryFrom($this->collection);

        return [
            'id'                => $this->id,
            'file_name'         => $this->file_name,
            'collection'        => $this->collection,
            'collection_label'  => $collectionEnum?->label() ?? $this->collection,
            'size'              => $this->size,
            'mime_type'         => $this->mime_type,
            'created_at'        => $this->created_at?->toIso8601String(),
            'variants'          => $variants,
            'is_processing'     => empty($this->variants),
            'lg_url'            => $this->getVariantUrl('lg'),
            'thumb_url'         => $this->getVariantUrl('thumb'),
        ];
    }
}
