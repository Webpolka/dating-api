<?php

namespace App\Actions\V1\Admin\Security;

use App\Actions\V1\Admin\Main\ToggleUserBanAction;
use App\Models\AdminLog;
use App\Models\User;
use App\Models\UserBlock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Бизнес-логика действий по сигналам блокировок (антифрод).
 * Извлечена из BlockSignalController (thin-controller pattern, см. ModeratePhotoAction).
 *
 * Архитектура:
 *   - Controller (HTTP-слой): валидация (ban type), resolution юзера
 *     (lockForUpdate + 404) + staff-check + idempotency early-return,
 *     форматирование ответа.
 *   - ModerateBlockSignalAction (бизнес-логика): бан юзера по сигналу
 *     блокировок, AdminLog, инвалидация кэша.
 *
 * Композиция (см. ModerateReportAction):
 *   - ban: бан через ToggleUserBanAction (его AdminLog/уведомления)
 *     + отдельная запись 'block_signal.ban' для audit trail.
 *
 * M1 FIX: lockForUpdate — race condition между двумя админами,
 * кликающими «Вечный бан» одновременно на одного юзера.
 *
 * M2 FIX: Idempotency — если юзер уже в target ban-статусе,
 * early-return без повторного бана и уведомления. Без этого
 * дабл-клик по кнопке приводил к повторной отправке
 * UserBanned notification и дубликату в AdminLog.
 *
 * M3 FIX: DB::transaction — обёртка для atomicity. Если ban
 * упадёт в середине, AdminLog не запишется в "повисшем" состоянии.
 *
 * M4 FIX: AdminLog::record('block_signal.ban', ...) — отдельная
 * запись для audit trail, что именно сигнал блокировок был
 * триггером (а не жалоба / фото / ручной бан через users-страницу).
 */
class ModerateBlockSignalAction
{
    public function __construct(
        private readonly ToggleUserBanAction $toggleBanAction,
    ) {
    }

    /**
     * Бан юзера по сигналу блокировок.
     * Делегирует в ToggleUserBanAction (как fraud-alerts).
     *
     * @param User   $user    Целевой юзер (withTrashed, lockForUpdate + staff-check
     *                        + idempotency проверены в контроллере).
     * @param User   $admin   Кто модерировал.
     * @param string $banType 'shadow' | 'temp' | 'permanent' (провалидирован в контроллере).
     * @return array{success: bool, is_banned?: bool, message: string} Результат
     *         ToggleUserBanAction::execute() как есть (message уходит в JSON-ответ).
     *
     * @throws \RuntimeException ToggleUserBanAction вернул success=false
     *         (пробрасывается наружу для rollback — контроллер отдаёт 422).
     */
    public function ban(User $user, User $admin, string $banType): array
    {
        // Подсчитаем текущие блокировки для reason-строки (чтобы зафиксировать в AdminLog контекст)
        $stats = UserBlock::where('blocked_id', $user->id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as recent', [now()->subDays(7)])
            ->first();

        $total  = (int) ($stats?->total ?? 0);
        $recent = (int) ($stats?->recent ?? 0);
        $reason = "Сигнал блокировок: Всего {$total}, За 7 дней: {$recent}";

        $banResult = null;

        // M3 FIX: DB::transaction — atomicity для бана + AdminLog.
        // Если что-то упадёт внутри — откатится всё.
        DB::transaction(function () use ($user, $admin, $reason, $banType, &$banResult, $total, $recent) {
            $banResult = $this->toggleBanAction->execute($user, $admin, $reason, $banType, true);

            if (empty($banResult['success'])) {
                // Пробрасываем ошибку наружу — transaction rollback
                throw new \RuntimeException($banResult['message'] ?? 'Ошибка бана');
            }

            // M4 FIX: отдельная запись в AdminLog — что триггером был signal-of-blocks.
            // ToggleUserBanAction уже пишет 'user.ban' / 'user.shadowban',
            // но без контекста "почему" забанен. Эта запись — дополнение.
            $before = [
                'status'      => $user->getOriginal('status'),
                'ban_reason'  => $user->getOriginal('ban_reason'),
            ];

            $after = [
                'status'      => $user->status,
                'ban_type'    => $banType,
                'ban_reason'  => $reason,
                'context'     => [
                    'source'              => 'block_signal',
                    'user_id'             => $user->id,
                    'admin_id'            => $admin->id,
                    'total_blocks_count'  => $total,
                    'recent_blocks_count' => $recent,
                ],
            ];

            AdminLog::record('block_signal.ban', $user, $admin, $before, $after, participants: [$user->id]);
        });

        // Cache forget — ПОСЛЕ успешного commit, не внутри transaction.
        // Если transaction rollback, кэш не сбросится (нечего сбрасывать).
        $this->clearCaches();

        return $banResult;
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function clearCaches(): void
    {
        Cache::forget('admin_sidebar_stats');
        Cache::forget('admin_block_signal_counts');
        // ФИКС: ban() банит юзера через ToggleUserBanAction, но кэш
        // статистики юзеров не сбрасывался — счётчик «Бан» на странице /users
        // мог показывать устаревшее число до 60 секунд.
        Cache::forget('admin_users_stats');
    }
}
