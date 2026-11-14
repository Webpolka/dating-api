<?php

namespace App\Http\Controllers\Api\V1\Admin\Communication;

use App\Actions\V1\Admin\Moderation\ModerateStopWordAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Moderation\ApplyBulkStopWordRequest;
use App\Http\Requests\Api\V1\Admin\Moderation\StoreBulkStopWordsRequest;
use App\Http\Requests\Api\V1\Admin\Moderation\UpdateStopWordRequest;
use App\Http\Resources\Api\V1\Admin\Communication\StopWordResource;
use App\Models\StopWord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Стоп-слова и фильтры контента.
 *
 * Архитектура:
 *  - Controller (HTTP-слой): валидация (FormRequest), resolution модели + 404,
 *    форматирование ответа (через StopWordResource), тосты для UI
 *  - ModerateStopWordAction (бизнес-логика): CUD-операции над stop_words,
 *    AdminLog, сброс кэша stop_words_active (используется ContentFilterService
 *    в рантайме при проверке сообщений)
 *  - Поиск по ID: искомое слово всегда первым (через ORDER BY CASE)
 *
 * Примечание про cross-domain import: ModerateStopWordAction лежит в
 * App\Actions\V1\Admin\Moderation\ (а не Communication), потому что
 * семантически это модерационная операция. Stop-word controller физически
 * в Communication секции, но использует Moderation Action — это нормально,
 * домены могут пересекаться на уровне бизнес-операций.
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

        $words = StopWord::query()
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
            ->when($statusFilter !== 'all',   fn($q) => $q->where('is_active', $statusFilter === 'active'))
            ->when($isId, fn($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]))
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return $this->paginated($words, StopWordResource::class, [
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
            return $this->message('Нечего сохранять: поле пустое!', 422, [
                'created_count' => 0,
            ]);
        }

        return $this->message(
            $this->buildCreateMessage($result['created_count'], $result['duplicate_count'], $result['total_count']),
            200,
            [
                'created_count'   => $result['created_count'],
                'duplicate_count' => $result['duplicate_count'],
                'total_count'     => $result['total_count'],
            ],
        );
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
        if (! $word) {
            return $this->notFound('Слово');
        }

        try {
            $action->update($word, $request->user(), $request->validated());
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            return $this->message('Такое слово уже существует в базе.', 422, [
                'errors' => ['word' => ['Это слово уже есть в справочнике.']],
            ]);
        }

        return $this->message('Слово обновлено!', 200, [
            'data' => new StopWordResource($word),
        ]);
    }

    /**
     * Переключатель is_active для одного слова.
     */
    public function toggleActive(Request $request, int $id, ModerateStopWordAction $action): JsonResponse
    {
        $word = StopWord::find($id);
        if (! $word) {
            return $this->notFound('Слово');
        }

        $action->toggleActive($word, $request->user());

        return $this->message('Статус изменен', 200, [
            'is_active' => $word->is_active,
        ]);
    }

    /**
     * Удаление одного слова.
     */
    public function destroy(Request $request, int $id, ModerateStopWordAction $action): JsonResponse
    {
        $word = StopWord::find($id);
        if (! $word) {
            return $this->notFound('Слово');
        }

        $action->destroy($word, $request->user());

        return $this->message('Слово удалено');
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

        return $this->message("Массовое действие применено: {$labels[$bulkAction]} — {$count} шт.");
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
        if ($created === 0) {
            return "Все {$total} слов уже существуют в базе";
        }
        if ($duplicates === 0) {
            return "Добавлено новых слов: {$created}";
        }
        return "Добавлено новых слов: {$created} · Пропущено дубликатов: {$duplicates}";
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