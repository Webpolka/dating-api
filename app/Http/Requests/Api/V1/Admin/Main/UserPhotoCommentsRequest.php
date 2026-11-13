<?php

namespace App\Http\Requests\Admin\V1\Users;

/**
 * Параметры для таба «Комм. фото» пользователя.
 *
 * GET /api/admin/users/{user}/photo-comments?status=pending&page=2
 *
 * Комментарии, НАПИСАННЫЕ пользователем (author = user).
 *
 * Варианты status:
 *   - all       — все (включая карантин)
 *   - pending   — ожидают модерации (status='pending', deleted_at IS NULL)
 *   - approved  — одобренные
 *   - rejected  — отклонённые
 *   - spam      — помеченные как спам
 *   - karantine — карантин (soft-deleted, deleted_at IS NOT NULL)
 *
 * «Карантин» ≠ status='rejected' — та же семантика, что в
 * PhotoCommentController::getCounts() и табе «Фото».
 */
class UserPhotoCommentsRequest extends UserListRequest
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
