<?php

namespace App\Http\Resources\Api\V1\Admin\Moderation;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Карточка юзера с pending-фото для очереди модерации (queue mode).
 *
 * Используется в PhotoController::index() когда status=pending и нет поиска.
 * Возвращает: { user_badge, pending_photos_count, photos[] }.
 *
 * Заменяет PhotoController::formatUserCard().
 *
 * ВАЖНО: photos здесь — только pending фото (фильтр в query), через
 * whenLoaded. pending_photos_count — from withCount, через whenCounted.
 */
class PhotoModerationCardResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                   => $this->id,
            'name'                 => $this->name,
            'email'                => $this->email,
            'avatar_url'           => $this->avatar_url,
            'is_online'            => $this->is_online,
            'status'               => $this->status,
            'deleted_at'           => $this->deleted_at?->toIso8601String(),
            'has_active_premium'   => $this->has_active_premium,
            'has_active_vip'       => $this->has_active_vip,
            'is_verified'          => $this->is_verified,
            'created_at'           => $this->created_at?->toIso8601String(),
            // from withCount('photos as pending_photos_count')
            'pending_photos_count' => $this->whenCounted('photos', fn () => (int) $this->pending_photos_count, 0),
            // pending photos — загружены в query с фильтром status=pending
            'photos' => $this->whenLoaded('photos', fn () => PhotoResource::collection($this->photos)->resolve($request)),
        ];
    }
}
