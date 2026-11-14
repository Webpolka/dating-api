<?php

namespace App\Http\Resources\Api\V1\Admin\Moderation;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Комментарий к дневнику (рекурсивный — поддерживает replies[]).
 *
 * Заменяет DiaryCommentController::formatComment().
 *
 * Структура полностью аналогична PhotoCommentResource — отличается только
 * diary_id вместо photo_id, и нет is_pinned.
 */
class DiaryCommentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'            => $this->id,
            'diary_id'      => $this->diary_id,
            'user_id'       => $this->user_id,
            'parent_id'     => $this->parent_id,
            'content'       => $this->content,
            'status'        => $this->status,
            'reject_reason' => $this->reject_reason,
            'moderated_by'  => $this->moderated_by,
            'moderated_at'  => $this->moderated_at?->toIso8601String(),
            'created_at'    => $this->created_at?->toIso8601String(),
            'updated_at'    => $this->updated_at?->toIso8601String(),
            'trashed'       => $this->trashed(),
            'likes_count'   => $this->whenCounted('likes', fn () => (int) $this->likes_count, 0),
            'replies_count' => $this->whenCounted('replies', fn () => (int) $this->replies_count, 0),
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                'id'      => $this->parent->id,
                'status'  => $this->parent->status,
                'trashed' => $this->parent->trashed(),
            ] : null),
            'user' => $this->whenLoaded('user', fn () => $this->user
                ? AdminUserBadgeResource::nullable($this->user, withRole: true)
                : null),
            // Рекурсия: replies мапятся в себя же.
            'replies' => $this->whenLoaded('allReplies', fn () =>
                self::collection($this->allReplies)->resolve($request)),
        ];
    }
} 