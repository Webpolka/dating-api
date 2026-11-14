<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\System\StoreBlogCategoryRequest;
use App\Http\Requests\Api\V1\Admin\System\UpdateBlogCategoryRequest;
use App\Http\Resources\Api\V1\Admin\System\BlogCategoryResource;
use App\Models\AdminLog;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * CRUD категорий блога.
 *
 * Slug генерируется из name через Str::slug. Если пустой (например, name только
 * из кириллицы) — fallback 'category-{time()}'.
 *
 * При удалении категории — её посты получают category_id=null (uncategorized).
 *
 * posts_count в index — from withCount. В store()/update() withCount не вызывается
 * → posts_count будет 0 в ответе (это норм, фронт после создания сразу перечитает список).
 */
class BlogCategoryController extends AdminController
{
    public function index(): JsonResponse
    {
        $categories = BlogCategory::active()
            ->ordered()
            ->withCount('posts')
            ->get();

        return response()->json([
            'data' => BlogCategoryResource::collection($categories)->resolve(request()),
        ]);
    }

    public function store(StoreBlogCategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $slug = Str::slug($validated['name']);
        if (empty($slug)) {
            $slug = 'category-' . time();
        }

        $category = BlogCategory::create([
            'name'       => $validated['name'],
            'slug'       => $slug,
            'sort_order' => (BlogCategory::max('sort_order') ?? 0) + 1,
        ]);

        AdminLog::record('blog_category.create', $category, $request->user(), null, [
            'status'  => 'created',
            'context' => [
                'category_id' => $category->id,
                'name'        => $category->name,
                'admin_id'    => $request->user()->id,
            ],
        ]);

        return $this->message('Рубрика создана', 201, [
            'data' => new BlogCategoryResource($category),
        ]);
    }

    public function update(UpdateBlogCategoryRequest $request, BlogCategory $category): JsonResponse
    {
        $validated = $request->validated();

        $before = $category->only(['name', 'is_active', 'sort_order']);

        // Если меняется name — пересчитываем slug.
        if (isset($validated['name']) && $validated['name'] !== $category->name) {
            $slug = Str::slug($validated['name']);
            if (empty($slug)) {
                $slug = 'category-' . time();
            }
            $validated['slug'] = $slug;
        }

        $category->update($validated);

        AdminLog::record('blog_category.update', $category, $request->user(), $before, $category->only(['name', 'is_active', 'sort_order']));

        return $this->message('Рубрика обновлена', 200, [
            'data' => new BlogCategoryResource($category),
        ]);
    }

    public function destroy(BlogCategory $category, Request $request): JsonResponse
    {
        $categoryId = $category->id;
        $categoryName = $category->name;

        // Перенос постов в uncategorized (category_id=null) перед удалением категории.
        BlogPost::where('category_id', $categoryId)->update(['category_id' => null]);

        AdminLog::record('blog_category.delete', $category, $request->user(), null, [
            'status'  => 'destroyed',
            'context' => [
                'category_id' => $categoryId,
                'name'        => $categoryName,
                'admin_id'    => $request->user()->id,
            ],
        ]);

        $category->delete();

        return $this->message('Рубрика удалена');
    }
} 