<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\System\BulkInterestRequest;
use App\Http\Requests\Api\V1\Admin\System\InterestRequest;
use App\Http\Resources\Api\V1\Admin\System\InterestResource;
use App\Models\AdminLog;
use App\Models\Interest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Cocur\Slugify\Slugify;

/**
 * Интересы: CRUD + boost + bulk.
 *
 * Зеркало BlogPostController (упрощённое — без body/cover/featured/status/author).
 *
 * Список:
 *   - поиск по name/slug/id (ilike на pgsql, like на mysql — как в BlogPostController).
 *   - фильтр по category_id (включая «Без категории»).
 *   - пагинация через paginated() + InterestResource.
 *   - counts (cache 60s): all, uncategorized, boosted — для табов-фильтров.
 *
 * Boost: POST /interests/{interest}/boost { search_boost } — поднять/снять буст выдачи.
 *
 * Bulk: delete (удалить навсегда) или set_category (перенести в категорию).
 *
 * Счётчики users_count (у интереса) и interests_count (у категории) обновляются
 * триггерами update_interest_users_count / update_interest_category_count.
 */
class InterestController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $search    = (string) $request->string('q', '');
        $categoryId = $request->input('category_id', 'all');
        $perPage   = min(max($request->integer('per_page', 15), 1), 100);
        $isId      = $search !== '' && ctype_digit($search);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        $interests = Interest::query()
            ->with(['category'])
            ->when($search !== '', function ($q) use ($search, $searchOperator, $isId) {
                $q->where(function ($q) use ($search, $searchOperator, $isId) {
                    $q->where('name', $searchOperator, "%{$search}%")
                        ->orWhere('slug', $searchOperator, "%{$search}%");
                    if ($isId) {
                        $q->orWhere('id', (int) $search);
                    }
                });
            })
            ->when($categoryId === 'uncategorized', fn($q) => $q->whereNull('category_id'))
            ->when($categoryId === 'boosted', fn($q) => $q->where('search_boost', '>', 0))
            ->when(!in_array($categoryId, ['all', 'uncategorized', 'boosted'], true), fn($q) => $q->where('category_id', (int) $categoryId))
            ->when($isId, fn($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]))
            // Сначала бустнутые (топ выдачи), потом по популярности.
            ->orderByDesc('search_boost')
            ->orderByDesc('users_count')
            ->latest('id')
            ->paginate($perPage);

        return $this->paginated($interests, InterestResource::class, [
            'counts' => $this->getCounts(),
        ]);
    }

    public function counts(): JsonResponse
    {
        return response()->json(['data' => $this->getCounts()]);
    }

    public function show(Interest $interest): JsonResponse
    {
        $interest->load(['category']);
        return response()->json([
            'data' => (new InterestResource($interest))->resolve(request()),
        ]);
    }

    public function store(InterestRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $slug = $this->makeSlug($validated['name']);

        $interest = Interest::create([
            'name'         => $validated['name'],
            'slug'         => $slug,
            'category_id'  => $validated['category_id'] ?? null,
            'search_boost' => $validated['search_boost'] ?? 0,
        ]);

        $interest->load(['category']);

        AdminLog::record('interest.create', $interest, $request->user(), null, [
            'status'  => 'created',
            'context' => [
                'interest_id' => $interest->id,
                'name'        => $interest->name,
                'admin_id'    => $request->user()->id,
            ],
        ]);

        $this->clearCountsCache();

        return $this->message('Интерес создан', 201, [
            'data' => new InterestResource($interest),
        ]);
    }

    public function update(InterestRequest $request, Interest $interest): JsonResponse
    {
        $validated = $request->validated();

        $before = $interest->only(['name', 'category_id', 'search_boost']);

        // Если меняется name — пересчитываем slug (как в блоге).
        if (isset($validated['name']) && $validated['name'] !== $interest->name) {
            $validated['slug'] = $this->makeSlug($validated['name']);
        }

        $interest->update($validated);
        $interest->load(['category']);

        AdminLog::record(
            'interest.update',
            $interest,
            $request->user(),
            $before,
            $interest->only(['name', 'category_id', 'search_boost']),
        );

        $this->clearCountsCache();

        return $this->message('Интерес обновлен', 200, [
            'data' => new InterestResource($interest),
        ]);
    }

    /**
     * Повысить/снять буст выдачи интереса.
     * POST /interests/{interest}/boost { search_boost: int }
     *
     * Валидация поля — через InterestRequest не подходит (он на full-update),
     * поэтому проверяем здесь явно.
     */
    public function boost(Interest $interest, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search_boost' => ['required', 'integer', 'min:0', 'max:65535'],
        ]);

        $before = ['search_boost' => $interest->search_boost];

        $interest->boost($validated['search_boost']);

        AdminLog::record('interest.boost', $interest, $request->user(), $before, ['search_boost' => $interest->search_boost]);

        $this->clearCountsCache();

        return $this->message(
            $interest->search_boost > 0 ? 'Интерес поднят в выдаче' : 'Буст снят',
            200,
            ['search_boost' => $interest->search_boost],
        );
    }

    public function destroy(Interest $interest, Request $request): JsonResponse
    {
        $interestId = $interest->id;
        $interestName = $interest->name;

        AdminLog::record('interest.delete', $interest, $request->user(), null, [
            'status'  => 'destroyed',
            'context' => [
                'interest_id' => $interestId,
                'name'        => $interestName,
                'admin_id'    => $request->user()->id,
            ],
        ]);

        // user_interests уйдут каскадом по FK onDelete cascade.
        $interest->delete();

        $this->clearCountsCache();

        return $this->message('Интерес удален');
    }

    public function bulk(BulkInterestRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $admin = $request->user();
        $ids = $validated['ids'];
        $action = $validated['action'];

        $result = DB::transaction(function () use ($ids, $action, $validated, $admin) {
            $query = Interest::whereIn('id', $ids);

            if ($action === 'delete') {
                $names = $query->pluck('name', 'id')->all();
                $count = $query->delete();

                AdminLog::record('interest.bulk_delete', null, $admin, null, [
                    'status'  => 'bulk_destroyed',
                    'context' => [
                        'ids'    => $ids,
                        'names'  => $names,
                        'count'  => $count,
                        'admin_id' => $admin->id,
                    ],
                ]);

                return $count;
            }

            // action === 'set_category'
            $categoryId = $validated['category_id'] ?? null;
            $count = $query->update(['category_id' => $categoryId]);

            AdminLog::record('interest.bulk_set_category', null, $admin, null, [
                'status'  => 'bulk_set_category',
                'context' => [
                    'ids'         => $ids,
                    'category_id' => $categoryId,
                    'count'       => $count,
                    'admin_id'    => $admin->id,
                ],
            ]);

            return $count;
        });

        $messages = [
            'delete'       => "Удалено интересов: {$result}",
            'set_category' => "Перенесено интересов: {$result}",
        ];

        $this->clearCountsCache();

        return $this->message($messages[$action], 200, [
            'count'  => $result,
            'counts' => $this->getCounts(),
        ]);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getCounts(): array
    {
        return Cache::remember('admin_interest_counts', 60, function () {
            $stats = Interest::query()
                ->selectRaw("COUNT(*) as total")
                ->selectRaw("SUM(CASE WHEN category_id IS NULL THEN 1 ELSE 0 END) as uncategorized")
                ->selectRaw("SUM(CASE WHEN search_boost > 0 THEN 1 ELSE 0 END) as boosted")
                ->first();

            return [
                'all'           => (int)($stats?->total ?? 0),
                'uncategorized' => (int)($stats?->uncategorized ?? 0),
                'boosted'       => (int)($stats?->boosted ?? 0),
            ];
        });
    }

    /**
     * Сброс кэша счётчиков — вызывать во ВСЕХ мутациях, меняющих интересы.
     * Паттерн как в блоге (Cache::forget('admin_blog_counts') в ManageBlogPostAction).
     * Без этого счётчики на табах-фильтрах не обновляются до 60 сек (staleTime кэша).
     */
    private function clearCountsCache(): void
    {
        Cache::forget('admin_interest_counts');
    }

    /**
     * Slug из name через cocur/slugify (russian ruleset).
     * Fallback 'interest-{time()}' для непереводимых имён.
     */
    private function makeSlug(string $name): string
    {
        $slugify = new Slugify(['rulesets' => ['default', 'russian']]);
        $slug = $slugify->slugify($name);

        return $slug !== '' ? $slug : 'interest-' . time();
    }
}
