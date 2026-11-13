<?php

namespace App\Http\Requests\Api\V1\Admin\Main;

/**
 * Параметры для таба «Комм. дневников» пользователя.
 *
 * GET /api/admin/users/{user}/diary-comments?status=pending&page=2
 *
 * Комментарии к дневникам, НАПИСАННЫЕ пользователем (author = user).
 *
 * Варианты status — зеркалит enum diary_comments.status:
 *   all | pending | approved | rejected | spam | karantine
 *
 * «Карантин» = soft-deleted (deleted_at IS NOT NULL) — та же семантика,
 * что в PhotoCommentController и табе «Комм. фото».
 */
class UserDiaryCommentsRequest extends UserListRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'status' => ['nullable', 'in:all,pending,approved,rejected,spam,karantine'],
        ]);
    }

    public function status(string $default = 'all'): string
    {
        $validated = $this->validated();
        $status = isset($validated['status']) ? (string) $validated['status'] : $default;
        return in_array($status, ['all', 'pending', 'approved', 'rejected', 'spam', 'karantine'], true)
            ? $status
            : $default;
    }
}
