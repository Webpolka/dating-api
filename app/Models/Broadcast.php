<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Broadcast extends Model
{
    use HasFactory;

    public const TYPE_IN_APP = 'in_app';
    public const TYPE_PUSH = 'push';
    public const TYPE_EMAIL = 'email';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING = 'sending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'admin_id',
        'type',
        'title',
        'message',
        'email_body',
        'data',
        'target_audience',
        'status',
        'scheduled_at',
        'started_at',
        'sent_at',
        'total_recipients',
        'sent_count',
        'failed_count',
    ];

    protected $casts = [
        'data' => 'array',
        'target_audience' => 'array',
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'sent_at' => 'datetime',
        'total_recipients' => 'integer',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_DRAFT);
    }
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SCHEDULED);
    }
    public function scopeSending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENDING);
    }
    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENT);
    }

    public function scopeDueForDispatch(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now());
    }

    // ============================================
    // ХЕЛПЕРЫ ЖИЗНЕННОГО ЦИКЛА
    // ============================================
    public function markAsSending(int $totalRecipients): bool
    {
        return $this->update([
            'status' => self::STATUS_SENDING,
            'started_at' => now(),
            'total_recipients' => $totalRecipients,
        ]);
    }

    public function markAsSent(): bool
    {
        return $this->update([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    public function markAsFailed(): bool
    {
        return $this->update([
            'status' => self::STATUS_FAILED,
            'sent_at' => now(),
        ]);
    }

    public function incrementSentBatch(int $count = 1): void
    {
        $this->newQuery()->where('id', $this->id)->increment('sent_count', $count);
        $this->sent_count += $count;
    }

    public function incrementFailedBatch(int $count = 1): void
    {
        $this->newQuery()->where('id', $this->id)->increment('failed_count', $count);
        $this->failed_count += $count;
    }

    // ============================================
    // АКСЕССОРЫ
    // ============================================
    public function getProgressAttribute(): int
    {
        if ($this->total_recipients === 0) {
            return 0;
        }

        return (int) round((($this->sent_count + $this->failed_count) / $this->total_recipients) * 100);
    }

    public function getAudiencePartsAttribute(): array
    {
        $audience = $this->target_audience ?? [];
        $parts = [];

        $toBool = fn($val) => filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if (!empty($audience['gender'])) {
            $parts[] = $audience['gender'] === 'male' ? 'Мужчины' : 'Женщины';
        }

        if (isset($audience['is_premium'])) {
            $parts[] = $toBool($audience['is_premium']) ? 'VIP' : 'Без VIP';
        }

        if (!empty($audience['city_label'])) {
            $parts[] = 'Город: ' . $audience['city_label'];
        }

        $ageStr = '';
        if (!empty($audience['age_from'])) $ageStr .= 'от ' . $audience['age_from'];
        if (!empty($audience['age_from']) && !empty($audience['age_to'])) $ageStr .= '-';
        elseif (!empty($audience['age_to'])) $ageStr .= 'до ';
        if (!empty($audience['age_to'])) $ageStr .= $audience['age_to'];
        if ($ageStr) $parts[] = 'Возраст: ' . $ageStr . ' лет';

        if (!empty($audience['device_os'])) {
            $osMap = ['ios' => 'iOS', 'android' => 'Android', 'web' => 'Web'];
            $parts[] = $osMap[$audience['device_os']] ?? $audience['device_os'];
        }

        if (!empty($audience['last_seen_days'])) {
            $parts[] = 'неактивные >' . $audience['last_seen_days'] . 'д';
        }

        if (isset($audience['has_photo'])) {
            $parts[] = $toBool($audience['has_photo']) ? 'с фото' : 'без фото';
        }

        return $parts;
    }

    public function getAudienceLabelAttribute(): string
    {
        $audience = $this->target_audience ?? [];

        if (!empty($audience['user_id'])) {
            return 'Юзер: ' . ($audience['user_name'] ?? 'ID ' . $audience['user_id']);
        }

        $parts = $this->audience_parts;
        return empty($parts) ? 'Все пользователи' : implode(', ', $parts);
    }
}
