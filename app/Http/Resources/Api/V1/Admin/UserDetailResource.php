<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Resources\Json\JsonResource;

class UserDetailResource extends JsonResource
{
    public function toArray($request): array
    {
        $profile = $this->profile;
        $prefs = $this->preferences;

        return [
            // === Базовые поля ===
            'id'                       => $this->id,
            'name'                     => $this->name,
            'email'                    => $this->email,
            'phone'                    => $this->phone,
            'avatar_url'               => $this->avatar_url,
            'is_online'                => $this->is_online,
            'status'                   => $this->status,
            'deleted_at'               => $this->deleted_at?->toIso8601String(),

            // === Верификация / подписки ===
            'is_verified'              => $this->is_verified,
            'has_completed_onboarding' => $this->has_completed_onboarding,
            'email_verified_at'        => $this->email_verified_at?->toIso8601String(),
            'has_active_premium'       => $this->has_active_premium,
            'premium_expires_at'       => $this->premium_expires_at?->toIso8601String(),
            'has_active_vip'           => $this->has_active_vip,
            'vip_expires_at'           => $this->vip_expires_at?->toIso8601String(),

            // === Системные ===
            'last_seen'                => $this->last_seen?->toIso8601String(),
            'last_login_at'            => $this->last_login_at?->toIso8601String(),
            'last_login_ip'            => $this->last_login_ip,
            'created_at'               => $this->created_at?->toIso8601String(),
            'banned_until'             => $this->banned_until?->toIso8601String(),
            'ban_reason'               => $this->ban_reason,

            // === Профиль ===
            'profile' => $profile ? [
                'gender'              => $profile->gender,
                'age'                 => $profile->age,
                'birth_date'          => $profile->birth_date?->toIso8601String(),
                'headline'            => $profile->headline,
                'bio'                 => $profile->bio,
                'looking_for'         => $profile->looking_for,
                'dating_goals'        => $profile->dating_goals,
                'zodiac_sign'         => $profile->zodiac_sign,
                'body_type'           => $profile->body_type,
                'eye_color'           => $profile->eye_color,
                'hair_color'          => $profile->hair_color,
                'height'              => $profile->height,
                'weight'              => $profile->weight,
                'body_decorations'    => $profile->body_decorations,
                'relationship_status' => $profile->relationship_status,
                'children_status'     => $profile->children_status,
                'pets'                => $profile->pets,
                'housing'             => $profile->housing,
                'has_car'             => $profile->has_car,
                'smoking'             => $profile->smoking,
                'alcohol'             => $profile->alcohol,
                'self_portrait'       => $profile->self_portrait,
                'education_level'     => $profile->education_level,
                'income'              => $profile->income,
                'institution'         => $profile->institution,
                'institution_year'    => $profile->institution_year,
                'activity'            => $profile->activity,
                'position'            => $profile->position,
                'sports'              => $profile->sports,
                'languages'           => $profile->languages,

                // Interests — BelongsToMany, мапим в {id, name, slug}
                'interests' => $profile->relationLoaded('interests')
                    ? $profile->interests->map(fn($i) => [
                        'id'   => $i->id,
                        'name' => $i->name,
                        'slug' => $i->slug,
                    ])->values()
                    : [],

                // Гео
                'country' => $profile->country?->name,
                'state'   => $profile->city?->state?->name,
                'city'    => $profile->city?->name,
            ] : null,

            // === Preferences ===
            'preferences' => $prefs ? [
                'preferred_gender'       => $prefs->preferred_gender,
                'preferred_age_min'      => $prefs->preferred_age_min,
                'preferred_age_max'      => $prefs->preferred_age_max,
                'preferred_distance_km'  => $prefs->preferred_distance_km,
                'search_filters'         => $prefs->search_filters,
                'visibility_gender'      => $prefs->visibility_gender,
                'visibility_age_min'     => $prefs->visibility_age_min,
                'visibility_age_max'     => $prefs->visibility_age_max,
                'chat_filter_enabled'    => $prefs->chat_filter_enabled,
                'chat_filter_settings'   => $prefs->chat_filter_settings,
                'is_invisible'           => $prefs->is_invisible,
                'hide_from_search'       => $prefs->hide_from_search,
                'hide_intimate'          => $prefs->hide_intimate,
                'disable_photo_comments' => $prefs->disable_photo_comments,
            ] : null,
        ];
    }
}
