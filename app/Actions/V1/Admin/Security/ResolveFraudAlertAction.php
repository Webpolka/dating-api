<?php

namespace App\Actions\V1\Admin\Security;

use App\Actions\V1\Admin\Main\ToggleUserBanAction;
use App\Models\AdminLog;
use App\Models\Chat;
use App\Models\FraudAlert;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Бизнес-логика закрытия антифрод-алертов.
 * Извлечена из FraudAlertController (thin-controller pattern, см. ModeratePhotoAction).
 *
 * Архитектура:
 *   - Controller (HTTP-слой): валидация (ban type), resolution алерта
 *     (lockForUpdate + 404) + idempotency early-return, форматирование ответа.
 *   - ResolveFraudAlertAction (бизнес-логика): закрытие алерта (resolve /
 *     false_positive), бан юзера, system message в support-чат, AdminLog,
 *     инвалидация кэша.
 *
 * Композиция (см. ModerateReportAction):
 *   - resolveAndBan: бан юзера через ToggleUserBanAction внутри общей транзакции.
 *
 * M1 FIX (idempotency): все 3 метода рассчитывают на lockForUpdate в контроллере +
 *  early-return если алерт уже не Open. Без этого два админа, кликающие
 *  «Закрыть» одновременно, оба запишут AdminLog + (для warning) оба
 *  отправят system message в support-чат.
 *
 * M2 FIX (ban idempotency): resolveAndBan проверяет, забанен ли уже юзер
 *  в target ban-статусе. Если да — skip бан (не дублируем notification
 *  и AdminLog), но алерт всё равно закрываем.
 */
class ResolveFraudAlertAction
{
    public function __construct(
        private readonly ToggleUserBanAction $toggleBanAction,
    ) {
    }

    /**
     * Закрыть алерт + забанить юзера (если есть).
     *
     * Если user удалён — просто закрываем алерт без бана.
     * Если user уже забанен в target ban-статусе — skip бан (идемпотентность),
     * но алерт всё равно закрываем.
     *
     * @param FraudAlert $alert  Открытый алерт (lockForUpdate + isOpen() проверены в контроллере).
     * @param User       $admin  Кто модерировал.
     * @param string     $banType 'shadow' | 'temp' | 'permanent' (провалидирован в контроллере).
     */
    public function resolveAndBan(FraudAlert $alert, User $admin, string $banType): void
    {
        DB::transaction(function () use ($alert, $admin, $banType) {
            $banApplied = 'none (user deleted or already banned)';

            if ($alert->user_id) {
                $user = $alert->user;
                if ($user) {
                    // M2 FIX: idempotency бана — если юзер уже в target ban-статусе,
                    // skip бан. ToggleUserBanAction с forceBan=true всё равно
                    // перезапишет, что создаст дубль AdminLog + дубль notification.
                    $targetStatus = match ($banType) {
                        'shadow'    => 'shadowbanned',
                        'temp'      => 'banned',
                        'permanent' => 'banned',
                    };

                    if ($user->status === $targetStatus) {
                        $banApplied = "skipped (already {$targetStatus})";
                    } else {
                        $reason = "Антифрод алерт #{$alert->id}: " . $alert->trigger_label;
                        $this->toggleBanAction->execute($user, $admin, $reason, $banType, true);
                        $banApplied = $banType;
                    }
                }
            }

            $before = ['status' => $alert->getOriginal('status'), 'severity' => $alert->getOriginal('severity')];
            $alert->resolve($admin->id);

            $after = [
                'status' => 'resolved',
                'resolved_by' => $admin->id,
                'resolved_at' => now()->toDateTimeString(),
                'context' => [
                    'alert_id' => $alert->id,
                    'user_id' => $alert->user_id,
                    'trigger_type' => $alert->trigger_type->value,
                    'trigger_label' => $alert->trigger_label,
                    'severity' => $alert->severity->value,
                    'ban_type_applied' => $banApplied,
                ]
            ];

            $participants = $alert->user_id ? [$alert->user_id] : [];
            AdminLog::record('fraud_alert.resolve', $alert, $admin, $before, $after, $participants);
        });

        // Cache forget ПОСЛЕ commit
        $this->clearCaches();
    }

