<?php

namespace App\Http\Resources\Api\V1\Admin\System;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Детальный просмотр рассылки (для /broadcasts/{id}).
 *
 * Заменяет inline return в BroadcastController::show().
 *
 * В отличие от BroadcastListItemResource — здесь ПОЛНОЕ message (не обрезанное),
 * + email_body, action_url, target_audience (полный JSONB).
 *
 * target_user — null-safe. Подгружается в контроллере через setRelation.
 */
class BroadcastDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'title'           => $this->title,
            'type'            => $this->type,
            'message'         => $this->message,
            'email_body'      => $this->email_body,
            'action_url'      => $this->data['action_url'] ?? null,
            'scheduled_at'    => $this->scheduled_at?->toIso8601String(),
            'status'          => $this->status,
            'target_audience' => $this->target_audience,
            'target_user'     => $this->whenLoaded('targetUser', fn () => $this->targetUser
                ? AdminUserBadgeResource::nullable($this->targetUser)
                : null),
        ];
    }
} 