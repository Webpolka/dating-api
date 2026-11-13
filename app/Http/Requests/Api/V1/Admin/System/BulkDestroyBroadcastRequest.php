<?php

namespace App\Http\Requests\Api\V1\Admin\System;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация массового удаления рассылок
 * (POST /api/admin/broadcasts/bulk-delete).
 *
 * Поля: ids — массив id существующих рассылок, макс. 100.
 */
class BulkDestroyBroadcastRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role.
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => 'required|array|max:100',
            'ids.*' => 'integer|exists:broadcasts,id',
        ];
    }
}
