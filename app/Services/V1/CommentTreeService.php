<?php

namespace App\Services\Moderation;

use App\Models\DiaryComment;
use App\Models\PhotoComment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Работа с деревьями комментариев (photo_comments / diary_comments).
 *
 * Заменяет дублировавшиеся приватные collectDescendantIds() и
 * buildCascadeLog() из PhotoCommentController и DiaryCommentController
 * (код был идентичен, отличался только класс модели).
 *
 * Оба дерева комментариев имеют одинаковую структуру:
 *   id, parent_id, deleted_at (SoftDeletes).
 */
class CommentTreeService
{
    /**
     * Допустимые классы комментариев (защита от произвольного класса в аргументе).
     */
    private const COMMENT_CLASSES = [
        PhotoComment::class,
        DiaryComment::class,
    ];

    /**
     * Обойти дерево комментариев вниз от корневых id и собрать ВСЕ потомков.
     *
     * BFS с защитой от циклов (visited) и батч-запросами по уровням
     * (один query на уровень дерева, а не на узел).
     *
     * @param  string $commentClass   PhotoComment::class | DiaryComment::class
     * @param  array  $rootIds        id корневых комментариев
     * @param  bool   $includeTrashed захватывать и soft-deleted потомков
     * @return int[]                  id всех потомков (без корней)
     */
    public function collectDescendantIds(string $commentClass, array $rootIds, bool $includeTrashed = false): array
    {
        if (!in_array($commentClass, self::COMMENT_CLASSES, true)) {
            throw new \InvalidArgumentException("Unsupported comment class: {$commentClass}");
        }

        if (empty($rootIds)) {
            return [];
        }

        $all = [];
        $queue = $rootIds;
        $visited = array_flip($rootIds);

        while (!empty($queue)) {
            /** @var Builder $query */
            $query = $commentClass::query()->whereIn('parent_id', $queue);
            if ($includeTrashed) {
                $query->withTrashed();
            }
            $childIds = $query->pluck('id')->all();

            $newChildIds = [];
            foreach ($childIds as $id) {
                if (!isset($visited[$id])) {
                    $visited[$id] = true;
                    $newChildIds[] = $id;
                    $all[] = $id;
                }
            }

            if (empty($newChildIds)) {
                break;
            }

            $queue = $newChildIds;
        }

        return $all;
    }

    /**
     * Компактное описание каскадного удаления для AdminLog
     * (не более 50 записей, дальше — флаг truncated).
     *
     * @param  Collection $descendants   коллекция моделей-потомков
     * @param  bool       $includeTrashed помечать was_trashed
     */
    public function buildCascadeLog(Collection $descendants, bool $includeTrashed = false): array
    {
        if ($descendants->isEmpty()) {
            return ['count' => 0, 'descendants' => []];
        }

        $list = [];
        foreach ($descendants as $desc) {
            $entry = [
                'id'        => $desc->id,
                'parent_id' => $desc->parent_id,
            ];
            if ($includeTrashed) {
                $entry['was_trashed'] = !empty($desc->deleted_at);
            }
            $list[] = $entry;
            if (count($list) >= 50) {
                break;
            }
        }

        return [
            'count'       => $descendants->count(),
            'descendants' => $list,
            'truncated'   => $descendants->count() > 50,
        ];
    }
}
