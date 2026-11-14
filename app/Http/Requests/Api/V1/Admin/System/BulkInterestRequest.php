<?php

namespace App\Http\Requests\Api\V1\Admin\System;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Валидация массовых действий над интересами
 * (POST /api/admin/interests/bulk).
 *
 * Действия:
 *   - delete        — удалить выбранные интересы навсегда (каскад: user_interests).
 *   - set_category  — переместить выбранные интересы в указанную категорию
 *                     (category_id обязателен при этом действии; null допустим —
 *                     означает "без категории").
 *
 * Удаление — деструктивное: счётчики users_count у юзеров не пострадают
 * (юзерская связь user_interests уйдёт каскадом по FK onDelete cascade),
 * но сам интерес пропадёт из всех профилей. Поэтому delete не имеет "черновиков".
 */
class BulkInterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $actions = ['delete', 'set_category'];

        return [
            'ids'         => ['required', 'array', 'min:1', 'max:500'],
            'ids.*'       => ['integer', 'exists:interests,id'],
            'action'      => ['required', 'string', Rule::in($actions)],
            'category_id' => ['nullable', 'integer', 'exists:interest_categories,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required'   => 'Выберите хотя бы один интерес',
            'ids.min'        => 'Выберите хотя бы один интерес',
            'ids.max'        => 'Можно обработать не более 500 интересов за раз',
            'ids.*.exists'   => 'Один из выбранных интересов не существует',
            'action.in'      => 'Недопустимое массовое действие',
            'category_id.exists' => 'Выбранная категория не существует',
        ];
    }

    /**
     * При action=set_category поле category_id может быть null
     * (это значит "убрать из категории"), но если оно передано как число —
     * категория должна существовать (проверено выше через exists).
     * Дополнительная валидация выполнена в контроллере (хоть nullable —
     * для set_category мы допускаем null осознанно).
     */
}
