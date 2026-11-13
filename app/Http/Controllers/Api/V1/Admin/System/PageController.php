<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Models\AdminLog;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * CMS — статические страницы сайта.
 *
 * body чистится через Purifier (XSS protection).
 */
class PageController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $search = (string) $request->input('search', '');
        $status = (string) $request->input('status', 'all');
        $page = max((int) $request->input('page', 1), 1);
        $perPage = min(max((int) $request->input('per_page', 15), 10), 100);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isId = !empty($search) && ctype_digit($search);

        $query = Page::query()
            ->when($search, function ($q) use ($searchOperator, $isId, $search) {
                $q->where(function ($q2) use ($searchOperator, $isId, $search) {
                    $q2->where('title', $searchOperator, "%{$search}%")
                        ->orWhere('slug', $searchOperator, "%{$search}%");
                    if ($isId) {
                        $q2->orWhere('id', (int) $search);
                    }
                });

                if ($isId) {
                    $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
                }
            })
            ->when($status === 'active', fn($q) => $q->where('is_active', true))
            ->when($status === 'draft', fn($q) => $q->where('is_active', false))
            ->latest('updated_at')
            ->latest('id');

        $pages = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'pages' => $pages->map(fn(Page $p) => [
                'id' => $p->id,
                'title' => $p->title,
                'slug' => $p->slug,
                'meta_description' => $p->meta_description,
                'is_active' => $p->is_active,
                'created_at' => $p->created_at?->toIso8601String(),
                'updated_at' => $p->updated_at?->toIso8601String(),
            ]),
            'total' => $pages->total(),
            'current_page' => $pages->currentPage(),
            'last_page' => $pages->lastPage(),
            'per_page' => $pages->perPage(),
            'counts' => $this->getCounts(),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        return response()->json([
            'id' => $page->id,
            'title' => $page->title,
            'meta_title' => $page->meta_title,
            'slug' => $page->slug,
            'body' => $page->body,
            'meta_description' => $page->meta_description,
            'is_active' => $page->is_active,
            'created_at' => $page->created_at?->toIso8601String(),
            'updated_at' => $page->updated_at?->toIso8601String(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'meta_title' => 'nullable|string|max:255',
            'slug' => 'required|regex:/^[a-z0-9-]+$/|unique:pages,slug',
            'body' => 'nullable|string',
            'meta_description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $admin = $request->user();
        $data = $validator->validated();

        // Purifier для body — XSS protection.
        if (!empty($data['body'])) {
            $data['body'] = clean($data['body']);
        }

        $page = Page::create($data);

        AdminLog::record('page.create', $page, $admin, null, [
            'status' => 'created',
            'context' => [
                'page_id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'admin_id' => $admin->id
            ]
        ]);

        $this->clearCountsCache();

        return response()->json([
            'message' => 'Страница создана!',
            'page' => ['id' => $page->id],
            // Возвращаем очищенный body — чтобы фронт обновил редактор
            // и юзер сразу увидел, что Purifier вырезал запрещённые теги.
            'body' => $page->body,
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $page = Page::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'meta_title' => 'nullable|string|max:255',
            'slug' => 'required|regex:/^[a-z0-9-]+$/|unique:pages,slug,' . $id,
            'body' => 'nullable|string',
            'meta_description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $admin = $request->user();
        $data = $validator->validated();

        // Purifier для body.
        if (!empty($data['body'])) {
            $data['body'] = clean($data['body']);
        }

        $before = [
            'title' => $page->getOriginal('title'),
            'slug' => $page->getOriginal('slug'),
            'is_active' => $page->getOriginal('is_active')
        ];

        $page->update($data);

        AdminLog::record('page.update', $page, $admin, $before, [
            'title' => $page->title,
            'slug' => $page->slug,
            'is_active' => $page->is_active,
            'context' => [
                'page_id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'admin_id' => $admin->id
            ]
        ]);

        $this->clearCountsCache();

        return response()->json([
            'message' => 'Страница обновлена!',
            // Возвращаем очищенный body — фронт обновит редактор реактивно.
            'body' => $page->body,
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        $admin = $request->user();

        $before = ['title' => $page->getOriginal('title'), 'slug' => $page->getOriginal('slug')];

        AdminLog::record('page.delete', $page, $admin, $before, [
            'status' => 'destroyed',
            'deleted_by' => $admin->id,
            'context' => [
                'page_id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'admin_id' => $admin->id
            ]
        ]);

        $page->delete();

        $this->clearCountsCache();

        return response()->json(['message' => 'Страница удалена']);
    }

    public function toggleStatus(Request $request, int $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        $admin = $request->user();

        $before = ['is_active' => $page->getOriginal('is_active')];

        $page->is_active = !$page->is_active;
        $page->save();

        AdminLog::record('page.update', $page, $admin, $before, [
            'is_active' => $page->is_active,
            'toggled_by' => $admin->id,
            'context' => [
                'page_id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'admin_id' => $admin->id
            ]
        ]);

        $this->clearCountsCache();

        return response()->json([
            'message' => $page->is_active ? 'Страница опубликована' : 'Страница снята с публикации',
            'is_active' => $page->is_active
        ]);
    }

    public function duplicate(Request $request, int $id): JsonResponse
    {
        $page = Page::findOrFail($id);
        $admin = $request->user();

        $newSlug = $page->slug . '-copy-' . time();
        $newPage = Page::create([
            'title' => $page->title . ' (Копия)',
            'meta_title' => $page->meta_title,
            'slug' => $newSlug,
            'body' => $page->body,
            'meta_description' => $page->meta_description,
            'is_active' => false,
        ]);

        AdminLog::record('page.create', $newPage, $admin, null, [
            'status' => 'created',
            'context' => [
                'source_page_id' => $page->id,
                'new_page_id' => $newPage->id,
                'title' => $newPage->title,
                'slug' => $newPage->slug,
                'admin_id' => $admin->id
            ]
        ]);

        $this->clearCountsCache();

        return response()->json(['message' => 'Страница продублирована']);
    }

    public function bulkAction(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|max:100',
            'ids.*' => 'integer|exists:pages,id',
            'action' => 'required|in:delete,activate,draft',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $admin = $request->user();
        $ids = $request->input('ids');
        $action = $request->input('action');

        if ($action === 'delete') {
            $affectedCount = Page::whereIn('id', $ids)->delete();

            if ($affectedCount > 0) {
                AdminLog::record('page.bulk_delete', null, $admin, null, [
                    'count' => $affectedCount,
                    'sample_ids' => array_slice($ids, 0, 100)
                ]);
            }
        } else {
            $isActive = ($action === 'activate');
            $affectedCount = Page::whereIn('id', $ids)
                ->where('is_active', '!=', $isActive)
                ->update(['is_active' => $isActive]);

            if ($affectedCount > 0) {
                AdminLog::record('page.bulk_update', null, $admin, null, [
                    'action' => $action,
                    'count' => $affectedCount,
                    'sample_ids' => array_slice($ids, 0, 100)
                ]);
            }
        }

        $this->clearCountsCache();

        $messages = [
            'delete' => "Удалено страниц: {$affectedCount}",
            'activate' => "Опубликовано страниц: {$affectedCount}",
            'draft' => "Снято с публикации: {$affectedCount}"
        ];

        return response()->json(['message' => $messages[$action] ?? 'Действие выполнено']);
    }

    private function getCounts(): array
    {
        return Cache::remember('admin_pages_counts', 60, function () {
            $stats = Page::query()
                ->selectRaw("COUNT(*) as total")
                ->selectRaw("SUM(CASE WHEN is_active = true THEN 1 ELSE 0 END) as active")
                ->first();

            $total = (int) ($stats?->total ?? 0);
            $active = (int) ($stats?->active ?? 0);

            return [
                'all' => $total,
                'active' => $active,
                'draft' => $total - $active,
            ];
        });
    }

    private function clearCountsCache(): void
    {
        Cache::forget('admin_pages_counts');
    }
}
