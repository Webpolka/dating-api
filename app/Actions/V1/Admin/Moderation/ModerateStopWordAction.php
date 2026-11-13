<?php

namespace App\Actions\Admin\Moderation;

use App\Enums\StopWordAction;
use App\Enums\StopWordCategory;
use App\Models\AdminLog;
use App\Models\StopWord;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Бизнес-логика управления справочником стоп-слов.
 * Извлечена из StopWordController (thin-controller pattern, см. ModeratePhotoAction).
 *
 * Архитектура:
 *   - Controller (HTTP-слой): валидация (FormRequest), resolution модели + 404,
 *     форматирование ответа (список/счётчики/сообщения для тостов).
 *   - ModerateStopWordAction (бизнес-логика): CUD-операции над stop_words,
 *     AdminLog, инвалидация кэша.
 *
 * Кэш:
 *   - 'stop_words_active'      — рантайм-кэш ContentFilter-сервиса, сбрасывается
 *                                после каждой мутации.
 *   - 'admin_stopword_counts'  — кэш счётчиков для админки (60 сек).
 */
class ModerateStopWordAction
{
    // ============================================
    // МАССОВОЕ СОЗДАНИЕ
    // ============================================

    /**
     * Массовое создание стоп-слов.
     * Разбивает строку по запятым / переносам / точкам с запятой.
     * insertOrIgnore пропускает дубликаты (благодаря UNIQUE индексу на word).
     *
     * @param User  $admin     Кто выполнил действие.
     * @param array $validated Валидированные данные StoreBulkStopWordsRequest.
     *                         Формат: {words: string, category: string, action: string}.
     * @return array{created_count: int, duplicate_count: int, total_count: int}|null
     *         null — после разбора строки не осталось ни одного слова
     *         (контроллер возвращает 422 «Нечего сохранять»).
     */
    public function storeBulk(User $admin, array $validated): ?array
    {
        $category = StopWordCategory::from($validated['category']);
        $action   = StopWordAction::from($validated['action']);

        // Разбиваем по запятым, переносам, точкам с запятой
        $rawWords = preg_split('/[\r\n,;]+/', $validated['words']);

        $rows = [];
        $now  = now();
        // Дедупликация внутри введённого набора тоже по нижнему регистру
        $seenNormalized = [];
        foreach ($rawWords as $wordStr) {
            $wordStr = trim($wordStr);
            if ($wordStr === '') {
                continue;
            }
            // Защита от слишком длинных строк (БД-ограничение 255)
            $wordStr = mb_substr($wordStr, 0, 255);

            // Регистронезависимая дедупликация:
            // "Казино" и "казино" — это одно и то же слово.
            // В БД сохраняем оригинальное написание (для админки читабельнее),
            // но для сравнения используем mb_strtolower.
            $normalized = mb_strtolower($wordStr, 'UTF-8');
            if (isset($seenNormalized[$normalized])) {
                // Уже вводили это слово в этой же пачке — пропускаем
                continue;
            }
            $seenNormalized[$normalized] = true;

            $rows[] = [
                'word'        => $wordStr,
                'category'    => $category->value,
                'action'      => $action->value,
                'replacement' => '***',
                'is_active'   => true,
                'created_at'  => $now,
                'updated_at'  => $now,
            ];
        }

        if (empty($rows)) {
            return null;
        }

        // === РЕГИСТРОНЕЗАВИСИМАЯ ДЕДУПЛИКАЦИЯ ===
        // PostgreSQL UNIQUE-индекс на word регистрозависимый: "Казино" и "казино" для него — разные строки.
        // Поэтому мы ВРУЧНУЮ вычисляем, какие из введённых слов уже есть в БД (сравнивая LOWER(word)),
        // удаляем их из $rows, и только реально новые вставляем через insert().
        $normalizedStrings = array_map(fn($r) => mb_strtolower($r['word'], 'UTF-8'), $rows);

        // whereRaw с LOWER(word) IN (?, ?, ...) — корректный синтаксис PostgreSQL
        $placeholders = implode(',', array_fill(0, count($normalizedStrings), '?'));

        $existingWords = StopWord::query()
            ->whereRaw("LOWER(word) IN ({$placeholders})", $normalizedStrings)
            ->pluck('word')
            ->map(fn($w) => mb_strtolower($w, 'UTF-8'))
            ->toArray();

        // Фильтруем: оставляем только те строки, которых нет в БД (по нижнему регистру)
        $rowsToInsert = array_values(array_filter(
            $rows,
            fn($r) => !in_array(mb_strtolower($r['word'], 'UTF-8'), $existingWords, true)
        ));

        $totalCount    = count($rows);               // сколько юзер ввёл (без пустых и дубликатов внутри пачки)
        $duplicateCount = $totalCount - count($rowsToInsert); // сколько пропущено как дубликаты БД
        $createdCount   = 0;
        $createdWords   = collect();

        if (!empty($rowsToInsert)) {
            // ОДИН bulk-запрос. Дубликатов здесь уже нет (мы их отфильтровали выше),
            // поэтому можно использовать insert() — он вернёт true/false.
            StopWord::insert($rowsToInsert);
            $createdCount = count($rowsToInsert);

            // Достаём только что созданные модели (для логирования и контекста)
            $createdNormalized = array_map(fn($r) => mb_strtolower($r['word'], 'UTF-8'), $rowsToInsert);
            $createdPlaceholders = implode(',', array_fill(0, count($createdNormalized), '?'));

            $createdWords = StopWord::query()
                ->whereRaw("LOWER(word) IN ({$createdPlaceholders})", $createdNormalized)
                ->get();

            // Логирование — РАЗДЕЛЬНО по характеру действия:
            //  - createdCount === 1 → действие 'stop_words.create', loggable = сама модель (прямая ссылка)
            //  - createdCount > 1   → действие 'stop_words.bulk_create', loggable = null
            //    (массовая операция, в context.word_ids список всех ID)
            if ($createdCount === 1) {
                $first = $createdWords->first();
                AdminLog::record('stop_words.create', $first, $admin, null, [
                    'status'  => 'created',
                    'count'   => 1,
                    'context' => [
                        'category'       => $category->value,
                        'action'         => $action->value,
                        'word_id'        => $first->id,
                        'word'           => $first->word,
                        'duplicate_count' => $duplicateCount,
                    ],
                ]);
            } else {
                AdminLog::record('stop_words.bulk_create', null, $admin, null, [
                    'status'  => 'created',
                    'count'   => $createdCount,
                    'context' => [
                        'category'        => $category->value,
                        'action'          => $action->value,
                        'word_ids'        => $createdWords->pluck('id')->toArray(),
                        'words'           => $createdWords->pluck('word')->toArray(),
                        'duplicate_count' => $duplicateCount,
                    ],
                ]);
            }
        }

        $this->clearCache();

        // Подробный отчёт для тоста: что добавилось, что пропущено (дубликаты)
        $totalCount     = count($rows);   // сколько строк юзер реально ввёл (без пустых)
        $duplicateCount = $totalCount - $createdCount;

        return [
            'created_count'   => $createdCount,
            'duplicate_count' => $duplicateCount,
            'total_count'     => $totalCount,
        ];
    }

