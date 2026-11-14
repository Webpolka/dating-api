<?php

namespace App\Http\Resources\Api\V1\Admin\Moderation;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Дневник с деревом комментариев — для списка модерации DiaryCommentController::index().
 *
 * Заменяет DiaryCommentController::formatDiaryWithComments().
 *
 * Возвращает: { diary_id, title, diary_rubric, user, comments[] }.
 * Это урезанная версия DiaryResource — без body, без counters, без status,
 * только то что нужно для отображения карточки поста с комментариями.
 */
class DiaryWithCommentsResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'    => $this->id,
            'title' => $this->title,
            'diary_rubric' => $this->whenLoaded('diaryRubric', fn () => $this->diaryRubric ? [
                'id'          => $this->diaryRubric->id,
                'name'        => $this->diaryRubric->name,
                'is_active'   => (bool) $this->diaryRubric->is_active,
                'is_personal' => $this->diaryRubric->user_id !== null,
                'user_id'     => $this->diaryRubric->user_id,
            ] : null),
            'user' => $this->whenLoaded('user', fn () => $this->user
                ? AdminUserBadgeResource::nullable($this->user, withRole: true)
                : null),
            // comments — корневые (parent_id=null), через DiaryCommentResource (рекурсивный).
            'comments' => DiaryCommentResource::collection($this->comments ?? collect())->resolve($request),
        ];
    }
}
