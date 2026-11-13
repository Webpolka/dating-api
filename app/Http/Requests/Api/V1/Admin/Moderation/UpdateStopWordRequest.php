<?php

namespace App\Http\Requests\Admin\V1\Moderation;

use App\Enums\StopWordAction;
use App\Enums\StopWordCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Редактирование одного стоп-слова (PUT /api/admin/stop-words/{id}).
 *
 * Позволяет менять word/category/action/replacement/is_active без
 * пересоздания записи. Поле word имеет UNIQUE-индекс в БД — коллизию
 * ловит контроллер и возвращает 422.
 */
class UpdateStopWordRequest extends FormRequest
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
            'word'        => 'required|string|max:255',
            'category'    => ['required', Rule::enum(StopWordCategory::class)],
            'action'      => ['required', Rule::enum(StopWordAction::class)],
            'replacement' => 'nullable|string|max:10',
            'is_active'   => 'boolean',
        ];
    }
}
