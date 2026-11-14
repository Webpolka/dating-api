<?php

namespace App\Http\Resources\Api\V1\Admin\Security;

use App\Enums\V1\UserBlockReason;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Запись о блокировке (кто кого заблокировал) — для /block-signals/{userId}/blockers.
 *
 * Заменяет inline-маппинг в BlockSignalController::blockers().
 *
 * reason_label / reason_color — computed через UserBlockReason enum.
 *   Если reason невалидный (tryFrom вернёт null) — поля null.
 *
 * blocker — User который заблокировал, inline (без withRole).
 */
class BlockerResource extends JsonResource
{
    public function toArray($request): array
    {
        $reasonEnum = $this->reason ? UserBlockReason::tryFrom($this->reason) : null;
        $blocker    = $this->blocker;

        return [
            'id'            => $this->id,
            'reason'        => $this->reason,
            'reason_label'  => $reasonEnum?->label(),
            'reason_color'  => $reasonEnum?->color(),
            'created_at'    => $this->created_at?->toIso8601String(),
            'blocker' => $blocker ? [
                'id'                 => $blocker->id,
                'name'               => $blocker->name,
                'email'              => $blocker->email,
                'avatar_url'         => $blocker->avatar_url,
                'is_online'          => $blocker->is_online,
                'status'             => $blocker->status,
                'deleted_at'         => $blocker->deleted_at?->toIso8601String(),
                'has_active_premium' => $blocker->has_active_premium ?? false,
                'has_active_vip'     => $blocker->has_active_vip ?? false,
                'is_verified'        => $blocker->is_verified ?? false,
            ] : null,
        ];
    }
} 