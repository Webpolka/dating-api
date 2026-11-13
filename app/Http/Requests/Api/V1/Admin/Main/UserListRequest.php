<?php

namespace App\Http\Requests\Admin\V1\Users;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация параметров пагинации/фильтрации для списков per-user
 * (reports, blocks, ban-history, auth-logs, verifications, и т.д.).
 *
 * Поля названы осознанно: `page` и `per_page` соответствуют
 * Laravel-конвенции пагинатора и фронта (TanStack Query).
 *
 * Несколько страниц в одном запросе (как в reports: made + received)
 * решаются наследованием — см. UserReportsRequest ниже.
 */
class UserListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * Дефолты пагинатора — единое место для всех табов.
     * Разные табы могут переопределятьperPage() в наследниках.
     */
    public function perPage(int $default = 20): int
    {
        $validated = $this->validated();
        return isset($validated['per_page']) ? (int) $validated['per_page'] : $default;
    }

    public function page(int $default = 1): int
    {
        $validated = $this->validated();
        return isset($validated['page']) ? (int) $validated['page'] : $default;
    }
}
