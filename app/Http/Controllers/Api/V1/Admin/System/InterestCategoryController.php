<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\System\StoreInterestCategoryRequest;
use App\Http\Requests\Api\V1\Admin\System\UpdateInterestCategoryRequest;
use App\Http\Resources\Api\V1\Admin\System\InterestCategoryResource;
use App\Models\AdminLog;
use App\Models\Interest;
use App\Models\InterestCategory;
use Cocur\Slugify\Slugify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * CRUD категорий интересов.
 *
 * Зеркало BlogCategoryController (System-раздел). Slug генерируется из name
 * через cocur/slugify (rulesets: default + russian) — чтобы «Хобби и увлечения»
 * превратилось в «hobbi-i-uvlecheniya», а не «» (что было бы со Str::slug
 * для чистой кириллицы). Fallback 'interest-category-{time()}' — страховка
 * на случай совсем «непереводимых» имён (эмодзи и т.п.).
 *
 * При удалении категории — её интересы получают category_id=null (uncategorized),
 * как блог-посты. Счётчик interests_count обновляется триггером
 * update_interest_category_count.
 *
 * interests_count в index — from withCount. В store()/update() withCount
 * не вызывается → interests_count будет 0 в ответе (норм — фронт после
 * создания сразу перечитает список).
 */
class InterestCategoryController extends AdminController
{
    public function index(): JsonResponse
    {
        $categories = InterestCategory::query()
            ->ordered()
            ->withCount('interests')
            ->get();

        return response()->json([
            'data' => InterestCategoryResource::collection($categories)->resolve(request()),
        ]);
    }

    public function store(StoreInterestCategoryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $slug = $this->makeSlug($validated['name']);

        $category = InterestCategory::create([
            'name'       => $validated['name'],
            'slug'       => $slug,
            'icon'       => $validated['icon'] ?? null,
            'sort_order' => $validated['sort_order'] ?? ((InterestCategory::max('sort_order') ?? 0) + 1),
        ]);

        AdminLog::record('interest_category.create', $category, $request->user(), null, [
            'status'  => 'created',
            'context' => [
                'category_id' => $category->id,
                'name'        => $category->name,
                'admin_id'    => $request->user()->id,
            ],
        ]);

        return $this->message('Категория создана', 201, [
            'data' => new InterestCategoryResource($category),
        ]);
    }

    public function update(UpdateInterestCategoryRequest $request, InterestCategory $category): JsonResponse
    {
        $validated = $request->validated();

        $before = $category->only(['name', 'icon', 'sort_order']);

        // Если меняется name — пересчитываем slug (как в блоге).
        if (isset($validated['name']) && $validated['name'] !== $category->name) {
            $validated['slug'] = $this->makeSlug($validated['name']);
        }

        $category->update($validated);

        AdminLog::record(
            'interest_category.update',
            $category,
            $request->user(),
            $before,
            $category->only(['name', 'icon', 'sort_order']),
        );

        return $this->message('Категория обновлена', 200, [
            'data' => new InterestCategoryResource($category),
        ]);
    }

    public function destroy(InterestCategory $category, Request $request): JsonResponse
    {
        $categoryId = $category->id;
        $categoryName = $category->name;

        // Перенос интересов в uncategorized (category_id=null) перед удалением категории.
        Interest::where('category_id', $categoryId)->update(['category_id' => null]);

        AdminLog::record('interest_category.delete', $category, $request->user(), null, [
            'status'  => 'destroyed',
            'context' => [
                'category_id' => $categoryId,
                'name'        => $categoryName,
                'admin_id'    => $request->user()->id,
            ],
        ]);

        $category->delete();

        // Удаление категории переносит её интересы в uncategorized —
        // это меняет счётчик uncategorized у интересов.
        Cache::forget('admin_interest_counts');

        return $this->message('Категория удалена');
    }

    /**
     * Slug из name через cocur/slugify с russian ruleset.
     * Fallback 'interest-category-{time()}' для непереводимых имён.
     */
    private function makeSlug(string $name): string
    {
        $slugify = new Slugify(['rulesets' => ['default', 'russian']]);
        $slug = $slugify->slugify($name);

        return $slug !== '' ? $slug : 'interest-category-' . time();
    }
}
