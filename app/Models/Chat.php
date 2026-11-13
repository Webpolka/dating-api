<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\QueryException;

class Chat extends Model
{
    use HasFactory;

    public const TYPE_PRIVATE = 'private';
    public const TYPE_SUPPORT = 'support';

    protected $fillable = [
        'type',
        'last_message_at',
        'is_locked',
        'participants_hash',
        // === STAFF STATE (для support-чатов) ===
        // Глобальное state для всех staff (модель «одно лицо»).
        // Для private чатов не используется (всегда 0 / false / null).
        'staff_unread_count',
        'staff_last_read_at',
        'staff_last_read_by_id',
        'is_archived',
    ];

    protected $casts = [
        'last_message_at'      => 'datetime',
        'is_locked'            => 'boolean',
        'staff_unread_count'   => 'integer',
        'staff_last_read_at'   => 'datetime',
        'is_archived'          => 'boolean',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_participants')
            ->withPivot(['unread_count', 'last_read_at', 'is_hidden', 'is_muted', 'is_blocked'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Кто из staff последний раз прочитал support-чат (для аудита/UI).
     *
     * Связь на User через chats.staff_last_read_by_id.
     * Используется в SupportChatController::index()/show() для отображения
     * «Прочитал {name} 5 мин назад» в списке тикетов и в шапке чата.
     *
     * Nullable — для свежесозданных чатов, которые ещё никто не читал,
     * staff_last_read_by_id = null, связь вернёт null.
     */
    public function lastReadBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_last_read_by_id');
    }

    /**
     * Последнее сообщение в чате (одна запись).
     *
     * ВАЖНО: использует latestOfMany() — это правильный способ получить
     * 1 запись на каждый parent при eager loading. Обычный
     * ->with(['messages' => fn($q) => $q->latest()->limit(1)]) грузит
     * 1 сообщение ВСЕГО на всю страницу (limit применяется глобально,
     * а не per-parent) — это известная ловушка Laravel.
     *
     * latestOfMany() под капотом использует оконную функцию
     * ROW_NUMBER() OVER (PARTITION BY chat_id ORDER BY id DESC),
     * что работает быстро и корректно на больших объёмах.
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopePrivate(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_PRIVATE);
    }

    public function scopeSupport(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_SUPPORT);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================
    public function getPartner(int $userId): ?User
    {
        if ($this->relationLoaded('users')) {
            return $this->users->firstWhere('id', '!=', $userId);
        }

        return $this->users()->where('user_id', '!=', $userId)->first();
    }

    // ============================================
    // БИЗНЕС-ЛОГИКА (СОЗДАНИЕ ЧАТОВ)
    // ============================================
    public static function getOrCreateBetween(int $userAId, int $userBId): self
    {
        $hash = md5(min($userAId, $userBId) . '-' . max($userAId, $userBId));

        // Сначала пробуем найти существующий чат (быстро, без try/catch)
        $existingChat = self::where('participants_hash', $hash)->first();
        if ($existingChat) {
            self::ensureParticipantsExist($existingChat, $userAId, $userBId);
            return $existingChat;
        }

        // Если не нашли — создаём (с защитой от race condition)
        try {
            $chat = self::create([
                'participants_hash' => $hash,
                'type' => self::TYPE_PRIVATE,
                'last_message_at' => now()
            ]);
            self::ensureParticipantsExist($chat, $userAId, $userBId);
            return $chat;
        } catch (\Illuminate\Database\UniqueConstraintViolationException | \Illuminate\Database\QueryException $e) {
            // Race condition — кто-то создал чат между find и create
            return self::where('participants_hash', $hash)->firstOrFail();
        }
    }

    /**
     * Найти или создать support-чат для юзера.
     *
     * ВАЖНО про архитектуру (модель «одно лицо для staff»):
     *   - В chat_participants для support-чатов хранится ТОЛЬКО юзер
     *     (один участник). Админ НЕ добавляется в participants — он
     *     «виртуальный» участник через staff-роль.
     *   - Все staff видят все support-чаты (без фильтра по participants).
     *   - State «прочитано/архив» — глобальное на уровне чата
     *     (staff_unread_count, staff_last_read_at, is_archived в chats).
     *   - Если один staff прочитал → все остальные тоже видят «прочитано».
     *
     * Хэш 'support-' . $userId → ОДИН support-чат на юзера (независимо
     * от того какой админ его создал или впервые написал).
     */
    public static function getOrCreateSupportChat(int $userId): self
    {
        $hash = md5('support-' . $userId);

        // Сначала пробуем найти существующий саппорт-чат
        $existingChat = self::where('participants_hash', $hash)->first();
        if ($existingChat) {
            self::ensureSupportParticipant($existingChat, $userId);
            return $existingChat;
        }

        // Если не нашли — создаём
        try {
            $chat = self::create([
                'type'               => self::TYPE_SUPPORT,
                'participants_hash'  => $hash,
                'last_message_at'    => now(),
                // staff_unread_count = 0 по умолчанию (в миграции)
                // is_archived = false по умолчанию (в миграции)
            ]);
            self::ensureSupportParticipant($chat, $userId);
            return $chat;
        } catch (\Illuminate\Database\UniqueConstraintViolationException | \Illuminate\Database\QueryException $e) {
            // Race condition — кто-то создал чат между find и create
            return self::where('participants_hash', $hash)->firstOrFail();
        }
    }

    /**
     * Гарантировать что юзер есть в chat_participants support-чатa.
     *
     * Для support-чатов участник ОДИН — юзер. Админ не добавляется
     * (он «виртуальный» через staff-роль, видит все support-чаты).
     *
     * Юзеру нужен participant-record потому что:
     *   - unread_count для юзера (per-user, для notification юзеру)
     *   - is_hidden / is_muted для юзера (per-user preferences)
     */
    private static function ensureSupportParticipant(self $chat, int $userId): void
    {
        ChatParticipant::firstOrCreate(
            ['chat_id' => $chat->id, 'user_id' => $userId],
            ['unread_count' => 0]
        );
    }

    private static function ensureParticipantsExist(self $chat, int $user1Id, int $user2Id): void
    {
        ChatParticipant::firstOrCreate(
            ['chat_id' => $chat->id, 'user_id' => $user1Id],
            ['unread_count' => 0]
        );
        ChatParticipant::firstOrCreate(
            ['chat_id' => $chat->id, 'user_id' => $user2Id],
            ['unread_count' => 0]
        );
    }
}
