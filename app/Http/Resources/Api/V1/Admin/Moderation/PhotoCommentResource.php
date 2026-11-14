<?php

namespace App\Http\Resources\Api\V1\Admin\Moderation;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Комментарий к фото (рекурсивный — поддерживает replies[]).
 *
 * Заменяет PhotoCommentController::formatComment().
 *
 * Рекурсия: replies грузится через связь allReplies, мапится в себя же
 * через PhotoCommentResource::collection(). Глубина не ограничена —
 * но в DataLoader обычно 2 уровня.
 *
 * parent — сводка о родителе (id, status, trashed) для фронта:
 *   - прятать кнопку restore/approve на ребёнке, если родитель rejected/trashed.
 *   через whenLoaded('parent').
 *
 * user — null-safe через whenLoaded + AdminUserBadgeResource::nullable.
 *
 * likes_count / replies_count — from withCount в query, через whenCounted.
 */
class PhotoCommentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'            => $this->id,
            'photo_id'      => $this->photo_id,
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
            'is_pinned'     => (bool) $this->is_pinned,
            'likes_count'   => $this->whenCounted('likes', fn () => (int) $this->likes_count, 0),
            'replies_count' => $this->whenCounted('replies', fn () => (int) $this->replies_count, 0),
            // parent — сводка для фронта (guard clickable кнопок на ребёнке).
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