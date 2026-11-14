<?php

namespace App\Http\Resources\Api\V1\Admin\System;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Карточка рассылки для списка в админке.
 *
 * Заменяет inline-маппинг в BroadcastController::index() (бывший $broadcasts->map(...)).
 *
 * message — обрезается до 60 символов (через Str::limit) для превью в списке.
 * В detail (BroadcastDetailResource) — полное сообщение.
 *
 * target_user — null-safe. Связь НЕ на модели Broadcast (это JSONB-поле target_audience.user_id,
 * а не morphTo/BelongsTo). Поэтому target_user подгружается отдельно в контроллере через
 * batch-запрос и подменяется через setRelation(). Здесь — через $this->whenLoaded('targetUser').
 *
 * admin — BelongsTo на User (кто создал рассылку). Через whenLoaded.
 *
 * audience_label / audience_parts — accessor'ы на модели Broadcast (computed из target_audience).
 */
class BroadcastListItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                => $this->id,
            'title'             => $this->title,
            'message'           => \Illuminate\Support\Str::limit($this->message, 60),
            'type'              => $this->type,
            'status'            => $this->status,
            'progress'          => $this->progress,
            'sent_count'        => $this->sent_count,
            'failed_count'      => $this->failed_count,
            'total_recipients'  => $this->total_recipients,
            'scheduled_at'      => $this->scheduled_at?->toIso8601String(),
            'sent_at'           => $this->sent_at?->toIso8601String(),
            'started_at'        => $this->started_at?->toIso8601String(),
            'created_at'        => $this->created_at?->toIso8601String(),
            'audience_label'    => $this->audience_label,
            'audience_parts'    => $this->audience_parts,
            // target_user — подменяется в контроллере через setRelation('targetUser', $user)
            'target_user'       => $this->whenLoaded('targetUser', fn () => $this->targetUser
                ? AdminUserBadgeResource::nullable($this->targetUser)
                : null),
            'admin'             => $this->whenLoaded('admin', fn () => $this->admin
                ? AdminUserBadgeResource::nullable($this->admin)
                : null),
        ];
    }
} 