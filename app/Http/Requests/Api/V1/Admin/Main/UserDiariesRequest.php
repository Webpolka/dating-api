<?php

namespace App\Http\Requests\Admin\V1\Users;

/**
 * Параметры для таба «Дневники» пользователя.
 *
 * GET /api/admin/users/{user}/diaries?status=all&trashed=without&page=2
 *
 * Дневники, НАПИСАННЫЕ пользователем (author = user).
 *
 * status — зеркалит DiaryStatus enum:
 *   all | draft | pending | published | rejected
 *
 * trashed — фильтр мягко-удалённых (та же семантика, что в
 * глобальном списке DiaryController::index()):
 *   without (дефолт) | with | only
 */
class UserDiariesRequest extends UserListRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'status'  => ['nullable', 'in:all,draft,pending,published,rejected'],
            'trashed' => ['nullable', 'in:with,only,without'],
        ]);
    }

    public function status(string $default = 'all'): string
    {
        $validated = $this->validated();
        $status = isset($validated['status']) ? (string) $validated['status'] : $default;
        return in_array($status, ['all', 'draft', 'pending', 'published', 'rejected'], true)
            ? $status
            : $default;
    }

    public function trashed(string $default = 'without'): string
    {
        $validated = $this->validated();
        $trashed = isset($validated['trashed']) ? (string) $validated['trashed'] : $default;
        return in_array($trashed, ['with', 'only', 'without'], true)
            ? $trashed
            : $default;
    }
}
