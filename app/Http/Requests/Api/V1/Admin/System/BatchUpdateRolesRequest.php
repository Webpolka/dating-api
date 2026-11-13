<?php

namespace App\Http\Requests\Admin\V1\System;

use Illuminate\Foundation\Http\FormRequest;

class BatchUpdateRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'roles' => ['required', 'array'],
            'roles.*' => ['required', 'string', 'in:admin,moderator,support'],
        ];
    }
}