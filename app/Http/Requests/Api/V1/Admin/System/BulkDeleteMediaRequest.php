<?php

namespace App\Http\Requests\Admin\V1\System;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация массового удаления файлов из медиатеки
 * (POST /api/admin/media/bulk-delete).
 *
 * Поля: ids — массив id существующих медиа, макс. 100.
 */
class BulkDeleteMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role.
        return true;
    }

    public function rules(): array
    {
        return [
            'ids'   => 'required|array|max:100',
            'ids.*' => 'integer|exists:media,id',
        ];
    }
}
