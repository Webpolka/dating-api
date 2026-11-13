<?php

namespace App\Http\Requests\Api\V1\Admin\System;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация загрузки файлов в медиатеку
 * (POST /api/admin/media).
 *
 * Поле files — массив изображений (макс. 20 шт.). Лимит размера
 * каждого файла берётся из config media.collections.{collection}
 * .max_file_size_kb (по умолчанию 5120 КБ).
 */
class UploadMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role.
        return true;
    }

    public function rules(): array
    {
        $collection = (string) $this->string('collection', 'default');
        $maxSize = config("media.collections.{$collection}.max_file_size_kb", 5120);

        return [
            'files'   => 'required|array|max:20',
            // B3: 'image' rule проверяет реальный mime через getimagesize()
            'files.*' => "file|image|mimes:jpg,jpeg,png,webp,gif|max:{$maxSize}",
        ];
    }
}
