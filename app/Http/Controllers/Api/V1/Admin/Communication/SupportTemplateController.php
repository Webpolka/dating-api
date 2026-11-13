<?php

namespace App\Http\Controllers\Api\V1\Admin\Communication;

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Communication\StoreSupportTemplateCategoryRequest;
use App\Http\Requests\Api\V1\Admin\Communication\StoreSupportTemplateRequest;
use App\Http\Requests\Api\V1\Admin\Communication\UpdateSupportTemplateCategoryRequest;
use App\Http\Requests\Api\V1\Admin\Communication\UpdateSupportTemplateRequest;
use App\Models\AdminLog;
use App\Models\SupportTemplate;
use App\Models\SupportTemplateCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Шаблоны поддержки и их категории.
 *
 * Архитектура:
 *  - Две сущности: SupportTemplate (шаблоны) и SupportTemplateCategory (категории)
 *  - Категория "Общие" (slug=general) — системная, её нельзя удалить и нельзя менять slug
 *  - При удалении категории её шаблоны переносятся в "Общие"
 *  - Slug: автогенерация из названия, только латиница/цифры/дефис
 *  - Все действия логируются в AdminLog с объектом
 *  - Кэши: admin_support_template_counts (60сек) + admin_support_templates (forever, для чата поддержки)
 */
class SupportTemplateController extends AdminController
{
    // ============================================
    // ШАБЛОНЫ — список / детали / CRUD
    // ============================================

