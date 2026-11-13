<?php

namespace App\Actions\V1\Admin\Main;

use App\Models\AdminLog;
use App\Models\Photo;
use App\Models\User;
use App\Notifications\V1\UserBanned;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ToggleUserBanAction
{
    /**
     * Забанить или разбанить пользователя.
     *
     * @param User $user
     * @param User $admin
     * @param string $reason    
     * @param string $type      permanent | temp | shadow
     * @param bool $forceBan    true — забанить безусловно (админский эндпоинт);
     *                          false — тумблер: бан/разбан по текущему статусу
     * @param int|null $days    длительность temp-бана в днях (1–365).
     *                          null — дефолт 3 дня (историческое поведение).
     *                          Игнорируется для permanent/shadow.
     * @return array{success: bool, is_banned?: bool, message: string}
     */
    public function execute(User $user, User $admin, string $reason = 'Нарушение правил сервиса', string $type = 'permanent', bool $forceBan = false, ?int $days = null): array
    {
        if ($user->isStaff()) {
            return ['success' => false, 'message' => 'Нельзя забанить сотрудника (админа/модератора)'];
        }

        $isCurrentlyBanned = ($user->status === 'banned' || $user->status === 'shadowbanned');

        if ($forceBan) {
            return $this->ban($user, $admin, $reason, $type, $days);
        }

        if ($isCurrentlyBanned) {
            return $this->unban($user, $admin);
        }

        return $this->ban($user, $admin, $reason, $type, $days);
    }

    protected function ban(User $user, User $admin, string $reason, string $type, ?int $days = null): array
    {
        // Нормализуем длительность temp-бана: по умолчанию 3 дня (историческое
        // поведение), жёсткие границы 1–365, чтобы в banned_until не улетело
        // что-то дикое вроде 100000 дней.
        $days = $type === 'temp' ? max(1, min(365, $days ?? 3)) : null;

        $before = [
            'status' => $user->getOriginal('status'),
            'ban_reason' => $user->getOriginal('ban_reason'),
            'banned_until' => $user->getOriginal('banned_until')
        ];

        $banData = match ($type) {
            'shadow' => [
                'status' => 'shadowbanned',
                'ban_reason' => $reason,
                'banned_until' => null,
            ],
            'temp' => [
                'status' => 'banned',
                'ban_reason' => $reason,
                'banned_until' => now()->addDays($days),
            ],
            default => [
                'status' => 'banned',
                'ban_reason' => $reason,
                'banned_until' => null,
            ],
        };

        // Собираем ID pending-фото ДО отклонения — для логирования.
        // Используем 'other' вместо 'user_banned' (не было в enum).
        //
        // В той же транзакции разлогиниваем юзера: убиваем DB-сессии и
        // отзываем все Sanctum-токены. Раньше этого не было — забаненный
        // с живой сессией продолжал пользоваться API до следующего логина,
        // т.к. isBanned() проверяется только при аутентификации.
        $rejectedPhotoIds = DB::transaction(function () use ($user, $banData) {
            $user->update($banData);

            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->tokens()->delete();

            $ids = $user->photos()
                ->where('status', 'pending')
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            if (!empty($ids)) {
                Photo::whereIn('id', $ids)->update([
                    'status'        => 'rejected',
                    'moderated_by'  => null, // системное действие, не модератор
                    'moderated_at'  => now(),
                    'reject_reason' => 'other',
                    'is_primary'    => false,
                ]);
            }

            return $ids;
        });

        $after = [
            'status' => $banData['status'],
            'ban_reason' => $banData['ban_reason'],
            'banned_until' => $banData['banned_until']?->toDateTimeString(),
            'ban_type' => $type,
            'banned_at' => now()->toDateTimeString(),
            'logged_out' => true, // сессии и токены юзера отозваны в этой же транзакции
            'context' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'admin_id' => $admin->id,
            ]
        ];

        $actionName = $type === 'shadow' ? 'user.shadowban' : 'user.ban';

        AdminLog::record(
            $actionName,
            $user,
            $admin,
            $before,
            $after,
            participants: [$user->id]
        );

        // Отдельная запись в лог для отклонённых фото — cascade от бана.
        // ОДНА запись (не цикл!), с массивом ID затронутых фото.
        // Пользователь НЕ получает уведомление PhotoModerated — он уже получает
        // уведомление UserBanned. Двойное уведомление было бы спамом.
        if (!empty($rejectedPhotoIds)) {
            $rejectedLog = [];
            foreach ($rejectedPhotoIds as $id) {
                $rejectedLog[] = ['id' => $id];
                if (count($rejectedLog) >= 50) break;
            }

            AdminLog::record(
                'photo.mass_reject',
                $user,
                $admin,
                null,
                [
                    'status'        => 'rejected',
                    'count'         => count($rejectedPhotoIds),
                    'rejected'      => $rejectedLog,
                    'truncated'     => count($rejectedPhotoIds) > 50,
                    'bulk_context'  => 'user_ban',
                    'reject_reason' => 'other',
                    'moderated_by'  => null,
                    'context'       => [
                        'user_id' => $user->id,
                        'cascade_from' => $actionName,
                    ],
                ],
                participants: [$user->id]
            );
        }

        if ($type !== 'shadow') {
            try {
                $user->notify(new UserBanned(true, $reason));
            } catch (\Exception $e) {
                Log::error('Ошибка отправки уведомления о бане: ' . $e->getMessage());
            }
        }

        $banLabel = match ($type) {
            'shadow' => 'подвергнут теневому бану',
            'temp' => 'забанен на ' . $days . ' ' . $this->pluralDays($days),
            default => 'забанен навсегда'
        };

        return ['success' => true, 'is_banned' => true, 'message' => "Пользователь {$user->name} {$banLabel}"];
    }

    /**
     * Русская плюрализация для «день/дня/дней».
     * Отдельный хелпер, чтобы не тянуть целый пакет ради одной строки.
     */
    private function pluralDays(int $n): string
    {
        $mod10 = $n % 10;
        $mod100 = $n % 100;

        return match (true) {
            $mod10 === 1 && $mod100 !== 11 => 'день',
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 10 || $mod100 >= 20) => 'дня',
            default => 'дней',
        };
    }


    protected function unban(User $user, User $admin): array
    {
        $before = [
            'status' => $user->getOriginal('status'),
            'ban_reason' => $user->getOriginal('ban_reason'),
            'banned_until' => $user->getOriginal('banned_until')
        ];

        $user->update([
            'status' => 'active',
            'ban_reason' => null,
            'banned_until' => null,
        ]);

        $after = [
            'status' => 'active',
            'unbanned_at' => now()->toDateTimeString(),
            'unbanned_by' => $admin->id,
            'context' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'admin_id' => $admin->id,
            ]
        ];

        AdminLog::record(
            'user.unban',
            $user,
            $admin,
            $before,
            $after,
            participants: [$user->id]
        );

        if ($before['status'] !== 'shadowbanned') {
            try {
                $user->notify(new UserBanned(false, "Ваш аккаунт разблокирован. Приносим извинения за неудобства."));
            } catch (\Exception $e) {
                Log::error('Ошибка отправки уведомления о разбане: ' . $e->getMessage());
            }
        }

        return ['success' => true, 'is_banned' => false, 'message' => "Пользователь {$user->name} разбанен"];
    }
}
