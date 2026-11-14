<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Базовый контроллер админ-панели (API Admin V1).
 *
 * Все контроллеры App\Http\Controllers\Api\V1\Admin\* наследуются от него.
 * Содержит общие хелперы ответов админки. Бизнес-логику сюда НЕ складываем —
 * для этого есть Actions (App\Actions\V1\Admin\*) и Services (App\Services\V1\*).
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

    /**
     * Стандартизированный ответ с пагинацией.
     *
     * Заменяет копипасту `{ data, meta: {total, current_page, last_page, per_page, from, to} }`,
     * которая была в ~15 методах (UserController, ChatController, SupportChatController,
     * SupportTemplateController, StopWordController, и т.д.).
     *
     * Использование:
     *   return $this->paginated($chats, ChatListItemResource::class);
     *   return $this->paginated($chats, ChatListItemResource::class, ['counts' => $stats]);
     *
     * $resourceClass — класс Resource с методом ::collection(). Если передать null,
     * вернёт коллекцию как есть (полезно для простых списков из 2-3 полей).
     */
    protected function paginated(        
        LengthAwarePaginator $paginator,
        ?string $resourceClass = null,
        array $additional = [],
    ): JsonResponse {
        /** @var \Illuminate\Pagination\LengthAwarePaginator $paginator */
        $items = $paginator->getCollection();

        $data = $resourceClass !== null
            ? $resourceClass::collection($items)->resolve()
            : $items->values();

        return response()->json(array_merge([
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
            ],
        ], $additional));
    }

    /**
     * Короткий ответ-сообщение для mutations (toggle, archive, send, и т.п.).
     *
     *   return $this->message('Чат заблокирован');
     *   return $this->message('Уже в архиве', 422);
     *   return $this->message('Отправлено', 200, ['chat_id' => $chat->id]);
     */
    protected function message(string $message, int $status = 200, array $extra = []): JsonResponse
    {
        return response()->json(array_merge(['message' => $message], $extra), $status);
    }
}
