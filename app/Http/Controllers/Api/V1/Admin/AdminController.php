<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Базовый контроллер админ-панели (API Admin V1).
 *
 * Все контроллеры App\Http\Controllers\Admin\V1\* наследуются от него.
 * Содержит общие хелперы ответов админки. Бизнес-логику сюда НЕ складываем —
 * для этого есть Actions (App\Actions\Admin\*) и Services (App\Services\Admin\*).
 */
abstract class AdminController extends Controller
{
    /**
     * Стандартизированный 404 для сущностей админки.
     *
     * Формат: { "message": "<Entity> не найдена" } — тот же, что раньше
     * дублировался приватным методом в PhotoComment/DiaryComment контроллерах.
     */
    protected function notFound(string $entity = 'Запись'): JsonResponse
    {
        return response()->json(['message' => "{$entity} не найдена"], 404);
    }
}
