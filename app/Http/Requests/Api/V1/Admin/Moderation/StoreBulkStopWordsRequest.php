<?php

namespace App\Http\Requests\Api\V1\Admin\Moderation;

use App\Enums\V1\StopWordAction;
use App\Enums\V1\StopWordCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Массовое создание стоп-слов (POST /api/admin/stop-words).
 *
 * Поле words — строка со словами, разделёнными запятыми / переносами
 * строк / точками с запятой; контроллер сам разбивает её на отдельные
 * слова и дедуплицирует. category и action — значения enum-справочника.
 */
class StoreBulkStopWordsRequest extends FormRequest
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
            'words'    => 'required|string',
            'category' => ['required', Rule::enum(StopWordCategory::class)],
            'action'   => ['required', Rule::enum(StopWordAction::class)],
        ];
    }
}
