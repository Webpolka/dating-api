<?php

namespace App\Http\Requests\Admin\V1\Users;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация фильтров списка пользователей в админке
 * (GET /api/admin/users).
 *
 * Поля:
 *  - q        — поиск по имени/email/телефону (ilike на pgsql);
 *  - status   — статус аккаунта;
 *  - gender   — пол;
 *  - sub      — фильтр по подписке (VIP / Premium / обе / без);
 *  - sort     — направление сортировки по created_at;
 *  - verified — верификация;
 *  - per_page — пагинация (макс. 100, как везде в админке).
 */
class UserIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role —
        // здесь дополнительно проверять нечего.
        return true;
    }

    public function rules(): array
    {
        return [
            'q'        => ['nullable', 'string', 'max:255'],
            'status'   => ['nullable', 'in:active,banned,shadowbanned,deactivated'],
            'gender'   => ['nullable', 'in:male,female'],
            'sub'      => ['nullable', 'in:vip_only,premium_only,both,none'],
            'sort'     => ['nullable', 'in:asc,desc'],
            'verified' => ['nullable', 'in:yes,no'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
