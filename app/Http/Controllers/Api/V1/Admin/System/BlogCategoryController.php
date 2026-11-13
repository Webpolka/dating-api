<?php

namespace App\Http\Controllers\Admin\V1\Content;

use App\Http\Controllers\AdminController;
use App\Http\Requests\Admin\V1\Content\StoreBlogCategoryRequest;
use App\Http\Requests\Admin\V1\Content\UpdateBlogCategoryRequest;
use App\Models\AdminLog;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BlogCategoryController extends AdminController
{
    public function index(): JsonResponse
    {
        $categories = BlogCategory::active()
            ->ordered()
            ->get()
            ->map(fn(BlogCategory $c) => [
                'id'         => $c->id,
                'name'       => $c->name,
                'slug'       => $c->slug,
                'is_active'  => $c->is_active,
                'sort_order' => $c->sort_order,
                'posts_count' => BlogPost::where('category_id', $c->id)->count(),
            ]);

        return response()->json(['data' => $categories]);
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

        return response()->json([
            'message' => 'Рубрика создана',
            'data' => [
                'id'   => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ],
        ], 201);
    }

    public function update(UpdateBlogCategoryRequest $request, BlogCategory $category): JsonResponse
    {
        $validated = $request->validated();

        $before = $category->only(['name', 'is_active', 'sort_order']);

        if (isset($validated['name']) && $validated['name'] !== $category->name) {
            $slug = Str::slug($validated['name']);
            if (empty($slug)) {
                $slug = 'category-' . time();
            }
            $validated['slug'] = $slug;
        }

        $category->update($validated);

        AdminLog::record('blog_category.update', $category, $request->user(), $before, $category->only(['name', 'is_active', 'sort_order']));

        return response()->json([
            'message' => 'Рубрика обновлена',
            'data' => [
                'id'         => $category->id,
                'name'       => $category->name,
                'slug'       => $category->slug,
                'is_active'  => $category->is_active,
                'sort_order' => $category->sort_order,
            ],
        ]);
    }

    public function destroy(BlogCategory $category, Request $request): JsonResponse
    {
        $categoryId = $category->id;
        $categoryName = $category->name;

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

        return response()->json(['message' => 'Рубрика удалена']);
    }
}
