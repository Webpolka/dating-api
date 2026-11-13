<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatParticipant extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_id',
        'user_id',
        'unread_count',
        'last_read_at',
        'is_hidden',
        'is_muted',
        'is_blocked',
    ];

    protected $casts = [
        'last_read_at' => 'datetime',
        'unread_count' => 'integer',
        'is_hidden' => 'boolean',
        'is_muted' => 'boolean',
        'is_blocked' => 'boolean',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ============================================
    // ХЕЛПЕРЫ ДЛЯ СТАТУСОВ ЧАТА
    // ============================================
    /**
     * Отметить чат как прочитанный.
     * Сбрасываем счётчик в Redis (мгновенно), БД обновляется через Job.
     */
    public function markAsRead(): void
    {
        // 1. Сбрасываем счётчик в Redis (мгновенно, 0.1ms)
        app(\App\Services\UnreadCounterService::class)->resetChat($this->user_id, $this->chat_id);

        // 2. Обновляем last_read_at в БД (быстро, раз в 5 минут через throttling)
        if (!$this->last_read_at || $this->last_read_at->lt(now()->subMinutes(5))) {
            $this->update(['last_read_at' => now()]);
        }
    }

    /**
     * Возвращает количество непрочитанных сообщений.
     * Сначала проверяем Redis (мгновенно), fallback на БД.
     */
    public function getUnreadCountAttribute($value): int
    {
        // Пытаемся получить из Redis (primary source)
        try {
            $redisValue = app(\App\Services\UnreadCounterService::class)
                ->getChatCount($this->user_id, $this->chat_id);

            // Если в Redis есть значение — возвращаем его
            if ($redisValue > 0) {
                return $redisValue;
            }
        } catch (\Throwable $e) {
            // Redis упал — fallback на БД
        }

        // Fallback: значение из БД
        return (int) $value;
    }

    public function incrementUnread(): void
    {
        $this->increment('unread_count');
    }

    public function hide(): void
    {
        $this->update(['is_hidden' => true]);
    }

    public function unhide(): void
    {
        $this->update(['is_hidden' => false]);
    }

    public function mute(): void
    {
        $this->update(['is_muted' => true]);
    }

    public function unmute(): void
    {
        $this->update(['is_muted' => false]);
    }

    public function block(): void
    {
        $this->update(['is_blocked' => true]);
    }

    public function unblock(): void
    {
        $this->update(['is_blocked' => false]);
    }
}
