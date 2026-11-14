<?php

namespace App\Http\Resources\Api\V1\Admin\System;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Карточка сотрудника (staff) для списка /admin/staff.
 *
 * Заменяет inline transform в StaffController::index().
 *
 * Особенности:
 *  - is_founder — bool, computed: id в config('app.founders').
 *  - is_self — bool, computed: id === текущий admin (request()->user()->id).
 *    Передаётся через withCustomData или просто через request context.
 *  - created_at — дата РЕГИСТРАЦИИ юзера (не "дата назначения" — роль может меняться).
 *    Подпись в UI — "Дата регистрации".
 *
 * ВАЖНО: is_self зависит от request context, поэтому Resource вычисляет его через
 * request()->user()->id. Если Resource вызывается вне request context (например,
 * в tests) — будет null. Это OK для админки.
 */
class StaffResource extends JsonResource
{
    public function toArray($request): array
    {
        $founders = config('app.founders', []);
        $currentUserId = $request?->user()?->id;

        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'email'              => $this->email,
            'role'               => $this->role,
            // Отдаём ISO — фронт сам отформатирует через formatDateTime.
            // Это дата СОЗДАНИЯ юзера (registration), не "дата назначения".
            'created_at'         => $this->created_at?->toIso8601String(),
            'avatar_url'         => $this->avatar_url,
            'is_online'          => $this->is_online,
            'is_founder'         => in_array($this->id, $founders, true),
            'is_self'            => $this->id === $currentUserId,
            // Поля для UserBadge.
            'status'             => $this->status,
            'deleted_at'         => $this->deleted_at?->toIso8601String(),
            'has_active_premium' => $this->has_active_premium ?? false,
            'has_active_vip'     => $this->has_active_vip ?? false,
            'is_verified'        => $this->is_verified ?? false,
        ];
    }
} 