    /**
     * Закрыть алерт + отправить warning в support-чат юзера.
     *
     * System message создаётся ВНУТРИ transaction — это правильно,
     * потому что message в БД должно быть atomic с закрытием алерта.
     * Notifications здесь нет (system message не триггерит UserNotified),
     * так что не нужно переносить после commit.
     *
     * @param FraudAlert $alert Открытый алерт (lockForUpdate + isOpen() проверены в контроллере).
     * @param User       $admin Кто модерировал.
     */
    public function resolveWithWarning(FraudAlert $alert, User $admin): void
    {
        DB::transaction(function () use ($alert, $admin) {
            $before = ['status' => $alert->getOriginal('status'), 'severity' => $alert->getOriginal('severity')];
            $alert->resolve($admin->id);

            $after = [
                'status' => 'resolved',
                'resolved_by' => $admin->id,
                'resolved_at' => now()->toDateTimeString(),
                'action_taken' => 'warning',
                'context' => [
                    'alert_id' => $alert->id,
                    'user_id' => $alert->user_id,
                    'trigger_type' => $alert->trigger_type->value,
                    'trigger_label' => $alert->trigger_label,
                    'severity' => $alert->severity->value
                ]
            ];

            $participants = $alert->user_id ? [$alert->user_id] : [];
            AdminLog::record('fraud_alert.warning', $alert, $admin, $before, $after, $participants);

            if ($alert->user_id) {
                // Support-чат создаётся без привязки к конкретному админу —
                // все staff видят его сразу. См. Chat::getOrCreateSupportChat().
                $chat = Chat::getOrCreateSupportChat($alert->user_id);
                $warningText = "⚠️ Внимание! Администрация вынесла вам предупреждение за нарушение правил сервиса (Причина: {$alert->trigger_label}). Пожалуйста, ознакомьтесь с правилами платформы. При повторных нарушениях аккаунт может быть заблокирован.";
                $chat->messages()->create([
                    'sender_id' => null,
                    'type' => 'system',
                    'body' => $warningText,
                ]);
                $chat->update(['last_message_at' => now()]);
            }
        });

        $this->clearCaches();
    }

    /**
     * Закрыть алерт как ложное срабатывание.
     *
     * @param FraudAlert $alert Открытый алерт (lockForUpdate + isOpen() проверены в контроллере).
     * @param User       $admin Кто модерировал.
     */
    public function markAsFalsePositive(FraudAlert $alert, User $admin): void
    {
        DB::transaction(function () use ($alert, $admin) {
            $before = ['status' => $alert->getOriginal('status'), 'severity' => $alert->getOriginal('severity')];
            $alert->markAsFalsePositive($admin->id);

            $after = [
                'status' => 'false_positive',
                'resolved_by' => $admin->id,
                'resolved_at' => now()->toDateTimeString(),
                'context' => [
                    'alert_id' => $alert->id,
                    'user_id' => $alert->user_id,
                    'trigger_type' => $alert->trigger_type->value,
                    'trigger_label' => $alert->trigger_label,
                    'severity' => $alert->severity->value
                ]
            ];

            $participants = $alert->user_id ? [$alert->user_id] : [];
            AdminLog::record('fraud_alert.false_positive', $alert, $admin, $before, $after, $participants);
        });

        $this->clearCaches();
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function clearCaches(): void
    {
        Cache::forget('admin_sidebar_stats');
        Cache::forget('admin_fraud_counts');
        // ФИКС: resolveAndBan банит юзера через ToggleUserBanAction, но кэш
        // статистики юзеров не сбрасывался — счётчик «Бан» на странице /users
        // мог показывать устаревшее число до 60 секунд.
        Cache::forget('admin_users_stats');
    }
}
