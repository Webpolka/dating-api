<?php

namespace App\Http\Requests\Api\V1\Admin\System;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BlogPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $postId = $this->route('post')?->id;

        return [
            'title'          => ['required', 'string', 'max:255'],
            'slug'           => ['required', 'string', 'regex:/^[a-z0-9-]+$/', Rule::unique('blog_posts', 'slug')->ignore($postId)],
            'excerpt'        => ['nullable', 'string', 'max:500'],
            'body'           => ['nullable', 'string'],
            'category_id'    => ['nullable', 'integer', 'exists:blog_categories,id'],
            'status'         => ['required', 'string', 'in:draft,published,archived'],
            'is_featured'    => ['boolean'],
            'cover_media_id' => ['nullable', 'integer', 'exists:media,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required'      => 'Заголовок обязателен для заполнения',
            'title.max'           => 'Заголовок слишком длинный (макс. 255 символов)',
            'slug.required'       => 'Slug обязателен для заполнения',
            'slug.regex'          => 'Slug может содержать только строчные буквы, цифры и дефисы',
            'slug.unique'         => 'Пост с таким slug уже существует — придумайте другой',
            'excerpt.max'         => 'Краткое описание слишком длинное (макс. 500 символов)',
            'status.required'     => 'Необходимо выбрать статус поста',
            'status.in'           => 'Недопустимый статус поста',
            'category_id.exists'  => 'Выбранная рубрика не существует',
            'cover_media_id.exists' => 'Выбранное изображение не существует в хранилище',
        ];
    }
    
}
