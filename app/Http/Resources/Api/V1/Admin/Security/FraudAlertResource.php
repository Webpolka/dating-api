<?php

namespace App\Http\Resources\Api\V1\Admin\Security;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Антифрод-алерт (сигнал от MessageObserver, StopWord детектора, и т.д.).
 *
 * Заменяет FraudAlertController::formatAlert().
 *
 * Computed-поля (строго 1-в-1 из старого кода):
 *  - trigger_type / trigger_label / trigger_color — из FraudTriggerType enum.
 *    colorClass() — Tailwind-класс для бейджа.
 *  - severity / severity_label / severity_variant — из FraudAlertSeverity enum
 *    + severity_badge accessor (variant+label для shadcn).
 *  - status / status_label / status_variant — из FraudAlertStatus enum
 *    + status_badge accessor.
 *  - is_open — bool, $alert->isOpen() (computed на модели).
 *  - meta — JSONB с контекстом алерта (message_id, word, category, и т.д.).
 *
 * user — INLINE (не AdminUserBadgeResource), с fallback'ами ?? false.
 * Без withRole — фрод-алерты только на обычных юзерах (staff исключён в query).
 *
 * admin — короткий {id, name} (кто закрыл алерт). Через whenLoaded.
 */
class FraudAlertResource extends JsonResource
{
    public function toArray($request): array
    {
        $user = $this->user;

        return [
            'id'              => $this->id,
            'trigger_type'    => $this->trigger_type->value,
            'trigger_label'   => $this->trigger_label,
            'trigger_color'   => $this->trigger_type->colorClass(),
            'severity'        => $this->severity->value,
            'severity_label'  => $this->severity_badge['label'],
            'severity_variant'=> $this->severity_badge['variant'],
            'status'          => $this->status->value,
            'status_label'    => $this->status_badge['label'],
            'status_variant'  => $this->status_badge['variant'],
            'meta'            => $this->meta,
            'created_at'      => $this->created_at?->toIso8601String(),
            'is_open'         => $this->isOpen(),
            // user — inline с fallback'ами (как в старом formatAlert).
            'user' => $user ? [
                'id'                 => $user->id,
                'name'               => $user->name,
                'email'              => $user->email,
                'avatar_url'         => $user->avatar_url,
                'is_online'          => $user->is_online,
                'status'             => $user->status,
                'deleted_at'         => $user->deleted_at?->toIso8601String(),
                'has_active_premium' => $user->has_active_premium ?? false,
                'has_active_vip'     => $user->has_active_vip ?? false,
                'is_verified'        => $user->is_verified ?? false,
            ] : null,
            // admin — короткий {id, name} (кто закрыл алерт).
            'admin' => $this->whenLoaded('admin', fn () => $this->admin ? [
                'id'   => $this->admin->id,
                'name' => $this->admin->name,
            ] : null),
        ];
    }
}
