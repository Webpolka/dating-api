<?php

namespace App\Http\Controllers\Api\V1\Admin\Communication;

use App\Actions\V1\Admin\Moderation\ModerateStopWordAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Moderation\ApplyBulkStopWordRequest;
use App\Http\Requests\Api\V1\Admin\Moderation\StoreBulkStopWordsRequest;
use App\Http\Requests\Api\V1\Admin\Moderation\UpdateStopWordRequest;
use App\Models\StopWord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Стоп-слова и фильтры контента.
 *
 * Архитектура:
 *  - Controller (HTTP-слой): валидация (FormRequest), resolution модели + 404,
 *    форматирование ответа (список/счётчики/сообщения для тостов)
 *  - ModerateStopWordAction (бизнес-логика): CUD-операции над stop_words,
 *    AdminLog, сброс кэша stop_words_active (используется ContentFilter-сервисом в рантайме)
 *  - Поиск по ID: искомое слово всегда первым (через ORDER BY CASE)
 */
class StopWordController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $search         = $request->string('q', '')->toString();
        $categoryFilter = $request->string('category', 'all')->toString();
        $actionFilter   = $request->string('action', 'all')->toString();
        $statusFilter   = $request->string('status', 'all')->toString();
        $perPage        = min(max((int) $request->integer('per_page', 50), 10), 200);
        $page           = max((int) $request->integer('page', 1), 1);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isId           = $search !== '' && ctype_digit($search);

        $query = StopWord::query()
            ->when($search !== '', function ($q) use ($search, $searchOperator, $isId) {
                $q->where(function ($q) use ($search, $searchOperator, $isId) {
                    $q->where('word', $searchOperator, "%{$search}%");
                    if ($isId) {
                        $q->orWhere('id', (int) $search);
                    }
                });
            })
            ->when($categoryFilter !== 'all', fn($q) => $q->where('category', $categoryFilter))
            ->when($actionFilter !== 'all',   fn($q) => $q->where('action', $actionFilter))
            ->when($statusFilter !== 'all',  fn($q) => $q->where('is_active', $statusFilter === 'active'));

        // При поиске по ID — искомая запись всегда первой
        $query->when($isId, function ($q) use ($search) {
            $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
        })
            ->latest('created_at')
            ->latest('id');

        $words = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $words->map(fn(StopWord $w) => $this->formatWord($w)),
            'meta' => [
                'current_page' => $words->currentPage(),
                'last_page'    => $words->lastPage(),
                'per_page'     => $words->perPage(),
                'total'        => $words->total(),
                'from'         => $words->firstItem(),
                'to'           => $words->lastItem(),
            ],
            'counts' => $this->getCounts(),
        ]);
    }

    public function counts(): JsonResponse
    {
        return response()->json(['data' => $this->getCounts()]);
    }

    // ============================================
    // ДЕЙСТВИЯ — делегируют ModerateStopWordAction
    // ============================================

    /**
     * Массовое создание стоп-слов.
     */
    public function storeBulk(StoreBulkStopWordsRequest $request, ModerateStopWordAction $action): JsonResponse
    {
        // null — после разбора строки не осталось ни одного слова
        $result = $action->storeBulk($request->user(), $request->validated());

        if ($result === null) {
            return response()->json([
                'message'       => 'Нечего сохранять: поле пустое!',
                'created_count' => 0,
            ], 422);
        }

        return response()->json([
            'message'         => $this->buildCreateMessage($result['created_count'], $result['duplicate_count'], $result['total_count']),
            'created_count'   => $result['created_count'],
            'duplicate_count' => $result['duplicate_count'],
            'total_count'     => $result['total_count'],
        ]);
    }

    /**
     * Редактирование одного стоп-слова (word/category/action/replacement/is_active).
     *
     * ВАЖНО про unique constraint: поле word имеет UNIQUE индекс в БД.
     * Если админ меняет word на уже существующий — БД бросит
     * UniqueConstraintViolationException. Ловим и возвращаем 422.
     */
    public function update(UpdateStopWordRequest $request, int $id, ModerateStopWordAction $action): JsonResponse
    {
        $word = StopWord::find($id);
        if (!$word) {
            return response()->json(['message' => 'Слово не найдено'], 404);
        }

        try {
            $action->update($word, $request->user(), $request->validated());
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return response()->json([
                'message' => 'Такое слово уже существует в базе.',
                'errors'  => ['word' => ['Это слово уже есть в справочнике.']],
            ], 422);
        }

        return response()->json([
            'message' => 'Слово обновлено!',
            'data'    => $this->formatWord($word),
        ]);
    }

    /**
     * Переключатель is_active для одного слова.
     */
    public function toggleActive(Request $request, int $id, ModerateStopWordAction $action): JsonResponse
    {
        $word = StopWord::find($id);
        if (!$word) {
            return response()->json(['message' => 'Слово не найдено'], 404);
        }

        $action->toggleActive($word, $request->user());

        return response()->json([
            'message'   => 'Статус изменен',
            'is_active' => $word->is_active,
        ]);
    }

    /**
     * Удаление одного слова.
     */
    public function destroy(Request $request, int $id, ModerateStopWordAction $action): JsonResponse
    {
        $word = StopWord::find($id);
        if (!$word) {
            return response()->json(['message' => 'Слово не найдено'], 404);
        }

        $action->destroy($word, $request->user());

        return response()->json(['message' => 'Слово удалено']);
    }

    /**
     * Массовое действие над выбранными словами.
     * Body: { ids: number[], action: 'activate' | 'deactivate' | 'delete' }
     */
    public function applyBulk(ApplyBulkStopWordRequest $request, ModerateStopWordAction $action): JsonResponse
    {
        $data = $request->validated();

        $ids        = array_map('intval', $data['ids']);
        $bulkAction = $data['action'];
        $count      = count($ids);

        $action->applyBulk($ids, $bulkAction, $request->user());

        $labels = [
            'activate'   => 'активировано',
            'deactivate' => 'деактивировано',
            'delete'     => 'удалено',
        ];

        return response()->json([
            'message' => "Массовое действие применено: {$labels[$bulkAction]} — {$count} шт.",
        ]);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    /**
     * Формирует понятное сообщение для тоста:
     *  - 5 добавлено, 0 дублей → "Добавлено новых слов: 5"
     *  - 3 добавлено, 2 дубля → "Добавлено: 3 · Пропущено дубликатов: 2"
     *  - 0 добавлено, 5 дублей → "Все 5 слов уже существуют в базе"
     */
    private function buildCreateMessage(int $created, int $duplicates, int $total): string
    {
        // Ничего не добавилось — все дубли
        if ($created === 0) {
            return "Все {$total} слов уже существуют в базе";
        }

        // Все добавились новые (дубликатов нет)
        if ($duplicates === 0) {
            return "Добавлено новых слов: {$created}";
        }

        // Часть добавилась, часть пропущена — показываем обе цифры
        return "Добавлено новых слов: {$created} · Пропущено дубликатов: {$duplicates}";
    }

    private function formatWord(StopWord $w): array
    {
        $actionBadge = $w->actionBadge;
        return [
            'id'            => $w->id,
            'word'          => $w->word,
            'category'      => $w->category->value,
            'category_label' => $w->category->label(),
            'action'        => $w->action->value,
            'action_label'  => $w->action->label(),
            'action_variant' => $actionBadge['variant'],
            'replacement'   => $w->replacement,
            'is_active'     => (bool) $w->is_active,
            'created_at'    => $w->created_at?->toIso8601String(),
        ];
    }

    private function getCounts(): array
    {
        return Cache::remember('admin_stopword_counts', 60, function () {
            $stats = StopWord::query()
                ->selectRaw("COUNT(*) as total")
                ->selectRaw("SUM(CASE WHEN is_active = true THEN 1 ELSE 0 END) as active")
                ->first();

            return [
                'total'  => (int) ($stats?->total ?? 0),
                'active' => (int) ($stats?->active ?? 0),
            ];
        });
    }
}
