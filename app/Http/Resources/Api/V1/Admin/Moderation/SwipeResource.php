<?php

namespace App\Http\Resources\Api\V1\Admin\Moderation;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Свайп (like / dislike / superlike) для модерации.
 *
 * Заменяет DatingController::formatSwipe().
 *
 * rewinded — bool, computed на лету из $s->rewinded_at (если не null — был rewind).
 * user / targetUser — через AdminUserBadgeResource::nullable.
 */
class SwipeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'         => $this->id,
            'type'       => $this->type,
            'rewinded'   => (bool) $this->rewinded_at,
            'created_at' => $this->created_at?->toIso8601String(),
            'user'       => $this->whenLoaded('user', fn () => $this->user
                ? AdminUserBadgeResource::nullable($this->user)
                : null),
            'targetUser' => $this->whenLoaded('targetUser', fn () => $this->targetUser
                ? AdminUserBadgeResource::nullable($this->targetUser)
                : null),
        ];
    }
}