    // ============================================
    // ОДИНОЧНЫЕ ОПЕРАЦИИ
    // ============================================

    /**
     * Редактирование одного стоп-слова (word/category/action/replacement/is_active).
     *
     * ВАЖНО про unique constraint: поле word имеет UNIQUE индекс в БД.
     * Если админ меняет word на уже существующий — БД бросит
     * UniqueConstraintViolationException (контроллер ловит и возвращает 422).
     *
     * @param StopWord $word      Целевое слово (найдено в контроллере).
     * @param User     $admin     Кто выполнил действие.
     * @param array    $validated Валидированные данные UpdateStopWordRequest.
     */
    public function update(StopWord $word, User $admin, array $validated): void
    {
        $before = [
            'word'        => $word->getOriginal('word'),
            'category'    => $word->getOriginal('category'),
            'action'      => $word->getOriginal('action'),
            'replacement' => $word->getOriginal('replacement'),
            'is_active'   => (bool) $word->getOriginal('is_active'),
        ];

        $word->update([
            'word'        => trim($validated['word']),
            'category'    => StopWordCategory::from($validated['category'])->value,
            'action'      => StopWordAction::from($validated['action'])->value,
            'replacement' => $validated['replacement'] ?? $word->replacement,
            'is_active'   => $validated['is_active'] ?? $word->is_active,
        ]);

        AdminLog::record('stop_words.update', $word, $admin, $before, [
            'word'        => $word->word,
            'category'    => $word->category->value,
            'action'      => $word->action->value,
            'replacement' => $word->replacement,
            'is_active'   => $word->is_active,
            'context'     => [
                'word_id' => $word->id,
            ],
        ]);

        $this->clearCache();
    }

