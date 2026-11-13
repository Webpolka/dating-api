<?php

namespace App\Http\Requests\Api\V1\Admin\Main;

/**
 * Параметры пагинации для таба «Знакомства» пользователя.
 *
 * GET /api/admin/users/{user}/dating?swipes_page=1&matches_page=2
 *
 * В одном запросе две независимые пагинации:
 *   - swipes  — свайпы, сделанные пользователем (user_id = userId)
 *   - matches — мэтчи с участием пользователя (user1_id | user2_id = userId)
 *
 * Паттерн идентичен UserReportsRequest (made/received) — один `page`
 * нельзя: переключение одной колонки сбрасывало бы вторую.
 */
class UserDatingRequest extends UserListRequest
{
    public function rules(): array
    {
        return [
            'swipes_page'  => ['nullable', 'integer', 'min:1'],
            'matches_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function swipesPage(int $default = 1): int
    {
        $validated = $this->validated();
        return isset($validated['swipes_page']) ? (int) $validated['swipes_page'] : $default;
    }

    public function matchesPage(int $default = 1): int
    {
        $validated = $this->validated();
        return isset($validated['matches_page']) ? (int) $validated['matches_page'] : $default;
    }
}
