<?php

namespace App\Http\Resources\Api\V1\Admin\Communication;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Карточка support-чата (тикета поддержки) для списков и детального просмотра.
 *
 * Один ресурс для двух endpoint'ов:
 *  - SupportChatController::index()  — список тикетов с превью последнего сообщения
 *  - SupportChatController::show()   — детали тикета (без last_message, т.к.
 *                                      сообщения грузятся отдельно с пагинацией)
 *
 * Механика: last_message попадает в ответ ТОЛЬКО когда связь latestMessage
 * загружена (через ->with('latestMessage') в query). В show() мы её НЕ
 * подгружаем — last_message будет отсутствовать, что фронт игнорирует.
 *
 * Заменяет:
 *   - SupportChatController::formatChatListItem() (~25 строк)
 *
 * Особенности:
 *  - partner — НЕ-staff participant (юзер). Фильтруем по !isStaff() вместо
 *    firstWhere('user_id', '!=', $adminId), потому что в старых чатах (до
 *    рефактора «одно лицо для staff») participants могло быть [admin, user]
 *    и first() мог вернуть admin. Фильтр по isStaff() даёт одинаковый
 *    правильный результат в обоих случаях.
 *  - partner всегда с withIsStaff=true — фронт различает юзера от staff.
 *  - staff_last_read_by — тоже null-safe (через whenLoaded lastReadBy).
 */
class SupportChatResource extends JsonResource
{
    public function toArray($request): array
    {
        // Partner = НЕ-staff participant. Берём именно юзера, независимо
        // от порядка insertion в participants.
        $partner = $this->participants->first(fn ($p) => $p->user && ! $p->user->isStaff())?->user;

        return [
            'id'                    => $this->id,
            'is_locked'             => (bool) $this->is_locked,
            'is_archived'           => (bool) $this->is_archived,
            'last_message_at'       => $this->last_message_at?->toIso8601String(),

            // Глобальное staff-state (модель «одно лицо для staff» —
            // если один staff прочитал, все видят «прочитано»).
            'staff_unread_count'    => (int) $this->staff_unread_count,
            'staff_last_read_at'    => $this->staff_last_read_at?->toIso8601String(),
            'staff_last_read_by'    => $this->whenLoaded('lastReadBy', fn () => [
                'id'   => $this->lastReadBy->id,
                'name' => $this->lastReadBy->name,
            ]),

            // Юзер тикета — always withIsStaff=true, чтобы фронт в саппорт-чатах
            // различал юзера от staff-участников (если такие в старых чатах есть).
            'partner'               => AdminUserBadgeResource::nullable($partner, withIsStaff: true),

            // Превью последнего сообщения — только когда связь загружена.
            // В show() мы её не грузим (сообщения идут отдельным списком).
            'last_message'          => $this->whenLoaded('latestMessage', fn () => [
                'id'         => $this->latestMessage->id,
                'type'       => $this->latestMessage->type,
                'body'       => $this->latestMessage->body,
                'created_at' => $this->latestMessage->created_at?->toIso8601String(),
            ]),
        ];
    }
}
