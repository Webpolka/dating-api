<?php

namespace App\Models;

use App\Notifications\V1\VerifyEmail;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Cocur\Slugify\Slugify;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    // === КОНСТАНТЫ РОЛЕЙ И СТАТУСОВ ===
    public const ROLE_USER = 'user';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MODERATOR = 'moderator';
    public const ROLE_SUPPORT = 'support';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_BANNED = 'banned';
    public const STATUS_SHADOWBANNED = 'shadowbanned';
    public const STATUS_DEACTIVATED = 'deactivated';

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'slug',
        'role',
        'status',
        'ban_reason',
        'banned_until',
        'premium_expires_at',
        'vip_expires_at',
        'is_verified',
        'has_completed_onboarding',
        'last_seen',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_seen' => 'datetime',
            'banned_until' => 'datetime',
            'premium_expires_at' => 'datetime',
            'vip_expires_at' => 'datetime',
            'is_verified' => 'boolean',
            'has_completed_onboarding' => 'boolean',
        ];
    }

    protected $appends = [
        'is_online',
        'has_active_premium',
        'has_active_vip',
        'avatar_url',
        'locale',
        'theme',
        'push_enabled',
        'email_enabled',
        'email_settings',
    ];

    /**
     * Переопределяем стандартное уведомление верификации.
     * Наше — implements ShouldQueue, значит уйдёт в очередь Horizon.
     */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmail());
    }

    // ============================================
    // СВЯЗИ
    // ============================================
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    public function balance(): HasOne
    {
        return $this->hasOne(UserBalance::class);
    }

    public function albums(): HasMany
    {
        return $this->hasMany(Album::class);
    }

    public function defaultAlbum(): HasOne
    {
        return $this->hasOne(Album::class)->where('is_default', true);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PhotoComment::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(UserSubscription::class);
    }

    public function boosts(): HasMany
    {
        return $this->hasMany(UserBoost::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(UserFavorite::class, 'user_id');
    }

    public function favoritedBy(): HasMany
    {
        return $this->hasMany(UserFavorite::class, 'favorite_user_id');
    }

    public function chats(): BelongsToMany
    {
        return $this->belongsToMany(Chat::class, 'chat_participants')
            ->withPivot(['unread_count', 'last_read_at', 'is_hidden', 'is_muted', 'is_blocked'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function giftsSent(): HasMany
    {
        return $this->hasMany(UserGift::class, 'sender_id');
    }

    public function giftsReceived(): HasMany
    {
        return $this->hasMany(UserGift::class, 'receiver_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function reportsMade(): HasMany
    {
        return $this->hasMany(Report::class, 'reporter_id');
    }

    public function reportsAgainst(): HasMany
    {
        return $this->hasMany(Report::class, 'reported_id');
    }

    public function swipesGiven(): HasMany
    {
        return $this->hasMany(Swipe::class, 'user_id');
    }

    public function swipesReceived(): HasMany
    {
        return $this->hasMany(Swipe::class, 'target_user_id');
    }

    public function matchesAsUser1(): HasMany
    {
        return $this->hasMany(UserMatch::class, 'user1_id');
    }

    public function matchesAsUser2(): HasMany
    {
        return $this->hasMany(UserMatch::class, 'user2_id');
    }

    public function profileViewers(): HasMany
    {
        return $this->hasMany(ProfileView::class, 'viewed_id');
    }

    public function blockedUsers(): HasMany
    {
        return $this->hasMany(UserBlock::class, 'blocker_id');
    }

    public function blockers(): HasMany
    {
        return $this->hasMany(UserBlock::class, 'blocked_id');
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class);
    }

    public function subscribedAuthors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'diary_subscriptions', 'subscriber_id', 'author_id');
    }

    public function diarySubscribers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'diary_subscriptions', 'author_id', 'subscriber_id');
    }

    public function diaryLikes(): HasMany
    {
        return $this->hasMany(DiaryLike::class);
    }

    public function diaryCommentLikes(): HasMany
    {
        return $this->hasMany(DiaryCommentLike::class);
    }

    public function adminLogs(): HasMany
    {
        return $this->hasMany(AdminLog::class, 'admin_id');
    }

    /**
     * Интересы пользователя.
     * Через pivot user_interests.
     * Сортировка по pivot.created_at (по времени добавления).
     *
     * Для синхронизации используй syncInterests() — он валидирует существование ID.
     */
    public function interests(): BelongsToMany
    {
        return $this->belongsToMany(Interest::class, 'user_interests')
            ->withPivot('created_at')
            ->orderByPivot('created_at', 'asc');
    }


    // ============================================
    // СКОПЫ
    // ============================================

    public function scopeExcludeStaff(Builder $query): Builder
    {
        return $query->where('role', self::ROLE_USER);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Кандидаты для поиска (на уровне User).
     * Использует partial индексы users_search_candidates_idx (см. миграцию #1).
     */
    public function scopeSearchCandidates(Builder $query): Builder
    {
        return $query
            ->whereNull('users.deleted_at')
            ->where('users.status', self::STATUS_ACTIVE)
            ->where('users.role', self::ROLE_USER)
            ->where('users.has_completed_onboarding', true)
            ->whereHas('preferences', function ($pq) {
                $pq->where('hide_from_search', false)
                    ->where('is_invisible', false);
            });
    }

    /**
     * Верифицированные кандидаты.
     * Использует partial индекс users_verified_active_idx (см. миграцию #1).
     */
    public function scopeVerifiedOnly(Builder $query): Builder
    {
        return $query->where('is_verified', true);
    }

    /**
     * Онлайн-юзеры (last_seen < 5 минут назад).
     */
    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('last_seen', '>', now()->subMinutes(5));
    }

    /**
     * Премиум-юзеры.
     */
    public function scopePremium(Builder $query): Builder
    {
        return $query->whereNotNull('premium_expires_at')
            ->where('premium_expires_at', '>', now());
    }

    /**
     * VIP-юзеры.
     */
    public function scopeVip(Builder $query): Builder
    {
        return $query->whereNotNull('vip_expires_at')
            ->where('vip_expires_at', '>', now());
    }

    /**
     * Исключить уже просмотренных юзеров (по swipes).
     */
    public function scopeExcludeSwipedBy(Builder $query, int $userId): Builder
    {
        return $query->whereNotExists(function ($q) use ($userId) {
            $q->select(DB::raw(1))
                ->from('swipes')
                ->whereColumn('swipes.target_user_id', 'users.id')
                ->where('swipes.user_id', $userId);
        });
    }

    /**
     * Исключить тех, с кем уже матч.
     */
    public function scopeExcludeMatchedWith(Builder $query, int $userId): Builder
    {
        return $query->whereNotExists(function ($q) use ($userId) {
            $q->select(DB::raw(1))
                ->from('user_matches')
                ->where(function ($qq) use ($userId) {
                    $qq->where(function ($qqq) use ($userId) {
                        $qqq->where('user1_id', $userId)
                            ->whereColumn('user2_id', 'users.id');
                    })->orWhere(function ($qqq) use ($userId) {
                        $qqq->where('user2_id', $userId)
                            ->whereColumn('user1_id', 'users.id');
                    });
                });
        });
    }

    /**
     * Исключить заблокированных юзером.
     */
    public function scopeExcludeBlockedBy(Builder $query, int $userId): Builder
    {
        return $query->whereNotExists(function ($q) use ($userId) {
            $q->select(DB::raw(1))
                ->from('user_blocks')
                ->whereColumn('user_blocks.blocked_id', 'users.id')
                ->where('user_blocks.blocker_id', $userId);
        });
    }

    // ============================================
    // АКСЕССОРЫ И ХЕЛПЕРЫ
    // ============================================

    public function hasCompletedOnboarding(): bool
    {
        if ($this->isStaff()) {
            return true;
        }
        if ($this->has_completed_onboarding) {
            return true;
        }
        if ($this->relationLoaded('photos')) {
            return $this->photos->isNotEmpty();
        }
        return $this->photos()->exists();
    }

    public function isStaff(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_MODERATOR, self::ROLE_SUPPORT]);
    }

    public function isBanned(): bool
    {
        if ($this->status !== self::STATUS_BANNED) {
            return false;
        }
        return is_null($this->banned_until) || $this->banned_until->isFuture();
    }

    public function getIsOnlineAttribute(): bool
    {
        try {
            if (app(\App\Services\V1\UserOnlineService::class)->isOnline($this->id)) {
                return true;
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Redis online check failed, using DB fallback', [
                'user_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->last_seen && $this->last_seen->gt(now()->subMinutes(5));
    }

    public function getHasActivePremiumAttribute(): bool
    {
        return $this->premium_expires_at && $this->premium_expires_at->isFuture();
    }

    public function getHasActiveVipAttribute(): bool
    {
        return $this->vip_expires_at && $this->vip_expires_at->isFuture();
    }

    public function getAvatarUrlAttribute(): string
    {
        if ($this->relationLoaded('photos')) {
            $photos = $this->getRelation('photos');
            if ($photos && $photos->isNotEmpty()) {
                $photo = $photos->firstWhere(fn($p) => $p->is_primary && $p->status === 'approved')
                    ?? $photos->firstWhere('status', 'approved')
                    ?? $photos->firstWhere('is_primary', true)
                    ?? $photos->first();
                return $photo ? ($photo->thumb_url ?? '') : '';
            }
        }
        return '';
    }

    public function getLocaleAttribute(): string
    {
        if (!$this->relationLoaded('preferences')) {
            return config('app.locale');
        }
        return $this->preferences?->locale ?? config('app.locale');
    }

    public function getThemeAttribute(): string
    {
        if (!$this->relationLoaded('preferences')) {
            return 'light';
        }
        return $this->preferences?->theme ?? 'light';
    }

    public function getPushEnabledAttribute(): bool
    {
        if (!$this->relationLoaded('preferences')) {
            return true;
        }
        return $this->preferences?->push_enabled ?? true;
    }

    public function getEmailEnabledAttribute(): bool
    {
        if (!$this->relationLoaded('preferences')) {
            return true;
        }
        return $this->preferences?->email_enabled ?? true;
    }

    public function getEmailSettingsAttribute(): array
    {
        if (!$this->relationLoaded('preferences') || !$this->preferences) {
            return [
                'on_message' => true,
                'on_like' => true,
                'on_view' => false,
                'on_photo_moderated' => true,
                'on_report' => true,
                'on_ban' => true,
                'on_broadcast' => true
            ];
        }
        return $this->preferences->email_settings;
    }

    public function resetCounters(): void
    {
        ChatParticipant::where('user_id', $this->id)
            ->where('unread_count', '>', 0)
            ->update(['unread_count' => 0, 'last_read_at' => now()]);

        $this->unreadNotifications()->update(['read_at' => now()]);
    }

    // ============================================
    // ИНТЕРЕСЫ — БЕЗОПАСНАЯ СИНХРОНИЗАЦИЯ
    // ============================================

    /**
     * Синхронизировать интересы юзера.
     *
     * Простая обёртка над ->sync().
     * Триггер user_interests_update_count автоматически обновит interests.users_count.
     *
     * @param array $interestIds — массив ID интересов
     * @param bool $detach — true: удалить остальные, false: только добавить
     * @return array — ['attached' => [...], 'detached' => [...], 'updated' => [...]]
     */
    public function syncInterests(array $interestIds, bool $detach = true): array
    {
        return $this->interests()->sync($interestIds, $detach);
    }

    /**
     * Добавить интерес юзеру.
     */
    public function addInterest(int $interestId): void
    {
        $this->interests()->attach($interestId);
    }

    /**
     * Убрать интерес у юзера.
     */
    public function removeInterest(int $interestId): void
    {
        $this->interests()->detach($interestId);
    }



    // ============================================
    // СОБЫТИЯ МОДЕЛИ
    // ============================================
    protected static function booted()
    {
        static::creating(function (User $user) {
            $slugify = new Slugify(['rulesets' => ['default', 'russian']]);
            $baseSlug = $slugify->slugify($user->name ?? '');

            if (empty($baseSlug)) {
                $baseSlug = 'user';
            }

            $user->slug = $baseSlug . '-' . Str::lower(Str::random(8));
        });

        static::created(function (User $user) {
            DB::transaction(function () use ($user) {
                $user->profile()->create();
                $user->preferences()->create();
                $user->balance()->create();
                Album::createDefaultForUser($user);
            });
        });

        static::deleted(function (User $user) {
            // Удаляем сессии (logout пользователя со всех устройств).
            DB::table('sessions')->where('user_id', $user->id)->delete();
            // Триггер из миграции #5 обновит user_profiles.deleted_at при soft-delete.
        });

        // При восстановлении юзера — триггер восстановит user_profiles.deleted_at.
    }

    public function getRouteKeyName()
    {
        return 'slug';
    }
}
