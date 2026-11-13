<?php

namespace App\Http\Requests\Api\V1\Admin\System;

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