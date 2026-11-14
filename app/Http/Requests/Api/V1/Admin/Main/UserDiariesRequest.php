<?php

namespace App\Http\Requests\Api\V1\Admin\Main;

/**
 * Параметры для таба «Дневники» пользователя.
 *
 * GET /api/admin/users/{user}/diaries?rubric=all&search=&page=2
 *
 * Дневники, НАПИСАННЫЕ пользователем (author = user).
 * Всегда показывает soft-deleted (withTrashed) — карантин виден бейджем строки,
 * как в старом blade-табе. Без отдельного фильтра по статусу: статус виден
 * бейджем в каждой строке, масс-фильтрация остаётся в глобальном разделе модерации.
 *
 * rubric — фильтр по рубрике:
 *   all  (дефолт) — все записи
 *   none          — записи без рубрики (diary_rubric_id IS NULL)
 *   <id>          — записи конкретной рубрики (только рубрики, в которых у юзера
 *                   реально есть посты — список приходит отдельным полем `rubrics`
 *                   в ответе, фронт рисует кнопки по нему)
 *
 * search — поиск по названию (ilike на pgsql, like на прочих) и по id,
 *          если поисковый запрос — число. Debounce на фронте 300ms.
 */
class UserDiariesRequest extends UserListRequest
{
    public function rules(): array
    {
        return [
            'rubric' => ['nullable', 'string'],
            'search' => ['nullable', 'string', 'max:120'],
            'page'   => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Рубрика-фильтр. Возвращает 'all' | 'none' | (int) id.
     * Числовую строку приводим к int — фронт шлёт id рубрики строкой из URL.
     */
    public function rubric(string $default = 'all')
    {
        $validated = $this->validated();
        if (!isset($validated['rubric'])) {
            return $default;
        }
        $rubric = (string) $validated['rubric'];
        if ($rubric === 'all' || $rubric === 'none') {
            return $rubric;
        }
        return is_numeric($rubric) ? (int) $rubric : $default;
    }

    public function search(): ?string
    {
        $validated = $this->validated();
        $search = isset($validated['search']) ? trim((string) $validated['search']) : '';
        return $search !== '' ? $search : null;
    }
}
