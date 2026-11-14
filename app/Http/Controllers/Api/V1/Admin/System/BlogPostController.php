<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Actions\V1\Admin\System\ManageBlogPostAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\System\BlogPostRequest;
use App\Http\Requests\Api\V1\Admin\System\BulkBlogPostRequest;
use App\Http\Resources\Api\V1\Admin\System\BlogPostResource;
use App\Models\BlogPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Блог-посты: чтение + thin-controller запись.
 *
 * Вся запись (CRUD, duplicate, toggle статуса, archive/restore, setFeatured,
 * bulk) делегируется ManageBlogPostAction. Featured-инвариант (только один
 * featured пост за раз) — через DB::transaction + lockForUpdate внутри Action.
 *
 * list vs detail (через BlogPostResource::withDetails()):
 *  - list: без body (тяжёлый HTML), без author.
 *  - detail: с body, cover_sm_url, author.
 *
 * Сортировка: featured первыми, потом по updated_at DESC ("recently updated first").
 *
 * Cache: admin_blog_counts (60 сек).
 */
class BlogPostController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $search   = (string) $request->string('q', '');
        $status   = (string) $request->string('status', 'all');
        $perPage  = min(max($request->integer('per_page', 15), 1), 100);
        $isId     = $search !== '' && ctype_digit($search);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        $posts = BlogPost::query()
            ->with(['category', 'cover'])
            ->when($search !== '', function ($q) use ($search, $searchOperator, $isId) {
                $q->where(function ($q) use ($search, $searchOperator, $isId) {
                    $q->where('title', $searchOperator, "%{$search}%")
                        ->orWhere('slug', $searchOperator, "%{$search}%");
                    if ($isId) {
                        $q->orWhere('id', (int) $search);
                    }
                });
            })
            ->when($status === 'uncategorized', fn($q) => $q->whereNull('category_id'))
            ->when(!in_array($status, ['all', 'uncategorized']), fn($q) => $q->where('status', $status))
            ->when($isId, fn($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]))
            ->orderBy('is_featured', 'desc')
            // Сортировка по updated_at DESC — "recently updated first".
            ->latest('updated_at')
            ->latest('id')
            ->paginate($perPage);

        return $this->paginated($posts, BlogPostResource::class, [
            'counts' => $this->getCounts(),
        ]);
    }

    /**
     * Закрепить/открепить пост (toggle is_featured).
     *
     * B3: транзакция + lockForUpdate против двух featured постов —
     * см. ManageBlogPostAction::setFeatured().
     */
    public function setFeatured(BlogPost $post, Request $request, ManageBlogPostAction $action): JsonResponse
    {
        $post = $action->setFeatured($post, $request->user());

        return $this->message(
            $post->is_featured ? 'Пост закреплен' : 'Пост откреплен',
            200,
            ['is_featured' => $post->is_featured],
        );
    }

    public function counts(): JsonResponse
    {
        return response()->json(['data' => $this->getCounts()]);
    }

    public function show(BlogPost $post): JsonResponse
    {
        $post->load(['category', 'cover', 'author']);
        return response()->json([
            'data' => (new BlogPostResource($post))->withDetails()->resolve(request()),
        ]);
    }

    public function store(BlogPostRequest $request, ManageBlogPostAction $action): JsonResponse
    {
        $post = $action->store($request->user(), $request->validated());
        $post->load(['category', 'cover', 'author']);

        return $this->message('Пост создан', 201, [
            'data' => (new BlogPostResource($post))->withDetails()->resolve(request()),
        ]);
    }

    public function update(BlogPostRequest $request, BlogPost $post, ManageBlogPostAction $action): JsonResponse
    {
        $post = $action->update($post, $request->user(), $request->validated());
        $post->load(['category', 'cover', 'author']);

        return $this->message('Пост обновлен', 200, [
            'data' => (new BlogPostResource($post))->withDetails()->resolve(request()),
        ]);
    }

    public function duplicate(BlogPost $post, Request $request, ManageBlogPostAction $action): JsonResponse
    {
        $new = $action->duplicate($post, $request->user());
        $new->load(['category', 'cover', 'author']);

        return $this->message('Пост продублирован', 200, [
            'data' => (new BlogPostResource($new))->withDetails()->resolve(request()),
        ]);
    }

    public function toggle(BlogPost $post, Request $request, ManageBlogPostAction $action): JsonResponse
    {
        $post = $action->toggle($post, $request->user());

        return $this->message('Статус обновлен', 200, ['status' => $post->status]);
    }

    public function archive(BlogPost $post, Request $request, ManageBlogPostAction $action): JsonResponse
    {
        if ($post->status === 'archived') {
            return $this->message('Пост уже в архиве');
        }

        $action->archive($post, $request->user());

        return $this->message('Пост перемещен в архив');
    }

    public function restore(BlogPost $post, Request $request, ManageBlogPostAction $action): JsonResponse
    {
        if ($post->status !== 'archived') {
            return $this->message('Пост не в архиве');
        }

        $action->restore($post, $request->user());

        return $this->message('Пост восстановлен');
    }

    public function destroy(BlogPost $post, Request $request, ManageBlogPostAction $action): JsonResponse
    {
        $action->destroy($post, $request->user());

        return $this->message('Пост удален');
    }

    public function bulk(BulkBlogPostRequest $request, ManageBlogPostAction $manage): JsonResponse
    {
        $validated = $request->validated();

        $admin = $request->user();
        $postIds = $validated['ids'];
        $action = $validated['action'];
        $isArchiveTab = $request->boolean('is_archive_tab');

        // Удалять можно только из архива (защита от случайного удаления опубликованных).
        if ($action === 'delete' && !$isArchiveTab) {
            return $this->message('Удалять можно только из архива!', 422);
        }

        $manage->bulk($admin, $postIds, $action);

        $messages = [
            'delete'  => 'Посты удалены',
            'publish' => 'Выбранные посты опубликованы',
            'draft'   => 'Выбранные посты сняты с публикации',
            'archive' => 'Посты перемещены в архив',
            'default' => 'Действие применено',
        ];

        return $this->message($messages[$action] ?? 'Действие применено', 200, [
            'counts' => $this->getCounts(),
        ]);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getCounts(): array
    {
        return Cache::remember('admin_blog_counts', 60, function () {
            $stats = BlogPost::query()
                ->selectRaw("COUNT(*) as total")
                ->selectRaw("SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published")
                ->selectRaw("SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft")
                ->selectRaw("SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived")
                ->selectRaw("SUM(CASE WHEN category_id IS NULL THEN 1 ELSE 0 END) as uncategorized")
                ->first();

            return [
                'all'           => (int)($stats?->total ?? 0),
                'published'     => (int)($stats?->published ?? 0),
                'draft'         => (int)($stats?->draft ?? 0),
                'archived'      => (int)($stats?->archived ?? 0),
                'uncategorized' => (int)($stats?->uncategorized ?? 0),
            ];
        });
    }
} 