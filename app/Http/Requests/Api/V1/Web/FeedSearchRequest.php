<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация фильтров поиска анкет.
 *
 * Все входные данные валидируются здесь — контроллер получает уже чистые данные.
 *
 * ВАЖНО про boolean поля:
 *   Фронт через URLSearchParams отправляет boolean как строку "true"/"false".
 *   Laravel 11 правило `boolean` иногда капризничает со строками.
 *   Поэтому в prepareForValidation() конвертируем их в настоящие boolean.
 */
class FeedSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // поиск доступен и гостям, и юзерам
    }

    public function rules(): array
    {
        return [
            // Пагинация
            'page'      => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'per_page'  => ['sometimes', 'integer', 'min:1', 'max:50'],

            // Базовые фильтры
            'gender'         => ['sometimes', 'string', 'in:any,male,female'],
            'age_from'       => ['sometimes', 'integer', 'min:18', 'max:99'],
            'age_to'         => ['sometimes', 'integer', 'min:18', 'max:99'],
            'country_id'     => ['sometimes', 'integer', 'min:1'],
            'city_id'        => ['sometimes', 'integer', 'min:1'],
            'dating_goal'    => ['sometimes', 'string', 'in:any,friends,romantic,family,casual,travel'],
            'activity'       => ['sometimes', 'string', 'in:online,recently'],
            'is_new'         => ['sometimes', 'nullable', 'boolean'],
            'verified_only'  => ['sometimes', 'nullable', 'boolean'],

            // Геолокация
            'lat'       => ['sometimes', 'numeric', 'between:-90,90'],
            'lng'       => ['sometimes', 'numeric', 'between:-180,180'],
            'radius_km' => ['sometimes', 'integer', 'min:1', 'max:1000'],

            // Сортировка
            'sort' => ['sometimes', 'string', 'in:last_seen,distance,new'],

            // Скалярные массивы (whereIn)
            'body_type'              => ['sometimes', 'array'],
            'body_type.*'            => ['integer', 'min:0'],
            'eye_color'              => ['sometimes', 'array'],
            'eye_color.*'            => ['integer', 'min:0'],
            'hair_color'             => ['sometimes', 'array'],
            'hair_color.*'           => ['integer', 'min:0'],
            'relationship_status'    => ['sometimes', 'array'],
            'relationship_status.*'  => ['integer', 'min:0'],
            'children_status'        => ['sometimes', 'array'],
            'children_status.*'      => ['integer', 'min:0'],
            'pets'                   => ['sometimes', 'array'],
            'pets.*'                 => ['integer', 'min:0'],
            'housing'                => ['sometimes', 'array'],
            'housing.*'              => ['integer', 'min:0'],
            'has_car'                => ['sometimes', 'array'],
            'has_car.*'              => ['integer', 'min:0'],
            'education_level'        => ['sometimes', 'array'],
            'education_level.*'      => ['integer', 'min:0'],
            'income'                 => ['sometimes', 'array'],
            'income.*'               => ['integer', 'min:0'],
            'smoking'                => ['sometimes', 'array'],
            'smoking.*'              => ['integer', 'min:0'],
            'alcohol'                => ['sometimes', 'array'],
            'alcohol.*'              => ['integer', 'min:0'],
            'zodiac_sign'           => ['sometimes', 'array'],
            'zodiac_sign.*'          => ['integer', 'min:0'],

            // JSONB массивы
            'languages'             => ['sometimes', 'array'],
            'languages.*'           => ['integer', 'min:0'],
            'sports'                => ['sometimes', 'array'],
            'sports.*'              => ['integer', 'min:0'],
            'body_decorations'      => ['sometimes', 'array'],
            'body_decorations.*'    => ['integer', 'min:0'],

            // Интересы (массив ID)
            'interests'        => ['sometimes', 'array'],
            'interests.*'      => ['integer', 'min:1'],

            // Диапазоны (height, weight)
            'height_from' => ['sometimes', 'integer', 'min:0', 'max:300'],
            'height_to'   => ['sometimes', 'integer', 'min:0', 'max:300'],
            'weight_from' => ['sometimes', 'integer', 'min:0', 'max:500'],
            'weight_to'   => ['sometimes', 'integer', 'min:0', 'max:500'],
        ];
    }

    /**
     * Нормализация входных данных ПЕРЕД валидацией.
     *
     * Проблема: фронт через URLSearchParams отправляет:
     *   - boolean true/false как строки "true"/"false"
     *   - числовые значения иногда как строки
     *   - пустые массивы как []
     *
     * Решение: конвертируем в правильные типы до валидации.
     */
    protected function prepareForValidation(): void
    {
        // Boolean поля — конвертируем из строки в настоящий boolean.
        // filter_var с FILTER_VALIDATE_BOOLEAN + FILTER_NULL_ON_FAILURE:
        //   "true"/"1"/true/1/"on"/"yes" → true
        //   "false"/"0"/false/0/"off"/"no" → false
        //   всё остальное (пустая строка, null, "abc") → null
        $booleanFields = ['is_new', 'verified_only'];
        foreach ($booleanFields as $field) {
            if ($this->has($field)) {
                $value = $this->input($field);
                $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                $this->merge([$field => $normalized]);
            }
        }

        // Числовые поля — конвертируем строки в int.
        // Это защитит от случаев когда фронт отправляет "25" вместо 25.
        $integerFields = [
            'page', 'per_page',
            'age_from', 'age_to',
            'country_id', 'city_id',
            'radius_km',
            'height_from', 'height_to',
            'weight_from', 'weight_to',
        ];
        foreach ($integerFields as $field) {
            if ($this->has($field) && $this->input($field) !== null && $this->input($field) !== '') {
                $this->merge([$field => (int) $this->input($field)]);
            }
        }

        // Float поля (lat/lng)
        foreach (['lat', 'lng'] as $field) {
            if ($this->has($field) && $this->input($field) !== null && $this->input($field) !== '') {
                $this->merge([$field => (float) $this->input($field)]);
            }
        }

        // Массивы — если фронт отправил как строку (через запятую), парсим.
        // URLSearchParams с `params.append('interests[]', val)` создаёт массив.
        // Но иногда фронт может отправить `interests=1,2,3` — тогда это строка.
        $arrayFields = [
            'body_type', 'eye_color', 'hair_color', 'relationship_status',
            'children_status', 'pets', 'housing', 'has_car',
            'education_level', 'income', 'smoking', 'alcohol', 'zodiac_sign',
            'languages', 'sports', 'body_decorations',
            'interests',
        ];
        foreach ($arrayFields as $field) {
            $value = $this->input($field);
            // Если это строка с запятыми — парсим в массив
            if (is_string($value) && str_contains($value, ',')) {
                $parts = explode(',', $value);
                $parts = array_filter(array_map('trim', $parts));
                $this->merge([$field => $parts]);
            }
            // Если это скаляр (один ID без массива) — оборачиваем в массив
            if (is_numeric($value)) {
                $this->merge([$field => [$value]]);
            }
            // Если это пустая строка — убираем совсем
            if ($value === '' || $value === null) {
                $this->offsetUnset($field);
            }
        }
    }

    /**
     * Возвращает нормализованные фильтры (после валидации).
     */
    public function getFilters(): array
    {
        $validated = $this->validated();

        // Убираем null и пустые массивы
        return array_filter($validated, function ($value) {
            if (is_array($value)) {
                return !empty($value);
            }
            return $value !== null && $value !== '';
        });
    }
}