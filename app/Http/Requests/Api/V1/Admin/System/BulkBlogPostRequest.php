<?php

namespace App\Http\Requests\Admin\V1\Content;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация массовых действий над постами блога
 * (POST /api/admin/blog/posts/bulk).
 *
 * Поля: ids (массив id существующих постов, макс. 100),
 * action (publish / draft / archive / delete).
 */
class BulkBlogPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к роуту уже ограничен middleware admin.role.
        return true;
    }

    public function rules(): array
    {
        return [
            'ids'     => 'required|array|max:100',
            'ids.*'   => 'integer|exists:blog_posts,id',
            'action'  => 'required|in:publish,draft,archive,delete',
        ];
    }
}
