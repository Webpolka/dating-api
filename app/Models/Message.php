<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_TEXT = 'text';
    public const TYPE_IMAGE = 'image';
    public const TYPE_SYSTEM = 'system';
    public const TYPE_GIFT = 'gift';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'chat_id',
        'sender_id',
        'type',
        'body',
        'attachment_url',
        'gift_id',
        'status',
        'reject_reason',
        'moderated_by',
        'moderated_at',
    ];

    protected $casts = [
        'moderated_at' => 'datetime',
    ];



    // ============================================
    // СВЯЗИ
    // ============================================
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id')->withTrashed();
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function gift(): BelongsTo
    {
        return $this->belongsTo(Gift::class);
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_APPROVED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeGifts(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_GIFT);
    }

    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_SYSTEM);
    }

    public function scopeImages(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_IMAGE);
    }

    // ============================================
    // ХЕЛПЕРЫ ТИПОВ
    // ============================================
    public function isText(): bool
    {
        return $this->type === self::TYPE_TEXT;
    }
    public function isGift(): bool
    {
        return $this->type === self::TYPE_GIFT;
    }
    public function isSystem(): bool
    {
        return $this->type === self::TYPE_SYSTEM;
    }
    public function isImage(): bool
    {
        return $this->type === self::TYPE_IMAGE;
    }
    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    // ============================================
    // ХЕЛПЕРЫ МОДЕРАЦИИ
    // ============================================
    public function markAsApproved(int $adminId): bool
    {
        return $this->update([
            'status' => self::STATUS_APPROVED,
            'moderated_by' => $adminId,
            'moderated_at' => now(),
            'reject_reason' => null,
        ]);
    }

    public function markAsRejected(int $adminId, string $reason): bool
    {
        return $this->update([
            'status' => self::STATUS_REJECTED,
            'moderated_by' => $adminId,
            'moderated_at' => now(),
            'reject_reason' => $reason,
        ]);
    }



     /**
     * Accessor: определить тип системного действия по body.
     *
     * Возвращает: 'lock' | 'unlock' | null.
     *
     * Используется в ChatMessageResource для поля system_action — фронт
     * использует это для цветовой дифференциации системных сообщений
     * (red=lock / green=unlock / blue=neutral) вместо fragile text matching.
     *
     * Парсинг централизован здесь — если текст системных сообщений изменится,
     * правим только в этом месте (не в Resource, не в Controller, не на фронте).
     *
     * Сейчас распознаёт:
     *   - "Чат заблокирован администрацией." → 'lock'
     *   - "Чат разблокирован администрацией." → 'unlock'
     *   - Любое другое system message → null
     *
     * ВАЖНО: для не-system сообщений всегда возвращает null — не надо
     * парсить обычные текстовые сообщения юзеров на наличие слова "заблокирован".
     *
     * TODO (будущее): когда станет колонкой messages.system_action (enum),
     * заменить парсинг на return $this->system_action. Resource не изменится.
     */
    public function getSystemActionAttribute(): ?string
    {
        if (! $this->isSystem()) {
            return null;
        }
        if (! $this->body) {
            return null;
        }
        if (str_contains($this->body, 'заблокирован')) {
            return 'lock';
        }
        if (str_contains($this->body, 'разблокирован')) {
            return 'unlock';
        }
        return null;
    }

    // === ПАТЧ ДЛЯ Message.php — добавить runtime-only атрибут contentFilterAlerts ===
    //
    // ПРОБЛЕМА:
    //   MessageObserver::creating() делал: $message->contentFilterAlerts = $result->alerts;
    //   Это писало массив alerts в $attributes модели → попадало в INSERT →
    //   "Array to string conversion" когда ContentFilter находил alert-слова.
    //   Раньше бага не проявлялась потому что alerts был пустым [] → кастится в null.
    //
    // РЕШЕНИЕ:
    //   Mutator (setContentFilterAlertsAttribute) перехватывает присваивание и
    //   складывает значение в private $runtimeAttributes — НЕ в $attributes.
    //   Accessor (getContentFilterAlertsAttribute) читает оттуда же.
    //   В INSERT это свойство НЕ попадает.
    //
    // В MessageObserver НИЧЕГО менять не надо — код `$message->contentFilterAlerts = $result->alerts;`
    // и `$message->contentFilterAlerts ?? []` продолжает работать, но теперь через
    // mutator/accessor, безопасно.
    //
    // Не переписывай весь файл. Вставь эти методы в класс Message рядом с
    // getSystemActionAttribute() (или в новой секции "RUNTIME ATTRIBUTES").

    /**
     * Runtime-only атрибуты модели — не сохраняются в БД.
     *
     * Используются observer'ами для передачи данных между lifecycle hooks
     * (creating → created), без записи в $attributes и SQL.
     *
     * @var array<string, mixed>
     */
    private array $runtimeAttributes = [];

    /**
     * Alerts от ContentFilterService — для обработки в created() hook.
     *
     * Устанавливается в MessageObserver::creating() через
     * $message->contentFilterAlerts = $result->alerts;
     * Читается в MessageObserver::created() через
     * $alerts = $message->contentFilterAlerts ?? [];
     *
     * Mutator перехватывает присваивание → не попадает в $attributes →
     * не попадает в INSERT SQL → нет "Array to string conversion".
     */
    public function getContentFilterAlertsAttribute(): array
    {
        return $this->runtimeAttributes['contentFilterAlerts'] ?? [];
    }

    /**
     * Mutator для contentFilterAlerts — пишет в $runtimeAttributes, не в $attributes.
     */
    public function setContentFilterAlertsAttribute(array $alerts): void
    {
        $this->runtimeAttributes['contentFilterAlerts'] = $alerts;
    } 

}
