<?php

namespace App\Http\Resources\Api\V1\Admin\Communication;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Карточка чата для списков и детального просмотра в админке.
 *
 * Один ресурс для двух endpoint'ов:
 *  - ChatController::index()  — список чатов с превью последнего сообщения
 *  - ChatController::show()   — детали чата (без last_message, т.к. сообщения
 *                               грузятся отдельно с пагинацией по курсору)
 *
 * Механика: last_message попадает в ответ ТОЛЬКО когда связь latestMessage
 * загружена (через ->with('latestMessage') в query). В show() мы её НЕ
 * подгружаем — last_message будет null, что фронт игнорирует.
 *
 * Заменяет:
 *   - ChatController::formatChatListItem() (~20 строк)
 *   - ChatController::formatChatDetail()   (~12 строк)
 *
 * participants: всегда два элемента [u1, u2] — даже если один из них null
 * (например, юзер удалён). Через AdminUserBadgeResource::nullable() — он
 * возвращает null-safe array или null.
 */
class ChatResource extends JsonResource
{
    public function toArray($request): array
    {
        $u1 = $this->participants->get(0)?->user;
        $u2 = $this->participants->get(1)?->user;

        return [
            'id'              => $this->id,
            'is_locked'       => (bool) $this->is_locked,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'participants'    => [
                AdminUserBadgeResource::nullable($u1),
                AdminUserBadgeResource::nullable($u2),
            ],
            // Превью последнего сообщения — только когда связь загружена.
            // В show() мы её не грузим (сообщения идут отдельным списком с пагинацией).
            'last_message' => $this->whenLoaded('latestMessage', fn () => [
                'id'         => $this->latestMessage->id,
                'type'       => $this->latestMessage->type,
                'body'       => $this->latestMessage->body,
                'created_at' => $this->latestMessage->created_at?->toIso8601String(),
            ]),
        ];
    }
}
