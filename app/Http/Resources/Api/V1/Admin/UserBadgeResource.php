<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

class UserBadgeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'email'              => $this->email,
            'avatar_url'         => $this->avatar_url,
            'is_online'          => $this->is_online,
            'status'             => $this->status,
            'deleted_at'         => $this->deleted_at?->toIso8601String(),
            'is_verified'        => $this->is_verified,
            'has_active_premium' => $this->has_active_premium,
            'has_active_vip'     => $this->has_active_vip,
            'last_seen'          => $this->last_seen?->toIso8601String(),
            'created_at'         => $this->created_at?->toIso8601String(),
        ];
    }
}
