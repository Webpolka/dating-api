<?php

namespace App\Http\Resources\Api\V1\Admin\Communication;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Сообщение чата для детального просмотра (ChatController::show()).
 *
 * Заменяет inline-маппинг в ChatController::show() — там было ~15 строк
 * с ->map(fn($m) => [...]).
 *
 * Особенности:
 *
 *  1. sender — null-safe: если sender_id=null (system message), sender=null.
 *     Через AdminUserBadgeResource::nullable() — он умеет принимать null.
 *
 *  2. system_action — поле только для system-сообщений ('lock'|'unlock'|null).
 *     Берётся через accessor Message::system_action (см. патч к Message.php).
 *     Раньше парсинг body был приватным методом detectSystemAction() в
 *     ChatController — теперь в модели, переиспользуемо и тестируемо.
 *     В будущем, если system_action станет колонкой в БД — accessor просто
 *     заменится на return $this->system_action;, Resource не изменится.
 *
 *  3. gift — null-safe через whenLoaded: если связь gift не загружена
 *     или gift_id=null, поле будет null. Не делает лишний SQL-запрос.
 *
 *  4. attachment_url — accessor на Message, возвращает полный URL медиа
 *     (если есть) или null.
 */
class ChatMessageResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'type'           => $this->type,
            'body'           => $this->body,
            'attachment_url' => $this->attachment_url,
            'status'         => $this->status,
            'created_at'     => $this->created_at?->toIso8601String(),
            'sender'         => $this->whenLoaded(
                'sender',
                fn () => AdminUserBadgeResource::nullable($this->sender),
                null,
            ),
            // system_action — accessor Message::getSystemActionAttribute().
            // Для не-system сообщений всегда null.
            'system_action'  => $this->system_action,
            'gift' => $this->whenLoaded('gift', fn () => $this->gift ? [
                'id'        => $this->gift->id,
                'name'      => $this->gift->name,
                'image_url' => $this->gift->image_url,
            ] : null),
        ];
    }
}
