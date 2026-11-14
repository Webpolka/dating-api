<?php

namespace App\Http\Resources\Api\V1\Admin\Moderation;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Упрощённое фото для инлайна в ReportResource (когда жалоба на фото).
 *
 * Это НЕ полный PhotoResource — только 4 поля + user. Сделан отдельный,
 * чтобы не менять формат API-ответа ReportController и не сломать фронт.
 *
 * Заменяет ReportController::formatPhoto().
 */
class ReportPhotoResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'thumb_url'    => $this->thumb_url,
            'medium_url'   => $this->medium_url,
            'original_url' => $this->original_url,
            'user'         => $this->whenLoaded('user', fn () => $this->user
                ? AdminUserBadgeResource::nullable($this->user, withRole: true)
                : null),
        ];
    }
}
