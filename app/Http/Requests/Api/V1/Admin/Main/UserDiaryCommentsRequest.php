<?php

namespace App\Http\Requests\Api\V1\Admin\Main;

/**
 * Параметры пагинации для таба «Комм. дневников» пользователя.
 *
 * GET /api/admin/users/{user}/diary-comments?made_page=1&received_page=2
 *
 * В одном запросе две независимые пагинации:
 *   - made     — комментарии, НАПИСАННЫЕ пользователем (diary_comments.user_id = userId)
 *   - received — комментарии под ЗАПИСЯМИ пользователя (diary.user_id = userId),
 *                автор — любой другой юзер
 *
 * Паттерн идентичен UserReportsRequest / UserPhotoCommentsRequest: один `page`
 * нельзя — иначе переключение одной колонки сбрасывало бы вторую.
 *
 * Фильтра статусов нет: обе колонки показывают всю историю «как есть» (включая
 * карантин — soft-deleted), статус виден бейджем в каждой строке. Масс-фильтрация
 * живёт в глобальной очереди модерации.
 */
class UserDiaryCommentsRequest extends UserListRequest
{
    public function rules(): array
    {
        return [
            'made_page'     => ['nullable', 'integer', 'min:1'],
            'received_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function madePage(int $default = 1): int
    {
        $validated = $this->validated();
        return isset($validated['made_page']) ? (int) $validated['made_page'] : $default;
    }

    public function receivedPage(int $default = 1): int
    {
        $validated = $this->validated();
        return isset($validated['received_page']) ? (int) $validated['received_page'] : $default;
    }
}
