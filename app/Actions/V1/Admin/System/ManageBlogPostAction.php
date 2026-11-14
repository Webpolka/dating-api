<?php

namespace App\Actions\V1\Admin\System;

use App\Models\AdminLog;
use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Запись блог-постов (CRUD + duplicate/toggle/archive/restore/setFeatured/bulk)
 * (thin-controller pattern). Извлечена из Admin\V1\Content\BlogPostController (S5-6).
 *
 * КОНТРАКТ ВОЗВРАТА:
 *   - store / update / duplicate возвращают модель BlogPost с загруженными
 *     ['category', 'cover'] — контроллер форматирует её приватным formatPost().
 *   - setFeatured возвращает $post->refresh() — контроллер читает is_featured
 *     для тела ответа.
 *   - toggle возвращает $post->refresh() — контроллер читает status.
 *   - archive / restore / destroy / bulk — void; тело ответа фиксировано,
 *     контроллер строит его сам (включая guard'ы «уже в архиве» / «не в архиве»
 *     и 422 «удалять можно только из архива»).
 *
 * setFeatured: DB::transaction + lockForUpdate — между
 * `where('is_featured', true)->update(false)` и `$post->update(true)` мог
 * вклиниться другой запрос → два featured поста.
 *
 * Логирование: AdminLog с action'ами blog.create / blog.update / blog.delete /
 * blog.bulk_delete / blog.bulk_update — форматы before/after как в исходном
 * контроллере.
 */
class ManageBlogPostAction
{
    // ============================================
    // CRUD
    // ============================================

    /**
     * Создать пост.
     *
     * @param  User  $admin     Автор-админ.
     * @param  array $validated Данные BlogPostRequest.
     * @return BlogPost Модель с загруженными ['category', 'cover'].
     */
    public function store(User $admin, array $validated): BlogPost
    {
        $data = $validated;
        $data['user_id'] = $admin->id;

        if (class_exists(\Mews\Purifier\Facades\Purifier::class) && !empty($data['body'])) {
            $data['body'] = clean($data['body']);
        }

        $post = BlogPost::create($data);

        if (!empty($data['is_featured']) && $data['is_featured'] === true) {
            BlogPost::where('id', '!=', $post->id)->update(['is_featured' => false]);
        }

        AdminLog::record('blog.create', $post, $admin, null, [
            'status' => 'created',
            'context' => [
                'post_id' => $post->id,
                'title' => $post->title,
                'slug' => $post->slug,
                'admin_id' => $admin->id
            ]
        ]);

        $this->clearBlogCache();
        $post->load(['category', 'cover']);

        return $post;
    }

    /**
     * Обновить пост.
     *
     * @param  BlogPost $post      Связана route-model binding'ом (404 — фреймворк).
     * @param  User     $admin     Кто изменил.
     * @param  array    $validated Данные BlogPostRequest.
     * @return BlogPost Модель с загруженными ['category', 'cover'].
     */
    public function update(BlogPost $post, User $admin, array $validated): BlogPost
    {
        $data = $validated;

        if (class_exists(\Mews\Purifier\Facades\Purifier::class) && !empty($data['body'])) {
            $data['body'] = clean($data['body']);
        }

        $before = [
            'title' => $post->getOriginal('title'),
            'slug' => $post->getOriginal('slug'),
            'status' => $post->getOriginal('status')
        ];

        $post->update($data);

        if (!empty($data['is_featured']) && $data['is_featured'] === true) {
            BlogPost::where('id', '!=', $post->id)->update(['is_featured' => false]);
        }

        $after = [
            'title' => $post->title,
            'slug' => $post->slug,
            'status' => $post->status,
            'context' => [
                'post_id' => $post->id,
                'title' => $post->title,
                'admin_id' => $admin->id
            ]
        ];

        AdminLog::record('blog.update', $post, $admin, $before, $after);
        $this->clearBlogCache();
        $post->load(['category', 'cover']);

        return $post;
    }

    /**
     * Дублировать пост копией-черновиком.
     *
     * @param  BlogPost $post  Источник.
     * @param  User     $admin Кто дублировал.
     * @return BlogPost Новая модель с загруженными ['category', 'cover'].
     */
    public function duplicate(BlogPost $post, User $admin): BlogPost
    {
        $new = $post->replicate();
        $new->slug = $post->slug . '-copy-' . Str::lower(Str::random(6));
        $new->title = $post->title . ' (Копия)';
        $new->status = 'draft';
        $new->is_featured = false;
        $new->views_count = 0;
        $new->save();

        AdminLog::record('blog.create', $new, $admin, null, [
            'status' => 'created',
            'context' => [
                'source_post_id' => $post->id,
                'new_post_id' => $new->id,
                'title' => $new->title,
                'slug' => $new->slug,
                'admin_id' => $admin->id
            ]
        ]);

        $this->clearBlogCache();
        $new->load(['category', 'cover']);

        return $new;
    }

    // ============================================
    // СТАТУСЫ / ЗАКРЕП
    // ============================================

