<?php

namespace App\Http\Controllers\Admin\V1\Content;

use App\Actions\Admin\Content\ManageBlogPostAction;
use App\Http\Controllers\AdminController;
use App\Http\Requests\Admin\V1\Content\BlogPostRequest;
use App\Http\Requests\Admin\V1\Content\BulkBlogPostRequest;
use App\Models\BlogPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Блог-посты: чтение + thin-controller запись.
 *
 * Вся запись (CRUD, duplicate, toggle статуса, archive/restore, setFeatured,
 * bulk) делегируется ManageBlogPostAction (S5-6); контроллер строит JSON и
 * форматирует чтение. Featured-инвариант (DB::transaction + lockForUpdate) —
 * внутри ManageBlogPostAction::setFeatured().
 */
class BlogPostController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        // B1: убрал избыточный ->toString()
        $search = (string) $request->string('q', '');
        $status = (string) $request->string('status', 'all');
        $perPage = min(max($request->integer('per_page', 15), 1), 100);
        $isId = $search !== '' && ctype_digit($search);

        // B2: оператор поиска через config
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
            ->when($isId, function ($q) use ($search) {
                $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
            })
            ->orderBy('is_featured', 'desc')
            // Сортировка по updated_at DESC — "recently updated first".
            ->latest('updated_at')
            ->latest('id')
            ->paginate($perPage);

        return response()->json([
            'data' => $posts->map(fn(BlogPost $p) => $this->formatPost($p)),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page'    => $posts->lastPage(),
                'per_page'     => $posts->perPage(),
                'total'        => $posts->total(),
                'from'         => $posts->firstItem(),
                'to'           => $posts->lastItem(),
            ],
            'counts' => $this->getCounts(),
        ]);
    }

    /**
     * Закрепить/открепить пост.
     *
     * B3: транзакция + lockForUpdate против двух featured постов —
     * см. ManageBlogPostAction::setFeatured().
     */
    public function setFeatured(BlogPost $post, Request $request, ManageBlogPostAction $action): JsonResponse
    {
        $post = $action->setFeatured($post, $request->user());

        return response()->json([
            'message' => $post->is_featured ? 'Пост закреплен' : 'Пост откреплен',
            'is_featured' => $post->is_featured,
        ]);
    }

    public function counts(): JsonResponse
    {
        return response()->json(['data' => $this->getCounts()]);
    }

    public function show(BlogPost $post): JsonResponse
    {
        $post->load(['category', 'cover']);
        return response()->json(['data' => $this->formatPost($post, true)]);
    }

    public function store(BlogPostRequest $request, ManageBlogPostAction $action): JsonResponse
    {
        $post = $action->store($request->user(), $request->validated());

        return response()->json([
            'message' => 'Пост создан',
            'data' => $this->formatPost($post, true),
        ], 201);
    }

    public function update(BlogPostRequest $request, BlogPost $post, ManageBlogPostAction $action): JsonResponse
    {
        $post = $action->update($post, $request->user(), $request->validated());

        return response()->json([
            'message' => 'Пост обновлен',
            'data' => $this->formatPost($post, true),
        ]);
    }

    public function duplicate(BlogPost $post, Request $request, ManageBlogPostAction $action): JsonResponse
    {
        $new = $action->duplicate($post, $request->user());

        return response()->json([
            'message' => 'Пост продублирован',
            'data' => $this->formatPost($new, true),
        ]);
    }

    public function toggle(BlogPost $post, Request $request, ManageBlogPostAction $action): JsonResponse
    {
        $post = $action->toggle($post, $request->user());

        return response()->json(['message' => 'Статус обновлен', 'status' => $post->status]);
    }

    public function archive(BlogPost $post, Request $request, ManageBlogPostAction $action): JsonResponse
    {
        if ($post->status === 'archived') {
            return response()->json(['message' => 'Пост уже в архиве']);
        }

        $action->archive($post, $request->user());

        return response()->json(['message' => 'Пост перемещен в архив']);
    }

    public function restore(BlogPost $post, Request $request, ManageBlogPostAction $action): JsonResponse
    {
        if ($post->status !== 'archived') {
            return response()->json(['message' => 'Пост не в архиве']);
        }

        $action->restore($post, $request->user());

        return response()->json(['message' => 'Пост восстановлен']);
    }

    public function destroy(BlogPost $post, Request $request, ManageBlogPostAction $action): JsonResponse
    {
        $action->destroy($post, $request->user());

        return response()->json(['message' => 'Пост удален']);
    }

    public function bulk(BulkBlogPostRequest $request, ManageBlogPostAction $manage): JsonResponse
    {
        $validated = $request->validated();

        $admin = $request->user();
        $postIds = $validated['ids'];
        $action = $validated['action'];
        $isArchiveTab = $request->boolean('is_archive_tab');

        if ($action === 'delete' && !$isArchiveTab) {
            return response()->json(['message' => 'Удалять можно только из архива!'], 422);
        }

        $manage->bulk($admin, $postIds, $action);

        $messages = [
            'delete'   => 'Посты удалены',
            'publish'  => 'Выбранные посты опубликованы',
            'draft'    => 'Выбранные посты сняты с публикации',
            'archive'  => 'Посты перемещены в архив',
            'default'  => 'Действие применено',
        ];

        return response()->json(['message' => $messages[$action] ?? 'Действие применено', 'counts' => $this->getCounts()]);
    }

    private function formatPost(BlogPost $post, bool $detailed = false): array
    {
        $data = [
            'id'          => $post->id,
            'title'       => $post->title,
            'slug'        => $post->slug,
            'excerpt'     => $post->excerpt,
            'status'      => $post->status,
            'status_label' => $post->status_badge['label'],
            'status_variant' => $post->status_badge['variant'],
            'is_featured' => $post->is_featured,
            'views_count' => $post->views_count,
            'category_id' => $post->category_id,
            'cover_media_id' => $post->cover_media_id,
            'category'    => $post->category ? [
                'id' => $post->category->id,
                'name' => $post->category->name,
                'slug' => $post->category->slug,
            ] : null,
            'cover_url'  => $post->cover_url,
            'cover_thumb' => $post->cover?->getVariantUrl('thumb'),
            'created_at'  => $post->created_at?->toIso8601String(),
            'updated_at'  => $post->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['body'] = $post->body;
            $data['cover_sm_url'] = $post->cover?->getVariantUrl('sm');
            $data['author'] = $post->author ? [
                'id' => $post->author->id,
                'name' => $post->author->name,
            ] : null;
        }

        return $data;
    }

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
