<?php

namespace App\Http\Resources\Api\V1\Admin\Moderation;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Запись дневника для модерации.
 *
 * Заменяет DiaryController::formatDiary($d, $detailed).
 *
 * body — попадает в ответ ТОЛЬКО в detailed mode. Реализовано через
 * $this->resource->body — оно всегда есть на модели, но в list мы делаем
 * ->select([... без body]) чтобы не тянуть тяжёлый текст. Здесь просто
 * всегда возвращаем body если оно не null — для list оно будет null после
 * select без него, для show — реальное значение.
 *
 * Точнее: Laravel не вычисляет поля не из select, поэтому body будет null
 * после ->select(['id', 'title', ...]) без body. Это и есть наш флаг detailed.
 *
 * diary_rubric — через whenLoaded.
 * user — через whenLoaded + AdminUserBadgeResource::nullable(withRole:true).
 *
 * counters (views/comments/likes) — from withCount в query, через whenCounted.
 * Если withCount не вызывался — поле отсутствует (для list'а они могут быть не нужны).
 */
class DiaryResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                  => $this->id,
            'title'               => $this->title,
            // body будет null в list (без select body) — фронт игнорирует.
            // В show() body загружается — фронт показывает текст.
            'body'                => $this->body,
            'status'              => $this->status,
            'reject_reason'       => $this->reject_reason,
            'moderated_by'        => $this->moderated_by,
            'moderated_at'        => $this->moderated_at?->toIso8601String(),
            'published_at'        => $this->published_at?->toIso8601String(),
            'created_at'          => $this->created_at?->toIso8601String(),
            'updated_at'          => $this->updated_at?->toIso8601String(),
            'trashed'             => $this->trashed(),
            'is_comments_enabled' => (bool) $this->is_comments_enabled,
            // views_count / comments_count / likes_count — это КОЛОНКИ БД (не связи),
            // инкрементируются через Diary::incrementViews/incrementLikes и триггеры.
            // Доступны всегда без eager-loading.
            'views_count'         => (int) $this->views_count,
            'comments_count'      => (int) $this->comments_count,
            'likes_count'         => (int) $this->likes_count,
            'user' => $this->whenLoaded('user', fn () => $this->user
                ? AdminUserBadgeResource::nullable($this->user, withRole: true)
                : null),
            'diary_rubric' => $this->whenLoaded('diaryRubric', fn () => $this->diaryRubric ? [
                'id'          => $this->diaryRubric->id,
                'name'        => $this->diaryRubric->name,
                'is_active'   => (bool) $this->diaryRubric->is_active,
                'is_personal' => $this->diaryRubric->user_id !== null,
                'user_id'     => $this->diaryRubric->user_id,
            ] : null),
        ];
    }
} 