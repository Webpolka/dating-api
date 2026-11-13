<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;
use App\Events\TransactionRefunded;

class Transaction extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFUNDED = 'refunded';

    public const TYPE_SUBSCRIPTION = 'subscription';
    public const TYPE_CREDITS = 'credits';
    public const TYPE_REFUND = 'refund';

    // Статусы возврата в meta (для защиты от двойной инициации).
    // См. TransactionController::refund() и ProcessRefundJob.
    public const REFUND_STATUS_INITIATED = 'initiated';
    public const REFUND_STATUS_SUCCESS = 'success';
    public const REFUND_STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'amount',
        'currency',
        'type',
        'status',
        'provider',
        'provider_transaction_id',
        'credits_amount',
        'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'credits_amount' => 'integer',
        'meta' => 'array',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopeSuccess(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeRefunded(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_REFUNDED);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeSubscriptions(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_SUBSCRIPTION);
    }

    public function scopeCredits(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_CREDITS);
    }

    public function scopePeriod(Builder $query, $startDate, $endDate): Builder
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    // ============================================
    // ХЕЛПЕРЫ СТАТУСОВ
    // ============================================
    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function markAsSuccess(array $metaData = []): bool
    {
        if ($this->status === self::STATUS_SUCCESS) {
            return true;
        }

        $updated = $this->update([
            'status' => self::STATUS_SUCCESS,
            'meta' => array_merge($this->meta ?? [], $metaData),
        ]);

        if ($updated) {
            self::clearStatsCache();
        }

        return $updated;
    }

    public function markAsFailed(?string $reason = null): bool
    {
        $updated = $this->update([
            'status' => self::STATUS_FAILED,
            'meta' => array_merge($this->meta ?? [], ['fail_reason' => $reason]),
        ]);

        if ($updated) {
            self::clearStatsCache();
        }

        return $updated;
    }

    public function markAsRefunded(array $metaData = []): bool
    {
        if ($this->status !== self::STATUS_SUCCESS) {
            return false;
        }

        // Помечаем в meta, что возврат успешен — на случай, если кто-то
        // попытается инициировать возврат повторно (TransactionController::refund()
        // проверяет meta.refund_status перед созданием нового Job).
        $metaData['refund_status'] = self::REFUND_STATUS_SUCCESS;

        $updated = $this->update([
            'status' => self::STATUS_REFUNDED,
            'meta' => array_merge($this->meta ?? [], $metaData),
        ]);

        if ($updated) {
            self::clearStatsCache();
            event(new TransactionRefunded($this));
        }

        return $updated;
    }

    /**
     * Возвращает текущий статус возврата из meta (или null, если возврат
     * никогда не инициировался). Используется в TransactionController::refund()
     * для защиты от двойного клика.
     */
    public function getRefundStatus(): ?string
    {
        return $this->meta['refund_status'] ?? null;
    }

    /**
     * Пометить в meta, что возврат инициирован (но ещё не обработан банком).
     * ProcessRefundJob потом обновит refund_status на 'success' или 'failed'.
     */
    public function markRefundInitiated(string $adminName): void
    {
        $this->update([
            'meta' => array_merge($this->meta ?? [], [
                'refund_status' => self::REFUND_STATUS_INITIATED,
                'refund_initiated_by' => $adminName,
                'refund_initiated_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Пометить в meta, что возврат отклонён банком. Не меняем основной
     * status (остаётся 'success'), только refund_status.
     * Это позволяет повторить возврат позже (TransactionController::refund()
     * пропустит, если refund_status === 'initiated', но пустит, если 'failed').
     */
    public function markRefundFailed(string $errorMessage): void
    {
        $this->update([
            'meta' => array_merge($this->meta ?? [], [
                'refund_status' => self::REFUND_STATUS_FAILED,
                'refund_error' => $errorMessage,
                'refund_failed_at' => now()->toIso8601String(),
            ]),
        ]);
    }

    // ============================================
    // КЭШ СТАТИСТИКИ
    // ============================================
    /**
     * Сбросить кэш статистики транзакций для всех периодов.
     *
     * Ключи кэша: 'admin_transactions_stats_{period}' где period ∈ {all,day,week,month}.
     * Вызывать после любого изменения status транзакции:
     *  - markAsSuccess(), markAsFailed(), markAsRefunded() — здесь, в модели
     *  - ProcessSyncJob, ProcessRefundJob — через эти методы модели
     *
     * Контроллер sync()/refund() НЕ чистит кэш при инициации (т.к. статус
     * ещё не изменился) — только Job'ы после реального обновления.
     */
    public static function clearStatsCache(): void
    {
        foreach (['all', 'day', 'week', 'month'] as $period) {
            Cache::forget("admin_transactions_stats_{$period}");
        }
    }

    // ============================================
    // АКСЕССОРЫ ДЛЯ UI
    // ============================================
    public function getStatusBadgeAttribute(): array
    {
        return match ($this->status) {
            self::STATUS_PENDING  => ['variant' => 'warning', 'label' => 'В ожидании'],
            self::STATUS_SUCCESS  => ['variant' => 'success', 'label' => 'Успешно'],
            self::STATUS_FAILED   => ['variant' => 'destructive', 'label' => 'Ошибка'],
            self::STATUS_REFUNDED => ['variant' => 'secondary', 'label' => 'Возврат'],
            default               => ['variant' => 'secondary', 'label' => 'Неизвестно'],
        };
    }

    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 2, '.', ' ') . ' ' . $this->currency;
    }
}