    /**
     * Закрепить/открепить пост (аналог одиночного featured-инварианта).
     *
     * B3: обёрнуто в DB::transaction + lockForUpdate. Раньше между
     * `where('is_featured', true)->update(false)` и `$post->update(true)`
     * мог вклиниться другой запрос → два featured поста.
     *
     * @param  BlogPost $post  Связана route-model binding'ом.
     * @param  User     $admin Кто переключил.
     * @return BlogPost $post->refresh() — актуальный is_featured.
     */
    public function setFeatured(BlogPost $post, User $admin): BlogPost
    {
        DB::transaction(function () use ($post, $admin) {
            // Берём лок на закрепляемый пост.
            $locked = BlogPost::lockForUpdate()->find($post->id);
            if (!$locked) return;

            $wasFeatured = $locked->getOriginal('is_featured');

            if ($wasFeatured) {
                $locked->update(['is_featured' => false]);
                AdminLog::record('blog.update', $locked, $admin, ['is_featured' => true], [
                    'is_featured' => false,
                    'context' => ['post_id' => $locked->id, 'admin_id' => $admin->id]
                ]);
            } else {
                // Снимаем закреп со всех остальных (в той же транзакции).
                BlogPost::where('is_featured', true)->where('id', '!=', $locked->id)->update(['is_featured' => false]);
                $locked->update(['is_featured' => true]);
                AdminLog::record('blog.update', $locked, $admin, ['is_featured' => false], [
                    'is_featured' => true,
                    'context' => ['post_id' => $locked->id, 'admin_id' => $admin->id]
                ]);
            }
        });

        $this->clearBlogCache();

        return $post->refresh();
    }

    /**
     * Переключить статус поста (published <-> draft, archived -> draft).
     *
     * @param  BlogPost $post  Связана route-model binding'ом.
     * @param  User     $admin Кто переключил.
     * @return BlogPost $post->refresh() — актуальный status.
     */
    public function toggle(BlogPost $post, User $admin): BlogPost
    {
        $oldStatus = $post->getOriginal('status');

        $newStatus = match ($oldStatus) {
            'published' => 'draft',
            'archived'  => 'draft',
            default     => 'published',
        };

        $post->update(['status' => $newStatus]);

        AdminLog::record('blog.update', $post, $admin, ['status' => $oldStatus], [
            'status' => $newStatus,
            'context' => [
                'post_id' => $post->id,
                'title' => $post->title,
                'admin_id' => $admin->id
            ]
        ]);

        $this->clearBlogCache();

        return $post->refresh();
    }

    /**
     * Переместить пост в архив (guard «уже в архиве» — в контроллере).
     *
     * @param  BlogPost $post  Связана route-model binding'ом.
     * @param  User     $admin Кто архивировал.
     * @return void
     */
    public function archive(BlogPost $post, User $admin): void
    {
        $oldStatus = $post->getOriginal('status');
        $post->update(['status' => 'archived']);

        AdminLog::record('blog.update', $post, $admin, ['status' => $oldStatus], [
            'status' => 'archived',
            'context' => [
                'post_id' => $post->id,
                'title' => $post->title,
                'admin_id' => $admin->id
            ]
        ]);

        $this->clearBlogCache();
    }

    /**
     * Восстановить пост из архива в черновики (guard «не в архиве» — в контроллере).
     *
     * @param  BlogPost $post  Связана route-model binding'ом.
     * @param  User     $admin Кто восстановил.
     * @return void
     */
    public function restore(BlogPost $post, User $admin): void
    {
        $post->update(['status' => 'draft']);

        AdminLog::record('blog.update', $post, $admin, ['status' => 'archived'], [
            'status' => 'draft',
            'context' => [
                'post_id' => $post->id,
                'title' => $post->title,
                'admin_id' => $admin->id
            ]
        ]);

        $this->clearBlogCache();
    }

    /**
     * Удалить пост навсегда (forceDelete).
     *
     * @param  BlogPost $post  Связана route-model binding'ом.
     * @param  User     $admin Кто удалил.
     * @return void
     */
    public function destroy(BlogPost $post, User $admin): void
    {
        $postId = $post->id;
        $postTitle = $post->title;

        AdminLog::record('blog.delete', $post, $admin, null, [
            'status' => 'destroyed',
            'context' => [
                'post_id' => $postId,
                'title' => $postTitle,
                'admin_id' => $admin->id
            ]
        ]);

        $post->forceDelete();
        $this->clearBlogCache();
    }

    /**
     * Массовые операции над постами (delete/publish/draft/archive).
     *
     * Guard «удалять можно только из архива» (422) — в контроллере.
     *
     * @param  User   $admin   Кто применил.
     * @param  array  $postIds IDs из BulkBlogPostRequest.
     * @param  string $action  delete|publish|draft|archive.
     * @return void
     */
    public function bulk(User $admin, array $postIds, string $action): void
    {
        if ($action === 'delete') {
            $deletedCount = BlogPost::whereIn('id', $postIds)->forceDelete();
            if ($deletedCount > 0) {
                AdminLog::record('blog.bulk_delete', null, $admin, null, [
                    'count' => $deletedCount,
                    'sample_ids' => array_slice($postIds, 0, 100)
                ]);
            }
        } else {
            $newStatus = match ($action) {
                'publish'  => 'published',
                'draft'    => 'draft',
                'archive'  => 'archived',
                default    => null,
            };

            if ($newStatus) {
                $affected = BlogPost::whereIn('id', $postIds)
                    ->where('status', '!=', $newStatus)
                    ->update(['status' => $newStatus]);

                if ($affected > 0) {
                    AdminLog::record('blog.bulk_update', null, $admin, null, [
                        'action' => $action,
                        'count' => $affected,
                        'sample_ids' => array_slice($postIds, 0, 100)
                    ]);
                }
            }
        }

        $this->clearBlogCache();
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function clearBlogCache(): void
    {
        Cache::forget('admin_blog_counts');
    }
}
