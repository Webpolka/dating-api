<?php

namespace App\Actions\V1\Admin\Main;

use App\Models\AdminLog;
use App\Models\User;
use App\Notifications\V1\UserDeleted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteUserAction
{
    /**
     * Деактивировать (soft-delete) пользователя.
     *
     * Возвращает массив с success/message (а не void), чтобы контроллер
     * мог корректно ответить 422 при попытке удалить сотрудника —
     * раньше action молча выходил, а контроллер отвечал 200 «деактивирован»,
     * что было ложным успехом.
     *
     * @return array{success: bool, message: string}
     */
    public function execute(User $user, User $admin, ?string $reason = null): array
    {
        if ($user->isStaff()) {
            return ['success' => false, 'message' => 'Нельзя деактивировать сотрудника (админа/модератора)'];
        }

        $before = [
            'status' => $user->getOriginal('status'),
            'premium_expires_at' => $user->getOriginal('premium_expires_at'),
            'vip_expires_at' => $user->getOriginal('vip_expires_at'),
            'deleted_at' => $user->getOriginal('deleted_at')
        ];

        DB::transaction(function () use ($user, $admin, $before, $reason) {
            $user->update([
                'status' => 'deactivated',
                // Чистим ОБА флага подписки: раньше vip_expires_at оставался
                // висеть у деактивированного юзера, и scope vip() продолжал
                // считать его VIP-шником в статистике.
                'premium_expires_at' => null,
                'vip_expires_at' => null,
            ]);


            $user->delete();

            $after = [
                'status' => 'deactivated',
                'deleted_at' => now()->toDateTimeString(),
                'context' => [
                    'user_id' => $user->id,
                    'user_name' => $user->name,
                    'admin_id' => $admin->id,
                    'reason' => $reason ?: 'Причина не указана'
                ]
            ];

            AdminLog::record('user.delete', $user, $admin, $before, $after, participants: [$user->id]);
        });

        // ФИКС: Отправляем уведомление ТОЛЬКО после успешного коммита транзакции!
        try {
            $user->notify(new UserDeleted());
        } catch (\Exception $e) {
            Log::error('Не удалось отправить уведомление об удалении аккаунта: ' . $e->getMessage());
        }

        return ['success' => true, 'message' => "Пользователь {$user->name} деактивирован"];
    }

    public function restore(User $user, User $admin): void
    {
        if (!$user->trashed()) return;

        $before = [
            'status' => $user->getOriginal('status'),
            'deleted_at' => $user->getOriginal('deleted_at')
        ];

        $user->restore();
        $user->update(['status' => 'active']);
        // ФИКС: Убрали $user->refresh()

        $after = [
            'status' => 'active',
            'restored_at' => now()->toDateTimeString(),
            'context' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'admin_id' => $admin->id
            ]
        ];

        AdminLog::record('user.restore', $user, $admin, $before, $after, participants: [$user->id]);
    }
}
