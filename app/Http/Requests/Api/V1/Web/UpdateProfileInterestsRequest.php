<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileInterestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // роут под auth:sanctum
    }

    public function rules(): array
    {
        return [
            // Принимаем массив ID интересов
            'interests'        => ['required', 'array'],
            'interests.*'      => ['integer', 'min:1', 'exists:interests,id'],
            
            // Лимит количества интересов (опционально)
            // Можно убрать если не нужен — но полезно чтобы юзер не выбрал 100 штук
            // 'interests' => ['required', 'array', 'min:1', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'interests.required' => 'Выберите хотя бы один интерес.',
            'interests.array'    => 'Неверный формат данных.',
            'interests.min'      => 'Выберите хотя бы один интерес.',
            'interests.max'      => 'Можно выбрать не более :max интересов.',
            'interests.*.integer' => 'ID интереса должен быть числом.',
            'interests.*.exists'  => 'Один из интересов не существует.',
        ];
    }

    /**
     * Нормализуем входные данные перед валидацией.
     */
    protected function prepareForValidation(): void
    {
        // Если фронт прислал null — конвертируем в пустой массив
        if ($this->has('interests') && is_null($this->input('interests'))) {
            $this->merge(['interests' => []]);
        }
    }
}