    /**
     * Переключатель is_active для одного слова.
     *
     * @param StopWord $word  Целевое слово (найдено в контроллере). После вызова
     *                        модель несёт новое значение is_active (для ответа).
     * @param User     $admin Кто выполнил действие.
     */
    public function toggleActive(StopWord $word, User $admin): void
    {
        $before = ['is_active' => $word->getOriginal('is_active')];

        $word->update(['is_active' => !$word->is_active]);

        AdminLog::record('stop_words.toggle', $word, $admin, $before, [
            'is_active' => $word->is_active,
            'context'   => [
                'word_id' => $word->id,
                'word'    => $word->word,
            ],
        ]);

        $this->clearCache();
    }

    /**
     * Удаление одного слова.
     *
     * @param StopWord $word  Целевое слово (найдено в контроллере).
     * @param User     $admin Кто выполнил действие.
     */
    public function destroy(StopWord $word, User $admin): void
    {
        $before = ['is_active' => $word->getOriginal('is_active')];

        AdminLog::record('stop_words.delete', $word, $admin, $before, [
            'status'  => 'destroyed',
            'context' => [
                'word_id' => $word->id,
                'word'    => $word->word,
            ],
        ]);

        $word->delete();
        $this->clearCache();
    }

    // ============================================
    // МАССОВЫЕ ДЕЙСТВИЯ
    // ============================================

    /**
     * Массовое действие над выбранными словами.
     *
     * Логирование:
     *  - Если выбрано РОВНО 1 слово → передаём модель как loggable (прямая ссылка в журнале)
     *    Для delete — UI рендерит ссылку перечёркнутой (модель удалена)
     *  - Если выбрано >1 слова → loggable = null, в context.affected_ids просто список ID
     *    (UI журнала не делает ссылку, показывает "обработано N слов: 12, 14, 18, ...")
     *  - Для delete — дополнительно сохраняем snapshot {id, word} каждой удалённой записи
     *    (после delete они исчезнут, без snapshot контекст будет бесполезен)
     *
     * @param array  $ids    ID выбранных слов (уже intval, провалидированы).
     * @param string $action 'activate' | 'deactivate' | 'delete'.
     * @param User   $admin  Кто выполнил действие.
     */
    public function applyBulk(array $ids, string $action, User $admin): void
    {
        $count = count($ids);

        // Загружаем первую запись ДО действия (для loggable, если выбран ровно 1).
        // И для delete — снимаем snapshot всех записей (id + word) ДО удаления.
        $firstWord = $count === 1 ? StopWord::find($ids[0]) : null;

        $affectedSnapshot = null;
        if ($action === 'delete') {
            $affectedSnapshot = StopWord::whereIn('id', $ids)
                ->get(['id', 'word'])
                ->map(fn($w) => ['id' => $w->id, 'word' => $w->word])
                ->toArray();
        }

        DB::transaction(function () use ($ids, $action) {
            match ($action) {
                'activate'   => StopWord::whereIn('id', $ids)->update(['is_active' => true]),
                'deactivate' => StopWord::whereIn('id', $ids)->update(['is_active' => false]),
                'delete'     => StopWord::whereIn('id', $ids)->delete(),
                default      => null,
            };
        });

        AdminLog::record('stop_words.bulk_action', $firstWord, $admin, null, [
            'status'  => $action,
            'count'   => $count,
            'context' => [
                'action'         => $action,
                'affected_count' => $count,
                'affected_ids'   => $ids,
                'affected_words' => $affectedSnapshot,
            ],
        ]);

        $this->clearCache();
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function clearCache(): void
    {
        Cache::forget('stop_words_active');
        Cache::forget('admin_stopword_counts');
    }
}
