<?php

namespace App\Http\Resources\Api\V1\Admin\Moderation;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Фото для модерации (grid mode) и для инлайн-вставки в ReportResource.
 *
 * Заменяет PhotoController::formatPhoto() и ReportController::formatPhoto().
 *
 * user — через whenLoaded (null-safe): если связь не загружена — поле
 * отсутствует. В ReportController мы грузим user отдельно для жалоб на фото.
 *
 * trashed — bool, computed на лету из $p->trashed() (SoftDeletes).
 *
 * album — null-safe через whenLoaded.
 */
class PhotoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'            => $this->id,
            'user_id'       => $this->user_id,
            'status'        => $this->status,
            'is_primary'    => (bool) $this->is_primary,
            'is_intimate'   => (bool) $this->is_intimate,
            'type'          => $this->type,
            'reject_reason' => $this->reject_reason,
            'trashed'       => $this->trashed(),
            'moderated_at'  => $this->moderated_at?->toIso8601String(),
            'created_at'    => $this->created_at?->toIso8601String(),
            'updated_at'    => $this->updated_at?->toIso8601String(),
            'thumb_url'     => $this->thumb_url,
            'medium_url'    => $this->medium_url,
            'large_url'     => $this->large_url,
            'original_url'  => $this->original_url,
            'album'         => $this->whenLoaded('album', fn () => $this->album ? [
                'id'   => $this->album->id,
                'name' => $this->album->name,
            ] : null),
            // user — null-safe. В ReportController user подгружается отдельно.
            // withRole=true — в модерации важно видеть role (staff/user).
            'user' => $this->whenLoaded('user', fn () => $this->user
                ? AdminUserBadgeResource::nullable($this->user, withRole: true)
                : null),
        ];
    }
} 