<?php

namespace App\Http\Resources\Api\V1\Admin\Security;

use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Карточка юзера с блок-сигналом (для /block-signals list).
 *
 * Заменяет inline-маппинг в BlockSignalController::index() (который склеивает
 * User + aggregate из UserBlock GROUP BY).
 *
 * ОСОБЕННОСТЬ: Этот Resource строится НЕ из одной модели, а из склейки:
 *   - $this->resource — это объект stdClass/StdObject из UserBlock::groupBy()
 *     с полями blocked_id, total_blocks_count, recent_blocks_count, last_block_at.
 *   - Юзер ВЛОЖЕН через setRelation('user', $userModel) в контроллере.
 *
 * Если user не подгружен (был удалён?) — early-return null в контроллере.
 * Если Resource вызывается без loaded user — отдаём только counts.
 */
class BlockSignalResource extends JsonResource
{
    public function toArray($request): array
    {
        // Counts — из aggregate-запроса UserBlock::groupBy().
        // Это поля на $this->resource (stdClass), НЕ на модели User.
        $data = [
            'total_blocks_count'  => (int) $this->total_blocks_count,
            'recent_blocks_count' => (int) $this->recent_blocks_count,
            'last_block_at'       => $this->last_block_at
                ? Carbon::parse($this->last_block_at)->toIso8601String()
                : null,
        ];

        // user — подменяется через setRelation('user', $user) в контроллере.
        // Если загружен — отдаём inline user (без withRole — staff исключён в query).
        $user = $this->user ?? null;
        if ($user) {
            $data = array_merge([
                'id'                 => $user->id,
                'name'               => $user->name,
                'email'              => $user->email,
                'avatar_url'         => $user->avatar_url,
                'is_online'          => $user->is_online,
                'status'             => $user->status,
                'deleted_at'         => $user->deleted_at?->toIso8601String(),
                'has_active_premium' => $user->has_active_premium ?? false,
                'has_active_vip'     => $user->has_active_vip ?? false,
                'is_verified'        => $user->is_verified ?? false,
            ], $data);
        }

        return $data;
    }
}
