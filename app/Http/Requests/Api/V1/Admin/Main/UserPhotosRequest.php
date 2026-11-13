<?php

namespace App\Http\Requests\Api\V1\Admin\Main;

/**
 * Параметры для таба «Фото» пользователя.
 *
 * GET /api/admin/users/{user}/photos?status=pending&page=2
 *
 * Один `page` (как в bans), в отличие от reports/blocks с двумя колонками:
 * фото — единый список, фильтруемый по `status`.
 *
 * `status` по умолчанию 'pending' — самое важное для модератора
 * (тот же дефолт, что в глобальной очереди GET /admin/photos).
 *
 * Варианты status:
 *   - pending    — ожидают модерации (status='pending', deleted_at IS NULL)
 *   - approved   — одобренные    (status='approved', deleted_at IS NULL)
 *   - rejected   — отклонённые   (status='rejected', deleted_at IS NULL)
 *   - quarantine — карантин      (deleted_at IS NOT NULL, любой status)
 *
 * Карантин ≠ status='rejected'. Карантин = soft-deleted (deleted_at IS NOT NULL).
 * См. PhotoController::getCounts() — та же логика подсчёта.
 */
class UserPhotosRequest extends UserListRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'status' => ['nullable', 'in:pending,approved,rejected,quarantine'],
        ]);
    }

    /**
     * Текущий статус-фильтр с дефолтом и защитой от подделки.
     */
    public function status(string $default = 'pending'): string
    {
        $validated = $this->validated();
        $status = isset($validated['status']) ? (string) $validated['status'] : $default;
        return in_array($status, ['pending', 'approved', 'rejected', 'quarantine'], true)
            ? $status
            : $default;
    }
}
