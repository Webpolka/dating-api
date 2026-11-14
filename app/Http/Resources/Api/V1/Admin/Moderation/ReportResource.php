<?php

namespace App\Http\Resources\Api\V1\Admin\Moderation;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use App\Models\Photo;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Жалоба для модерации.
 *
 * Заменяет ReportController::formatReport().
 *
 * Особенности:
 *  - reportable_type — morphTo, конвертируем в class_basename для фронта
 *    ('App\Models\User' → 'User', 'App\Models\Photo' → 'Photo').
 *  - photo — инлайн фото (через ReportPhotoResource) ТОЛЬКО когда
 *    reportable_type=Photo и связь reportable загружена. Иначе null.
 *    В ReportController мы грузим photos отдельно для batch'а жалоб на фото
 *    и подменяем reportable через setRelation.
 *  - reporter / reported — через AdminUserBadgeResource::nullable + withRole.
 *    null-safe: reporter может быть null (анонимная жалоба), reported тоже
 *    (например, при жалобе на фото юзер может быть удалён к моменту модерации).
 */
class ReportResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'reason'          => $this->reason,
            'description'     => $this->description,
            'status'          => $this->status,
            'resolution'      => $this->resolution,
            'resolution_note' => $this->resolution_note,
            'reportable_type' => $this->reportable_type ? class_basename($this->reportable_type) : null,
            'reportable_id'   => $this->reportable_id,
            'created_at'      => $this->created_at?->toIso8601String(),
            'updated_at'      => $this->updated_at?->toIso8601String(),
            'reporter'        => $this->whenLoaded('reporter', fn () => $this->reporter
                ? AdminUserBadgeResource::nullable($this->reporter, withRole: true)
                : null),
            'reported'        => $this->whenLoaded('reported', fn () => $this->reported
                ? AdminUserBadgeResource::nullable($this->reported, withRole: true)
                : null),
            // photo — только для жалоб на фото. Связь reportable подменяется
            // в ReportController через setRelation (мы грузим Photo оптом для
            // batch'а жалоб на фото, чтобы избежать N+1).
            'photo' => $this->reportable_type === Photo::class && $this->relationLoaded('reportable') && $this->reportable
                ? (new ReportPhotoResource($this->reportable))->resolve($request)
                : null,
        ];
    }
}
