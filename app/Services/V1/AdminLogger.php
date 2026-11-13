<?php

namespace App\Services\V1;

use App\Models\DiaryComment;
use App\Models\PhotoComment;
use App\Models\User;
use App\Models\AdminLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Единая точка записи в admin_logs для сценариев модерации.
 *
 * Заменяет приватные logAction() хелперы, которые копипастились
 * между PhotoCommentController и DiaryCommentController (отличались
 * только именем поля родителя: photo_id / diary_id).
 *
 * Формат after-стейта сохранён 1:1 с исходными хелперами, чтобы
 * записи в журнале остались совместимы с существующим фронтом
 * админки (AdminLogController рендерит их как есть).
 */
class AdminLogger
{
    /**
     * Логирование модерации комментария (photo или diary).
     *
     * @param string  $action        имя действия, напр. 'photo_comment.approve'
     * @param Model   $comment       PhotoComment|DiaryComment
     * @param User    $admin         кто модерировал
     * @param array   $before        снапшот состояния ДО изменения
     * @param string  $afterStatus   статус ПОСЛЕ ('approved'|'rejected'|...)
     * @param array   $extraContext  доп. поля в context (bulk-контекст и т.п.)
     */
    public function logCommentModeration(
        string $action,
        Model $comment,
        User $admin,
        array $before,
        string $afterStatus,
        array $extraContext = [],
    ): void {
        $isDiary = $comment instanceof DiaryComment;

        $after = array_merge([
            'status'       => $afterStatus,
            'moderated_by' => $admin->id,
            'moderated_at' => now()->toDateTimeString(),
            'context'      => [
                'comment_id' => $comment->id,
                $isDiary ? 'diary_id' : 'photo_id' => $isDiary ? $comment->diary_id : $comment->photo_id,
                'author_id'  => $comment->user_id,
                'snippet'    => Str::limit($comment->content, 50),
            ],
        ], $extraContext);

        $parent = $isDiary ? $comment->diary : $comment->photo;
        $participants = array_filter([$comment->user_id, $parent?->user_id]);

        AdminLog::record($action, $comment, $admin, $before, $after, participants: $participants);
    }
}
