<?php

namespace App\Http\Resources\Api\V1\Admin\Moderation;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Фото с деревом комментариев — для списка модерации PhotoCommentController::index().
 *
 * Заменяет PhotoCommentController::formatPhotoWithComments().
 *
 * Возвращает: { photo fields, user, pending_count, comments[] }.
 * comments — корневые (parent_id=null), у каждого replies[] через PhotoCommentResource.
 *
 * pending_count — считаем на коллекции (sum comments + flatMap replies с status=pending).
 * Это computed поле (не из БД), считаем здесь, не в query.
 */
class PhotoWithCommentsResource extends JsonResource
{
    public function toArray($request): array
    {
        $comments = $this->comments ?? collect();

        // Считаем pending на коллекции (комментарии + их ответы).
        // Это быстрее чем отдельный SQL, т.к. данные уже загружены.
        $pendingCount = $comments->where('status', \App\Models\PhotoComment::STATUS_PENDING)->count()
            + $comments->flatMap->allReplies->where('status', \App\Models\PhotoComment::STATUS_PENDING)->count();

        return [
            'id'            => $this->id,
            'user_id'       => $this->user_id,
            'is_primary'    => (bool) $this->is_primary,
            'is_intimate'   => (bool) $this->is_intimate,
            'status'        => $this->status,
            'trashed'       => $this->trashed(),
            'thumb_url'     => $this->thumb_url,
            'medium_url'    => $this->medium_url,
            'large_url'     => $this->large_url,
            'original_url'  => $this->original_url,
            'album'         => $this->whenLoaded('album', fn () => $this->album ? [
                'id'   => $this->album->id,
                'name' => $this->album->name,
            ] : null),
            'user'          => $this->whenLoaded('user', fn () => $this->user
                ? AdminUserBadgeResource::nullable($this->user, withRole: true)
                : null),
            'pending_count' => $pendingCount,
            // comments — корневые, через PhotoCommentResource (рекурсивный).
            'comments' => PhotoCommentResource::collection($comments)->resolve($request),
        ];
    }
} 