<?php

namespace App\Http\Resources\Api\V1\Admin\Moderation;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Мэтч (взаимная симпатия) для модерации.
 *
 * Заменяет DatingController::formatMatch().
 *
 * user1 / user2 — через AdminUserBadgeResource::nullable.
 * Статусы: 'active' | 'unmatched'.
 */
class UserMatchResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'status'       => $this->status,
            'unmatched_by' => $this->unmatched_by,
            'unmatched_at' => $this->unmatched_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),
            'user1'        => $this->whenLoaded('user1', fn () => $this->user1
                ? AdminUserBadgeResource::nullable($this->user1)
                : null),
            'user2'        => $this->whenLoaded('user2', fn () => $this->user2
                ? AdminUserBadgeResource::nullable($this->user2)
                : null),
        ];
    }
}