    public function index(Request $request): JsonResponse
    {
        $search         = (string) $request->string('q', '');
        $categoryFilter = (string) $request->string('category', 'all');
        $perPage        = min(max((int) $request->integer('per_page', 15), 10), 100);
        $page           = max((int) $request->integer('page', 1), 1);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isId           = $search !== '' && ctype_digit($search);

        $query = SupportTemplate::query()
            ->when($categoryFilter !== 'all', fn($q) => $q->where('category_id', (int) $categoryFilter))
            ->when($search !== '', function ($q) use ($search, $searchOperator, $isId) {
                if ($isId) {
                    $q->where('id', (int) $search);
                } else {
                    $q->where('title', $searchOperator, "%{$search}%")
                        ->orWhere('body', $searchOperator, "%{$search}%");
                }
            })
            ->with('category')
            // Сортировка: при поиске по ID — искомый первым, иначе — по updated_at DESC
            // (свежеотредактированные шаблоны всплывают наверх, как в Notion/Linear).
            // NULL updated_at (если когда-то будет) — в самый низ.
            ->when($isId, function ($q) use ($search) {
                $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
            })
            ->orderByRaw('updated_at IS NULL, updated_at DESC')
            ->orderBy('id', 'desc');

        $templates = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'  => $templates->map(fn(SupportTemplate $t) => $this->formatTemplate($t)),
            'meta'  => [
                'current_page' => $templates->currentPage(),
                'last_page'    => $templates->lastPage(),
                'per_page'     => $templates->perPage(),
                'total'        => $templates->total(),
                'from'         => $templates->firstItem(),
                'to'           => $templates->lastItem(),
            ],
            'counts' => $this->getCounts(),
        ]);
    }

    public function store(StoreSupportTemplateRequest $request): JsonResponse
    {
        $admin = $request->user();
        $data  = $request->validated();

        $template = SupportTemplate::create([
            'category_id' => $data['category_id'],
            'title'       => $data['title'],
            'body'        => $data['body'],
            'is_active'   => $data['is_active'] ?? true,
            'sort_order'  => $data['sort_order'] ?? 0,
        ]);

        AdminLog::record('support_template.create', $template, $admin, null, [
            'status'  => 'created',
            'context' => [
                'template_id' => $template->id,
                'title'       => $template->title,
                'category_id' => $template->category_id,
            ],
        ]);

        $this->clearCache();

        return response()->json(['message' => 'Шаблон создан!', 'data' => $this->formatTemplate($template)]);
    }

    /**
     * Детали одного шаблона — для отдельной страницы редактирования.
     */
    public function show(Request $request, $id): JsonResponse
    {
        $template = SupportTemplate::with('category')->find((int) $id);
        if (!$template) {
            return response()->json(['message' => 'Шаблон не найден'], 404);
        }

        return response()->json([
            'data' => $this->formatTemplate($template),
        ]);
    }

    public function update(UpdateSupportTemplateRequest $request, $id): JsonResponse
    {
        $admin    = $request->user();
        $template = SupportTemplate::find((int) $id);
        if (!$template) {
            return response()->json(['message' => 'Шаблон не найден'], 404);
        }

        $data = $request->validated();

        $before = [
            'category_id' => $template->getOriginal('category_id'),
            'title'       => $template->getOriginal('title'),
            'body'        => $template->getOriginal('body'),
            'is_active'   => (bool) $template->getOriginal('is_active'),
            'sort_order'  => $template->getOriginal('sort_order'),
        ];

        $template->update([
            'category_id' => $data['category_id'],
            'title'       => $data['title'],
            'body'        => $data['body'],
            'is_active'   => $data['is_active'] ?? $template->is_active,
            'sort_order'  => $data['sort_order'] ?? $template->sort_order,
        ]);

        AdminLog::record('support_template.update', $template, $admin, $before, [
            'category_id' => $template->category_id,
            'title'       => $template->title,
            'body'        => $template->body,
            'is_active'   => $template->is_active,
            'sort_order'  => $template->sort_order,
        ]);

        $this->clearCache();

        return response()->json(['message' => 'Шаблон обновлен!', 'data' => $this->formatTemplate($template)]);
    }

    public function destroy(Request $request, $id): JsonResponse
    {
        $admin    = $request->user();
        $template = SupportTemplate::find((int) $id);
        if (!$template) {
            return response()->json(['message' => 'Шаблон не найден'], 404);
        }

        $before = [
            'title'       => $template->title,
            'category_id' => $template->category_id,
            'is_active'   => (bool) $template->is_active,
        ];

        AdminLog::record('support_template.delete', $template, $admin, $before, [
            'status'  => 'destroyed',
            'context' => [
                'template_id' => $template->id,
                'title'       => $template->title,
            ],
        ]);

        $template->delete();
        $this->clearCache();

        return response()->json(['message' => 'Шаблон удален.']);
    }

    // ============================================
    // КАТЕГОРИИ — список / CRUD
    // ============================================

    public function categoriesIndex(Request $request): JsonResponse
    {
        $search = (string) $request->string('q', '');

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isId           = $search !== '' && ctype_digit($search);

        // Один SQL с withCount — LEFT JOIN считает шаблоны в каждой категории.
        // Раньше было два SQL: один для categories, второй для counts по category_id.
        // withCount добавляет колонку `templates_count` к каждой категории.
        $query = SupportTemplateCategory::query()
            ->withCount('templates')
            ->when($search !== '', function ($q) use ($search, $searchOperator, $isId) {
                if ($isId) {
                    $q->where('id', (int) $search);
                } else {
                    $q->where('name', $searchOperator, "%{$search}%")
                        ->orWhere('slug', $searchOperator, "%{$search}%");
                }
            })
            ->when($isId, function ($q) use ($search) {
                $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
            })
            ->orderBy('sort_order')
            ->orderBy('name');

        $categories = $query->get();

        return response()->json([
            // templates_count берётся из withCount (колонка в response),
            // не нужен отдельный query по category_id.
            'data' => $categories->map(fn(SupportTemplateCategory $c) => $this->formatCategory($c, $c->templates_count)),
            'counts' => $this->getCounts(),
        ]);
    }

    public function storeCategory(StoreSupportTemplateCategoryRequest $request): JsonResponse
    {
        $admin = $request->user();
        $data  = $request->validated();

        // Системная категория "Общие" — slug всегда general
        if (mb_strtolower(trim($data['name'])) === 'общие') {
            $data['slug'] = 'general';
        }

        // Формируем slug
        $finalSlug = !empty($data['slug']) ? Str::slug($data['slug']) : Str::slug($data['name']);
        if (empty($finalSlug)) {
            $finalSlug = 'category-' . time();
        }

        // Проверяем уникальность slug ДО создания
        $existing = SupportTemplateCategory::where('slug', $finalSlug)->exists();
        if ($existing) {
            return response()->json([
                'message' => "Категория с slug «{$finalSlug}» уже существует. Выберите другой slug.",
                'errors'  => ['slug' => ["Slug «{$finalSlug}» уже занят."]],
            ], 422);
        }

        $category = SupportTemplateCategory::create([
            'name'       => trim($data['name']),
            'slug'       => $finalSlug,
            'is_active'  => $data['is_active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);

        AdminLog::record('support_template_category.create', $category, $admin, null, [
            'status'  => 'created',
            'context' => [
                'category_id' => $category->id,
                'name'         => $category->name,
                'slug'         => $category->slug,
            ],
        ]);

        $this->clearCache();

        return response()->json(['message' => 'Категория создана!', 'data' => $this->formatCategory($category, 0)]);
    }

    public function updateCategory(UpdateSupportTemplateCategoryRequest $request, $id): JsonResponse
    {
        $admin    = $request->user();
        $category = SupportTemplateCategory::find((int) $id);
        if (!$category) {
            return response()->json(['message' => 'Категория не найдена'], 404);
        }

        $data = $request->validated();

        // Защита системной категории: нельзя менять slug
        if (in_array($category->slug, ['general', 'obshhie']) && !empty($data['slug']) && $data['slug'] !== 'general') {
            return response()->json(['message' => 'Нельзя менять Slug у системной категории!'], 422);
        }

        // Системная категория "Общие" → slug всегда general
        if (mb_strtolower(trim($data['name'])) === 'общие') {
            $data['slug'] = 'general';
        }

        $finalSlug = !empty($data['slug']) ? Str::slug($data['slug']) : Str::slug($data['name']);
        if (empty($finalSlug)) {
            $finalSlug = 'category-' . time();
        }

        // Проверяем уникальность slug среди ДРУГИХ категорий
        $existing = SupportTemplateCategory::where('slug', $finalSlug)
            ->where('id', '!=', $category->id)
            ->exists();
        if ($existing) {
            return response()->json([
                'message' => "Категория с slug «{$finalSlug}» уже существует. Выберите другой slug.",
                'errors'  => ['slug' => ["Slug «{$finalSlug}» уже занят."]],
            ], 422);
        }

        $before = [
            'name'       => $category->getOriginal('name'),
            'slug'       => $category->getOriginal('slug'),
            'is_active'  => (bool) $category->getOriginal('is_active'),
            'sort_order' => $category->getOriginal('sort_order'),
        ];

        $category->update([
            'name'       => trim($data['name']),
            'slug'       => $finalSlug,
            'is_active'  => $data['is_active'] ?? $category->is_active,
            'sort_order' => $data['sort_order'] ?? $category->sort_order,
        ]);

        AdminLog::record('support_template_category.update', $category, $admin, $before, [
            'name'       => $category->name,
            'slug'       => $category->slug,
            'is_active'  => $category->is_active,
            'sort_order' => $category->sort_order,
        ]);

        $this->clearCache();

        return response()->json(['message' => 'Категория обновлена!', 'data' => $this->formatCategory($category, 0)]);
    }

    public function destroyCategory(Request $request, $id): JsonResponse
    {
        $admin    = $request->user();
        $category = SupportTemplateCategory::find((int) $id);
        if (!$category) {
            return response()->json(['message' => 'Категория не найдена'], 404);
        }

        // Защита системной категории
        if (in_array($category->slug, ['general', 'obshhie'])) {
            return response()->json(['message' => 'Нельзя удалить системную категорию "Общие"!'], 422);
        }

        // Перенос шаблонов в "Общие" перед удалением
        $generalCategory = SupportTemplateCategory::whereIn('slug', ['general', 'obshhie'])->first();
        if ($generalCategory) {
            SupportTemplate::where('category_id', $id)->update(['category_id' => $generalCategory->id]);
        }

        $before = [
            'name' => $category->name,
            'slug' => $category->slug,
        ];

        AdminLog::record('support_template_category.delete', $category, $admin, $before, [
            'status'  => 'destroyed',
            'context' => [
                'category_id'        => $category->id,
                'name'               => $category->name,
                'templates_moved_to' => $generalCategory?->id,
            ],
        ]);

        $category->delete();
        $this->clearCache();

        return response()->json(['message' => "Категория удалена. Шаблоны перемещены в 'Общие'."]);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getCounts(): array
    {
        return Cache::remember('admin_support_template_counts', 60, function () {
            $stats = SupportTemplate::query()
                ->selectRaw("COUNT(*) as total")
                ->selectRaw("SUM(CASE WHEN is_active = true THEN 1 ELSE 0 END) as active")
                ->first();

            $categoryCounts = SupportTemplate::query()
                ->selectRaw("category_id, COUNT(*) as count")
                ->whereNotNull('category_id')
                ->groupBy('category_id')
                ->pluck('count', 'category_id');

            return [
                'total'      => (int) ($stats?->total ?? 0),
                'active'     => (int) ($stats?->active ?? 0),
                'categories' => $categoryCounts->toArray(),
            ];
        });
    }

    private function clearCache(): void
    {
        Cache::forget('admin_support_template_counts');
        Cache::forget('admin_support_templates');
    }

    private function formatTemplate(SupportTemplate $t): array
    {
        return [
            'id'          => $t->id,
            'title'       => $t->title,
            'body'        => $t->body,
            'is_active'   => (bool) $t->is_active,
            'sort_order'  => (int) $t->sort_order,
            'category_id' => $t->category_id,
            'category'    => $t->category ? [
                'id'   => $t->category->id,
                'name' => $t->category->name,
                'slug' => $t->category->slug,
            ] : null,
            'created_at'  => $t->created_at?->toIso8601String(),
            'updated_at'  => $t->updated_at?->toIso8601String(),
        ];
    }

    private function formatCategory(SupportTemplateCategory $c, int $templatesCount = 0): array
    {
        return [
            'id'              => $c->id,
            'name'            => $c->name,
            'slug'            => $c->slug,
            'is_active'       => (bool) $c->is_active,
            'sort_order'      => (int) $c->sort_order,
            'is_system'       => in_array($c->slug, ['general', 'obshhie']),
            'templates_count' => $templatesCount,
        ];
    }
}
