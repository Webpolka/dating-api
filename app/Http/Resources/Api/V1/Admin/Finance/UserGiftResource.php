<?php

namespace App\Http\Resources\Api\V1\Admin\Finance;

use App\Enums\V1\GiftCategory;
use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Запись из истории дарений (UserGift).
 *
 * Заменяет GiftController::formatUserGift().
 *
 * Особенности:
 *  - snapshot_* — снапшот состояния подарка на момент дарения (имя, изображение, цена).
 *    Сохраняется в UserGift, чтобы даже если подарок из каталога изменится/удалится,
 *    история оставалась корректной. snapshot_image_url — accessor на UserGift.
 *  - is_hidden — bool, computed из $ug->trashed() (SoftDeletes).
 *  - gift — связь на текущий Gift (для category_label/color). null-safe через whenLoaded.
 *  - sender / receiver — AdminUserBadgeResource::nullable (без withRole, как в старом коде).
 */
class UserGiftResource extends JsonResource
{
    public function toArray($request): array
    {
        $catEnum = $this->gift ? GiftCategory::tryFrom($this->gift->category ?? '') : null;

        return [
            'id'                 => $this->id,
            'snapshot_name'      => $this->snapshot_name,
            'snapshot_image_url' => $this->image_url, // accessor UserGift
            'snapshot_price'     => (int) $this->snapshot_price,
            'message'            => $this->message,
            'is_private'         => (bool) $this->is_private,
            'is_read'            => (bool) $this->is_read,
            'is_hidden'          => $this->trashed(), // true если soft-deleted
            'created_at'         => $this->created_at?->toIso8601String(),
            'sender'             => $this->whenLoaded('sender', fn () => $this->sender
                ? AdminUserBadgeResource::nullable($this->sender)
                : null),
            'receiver'           => $this->whenLoaded('receiver', fn () => $this->receiver
                ? AdminUserBadgeResource::nullable($this->receiver)
                : null),
            'gift' => $this->whenLoaded('gift', fn () => $this->gift ? [
                'id'              => $this->gift->id,
                'name'            => $this->gift->name,
                'image_url'       => $this->gift->image_url,
                'type'            => $this->gift->type,
                'category'        => $this->gift->category,
                'category_label'  => $catEnum?->label(),
                'category_color'  => $catEnum?->color(),
            ] : null),
        ];
    }
} 