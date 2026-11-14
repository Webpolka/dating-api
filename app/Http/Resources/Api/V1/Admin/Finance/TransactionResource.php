<?php

namespace App\Http\Resources\Api\V1\Admin\Finance;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Транзакция (платёж / возврат).
 *
 * Заменяет TransactionController::formatTransaction($t, $detailed).
 *
 * Особенности:
 *  - Содержит COMPUTED-ПОЛЯ ДЛЯ UI (строго сохранены из старого кода):
 *      * status_badge — accessor Transaction::getStatusBadgeAttribute()
 *        (variant, label — для shadcn-бейджа статуса)
 *      * type_badge — computed через match() в этом Resource.
 *        variant = shadcn-базовый вариант, className = кастомные Tailwind-классы.
 *        Цвет дифференцирует VIP/Premium/Кредиты/Возврат.
 *      * amount_class — computed через match() — Tailwind-класс для суммы,
 *        зависит от статуса (success=green, refunded=destructive, и т.д.).
 *      * formatted_amount — accessor Transaction::getFormattedAmountAttribute()
 *  - meta — попадает в ответ ТОЛЬКО когда вызван withDetails() (для show()).
 *    В index() meta не отдаётся (большой JSON, не нужен в списке).
 *
 * Использование:
 *   // list (без meta):
 *   TransactionResource::collection($transactions)
 *
 *   // detail (с meta):
 *   (new TransactionResource($transaction))->withDetails()
 */
class TransactionResource extends JsonResource
{
    private bool $withDetails = false;

    /**
     * Включить в ответ поле meta (для detail/show endpoint).
     * В list-режиме meta не нужна — это большой JSON с контекстом платежа.
     */
    public function withDetails(): static
    {
        $this->withDetails = true;
        return $this;
    }

    public function toArray($request): array
    {
        // === ВЫЧИСЛЯЕМЫЕ ПОЛЯ ДЛЯ UI ===
        // Сохранены 1-в-1 из старого TransactionController::formatTransaction().

        $statusBadge = $this->status_badge; // accessor Transaction::getStatusBadgeAttribute()

        // Тип для UI — единственная истина цветов тут.
        // variant = shadcn-базовый вариант, className = кастомные Tailwind-классы.
        $tier = $this->meta['tier'] ?? null;
        $typeBadge = match ($this->type) {
            'subscription' => [
                'variant'   => 'outline', // прозрачный фон + граница
                'label'     => $tier === 'vip' ? 'VIP' : 'Premium',
                'className' => $tier === 'vip'
                    ? 'bg-sky-500/15 text-sky-600 border-sky-500/30 hover:bg-sky-500/20 dark:text-sky-400 dark:border-sky-500/40'
                    : 'bg-orange-500/15 text-orange-600 border-orange-500/30 hover:bg-orange-500/20 dark:text-orange-400 dark:border-orange-500/40',
            ],
            'credits' => [
                'variant'   => 'secondary',
                'label'     => 'Кредиты',
                'className' => '',
            ],
            'refund' => [
                'variant'   => 'destructive',
                'label'     => 'Возврат',
                'className' => '',
            ],
            default => [
                'variant'   => 'secondary',
                'label'     => $this->type,
                'className' => '',
            ],
        };

        $amountClass = match ($this->status) {
            'refunded' => 'text-destructive font-medium',
            'failed'   => 'text-muted-foreground/50',
            'pending'  => 'text-muted-foreground',
            default    => 'text-green-500 font-medium',
        };

        $data = [
            'id'                      => $this->id,
            'user_id'                 => $this->user_id,
            'amount'                  => (float) $this->amount,
            'formatted_amount'        => $this->formatted_amount, // accessor
            'amount_class'            => $amountClass,
            'currency'                => $this->currency,
            'type'                    => $this->type,
            'type_badge'              => $typeBadge,
            'status'                  => $this->status,
            'status_badge'            => $statusBadge,
            'provider'                => $this->provider,
            'provider_transaction_id' => $this->provider_transaction_id,
            'credits_amount'          => $this->credits_amount,
            'created_at'              => $this->created_at?->toIso8601String(),
            'updated_at'              => $this->updated_at?->toIso8601String(),
            // user — без withRole (как в старом коде). AdminUserBadgeResource::nullable
            // без флагов возвращает ровно те же 9 полей: id, name, email, avatar_url,
            // is_online, status, deleted_at, has_active_premium, has_active_vip, is_verified.
            'user' => $this->whenLoaded('user', fn () => $this->user
                ? AdminUserBadgeResource::nullable($this->user)
                : null),
        ];

        // meta — только в detailed режиме (через withDetails()).
        // В list-режиме meta не нужна (большой JSON, не используется в карточке).
        if ($this->withDetails) {
            $data['meta'] = $this->meta;
        }

        return $data;
    }
}
