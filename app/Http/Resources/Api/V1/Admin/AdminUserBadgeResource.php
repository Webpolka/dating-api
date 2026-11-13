<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\Models\User;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Стандартизированный "бейдж" юзера для карточек/списков в админке.
 *
 * Заменяет 8 разошедшихся приватных formatUser()/formatUserShort() хелперов,
 * которые копипастились по контроллерам (Dating, Report, Diary, DiaryComment,
 * PhotoComment, Chat, Gift, SupportChat) и постепенно дрейфовали.
 *
 * Флаги (конструктор):
 *  - withRole   → добавить 'role' после email (нужно в Report/Diary/чатах);
 *  - withIsStaff → добавить 'is_staff' в конец (нужно в саппорт-чатах,
 *                  чтобы отличать юзера от staff-участника).
 *
 * Порядок полей и значения — конгруэнтны исходным хелперам, чтобы
 * JSON-ответы админ-фронта не изменились.
 *
 * Использование:
 *   (new AdminUserBadgeResource($u, withRole: true))->resolve()
 *   AdminUserBadgeResource::nullable($partner, withIsStaff: true)
 */
class AdminUserBadgeResource extends JsonResource
{
    public function __construct(
        $resource,
        private readonly bool $withRole = false,
        private readonly bool $withIsStaff = false,
    ) {
        parent::__construct($resource);
    }

    /**
     * null-safe вариант для опциональных связей (partner может отсутствовать).
     */
    public static function nullable(?User $user, bool $withRole = false, bool $withIsStaff = false): ?array
    {
        return $user
            ? (new static($user, $withRole, $withIsStaff))->resolve()
            : null;
    }

    public function toArray($request): array
    {
        $data = [
            'id'                 => $this->id,
            'name'               => $this->name,
            'email'              => $this->email,
        ];

        if ($this->withRole) {
            $data['role'] = $this->role;
        }

        $data['avatar_url']         = $this->avatar_url;
        $data['is_online']          = $this->is_online;
        $data['status']             = $this->status;
        $data['deleted_at']         = $this->deleted_at?->toIso8601String();
        $data['has_active_premium'] = $this->has_active_premium ?? false;
        $data['has_active_vip']     = $this->has_active_vip ?? false;
        $data['is_verified']        = $this->is_verified ?? false;

        if ($this->withIsStaff) {
            $data['is_staff'] = $this->isStaff();
        }

        return $data;
    }
}
