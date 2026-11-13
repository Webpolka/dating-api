<?php

namespace App\Actions\Admin\Moderation;

use App\Models\AdminLog;
use App\Models\Swipe;
use App\Models\User;
use App\Models\UserMatch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ModerateDatingAction
{
    /**
     * Удаление свайпа администратором.
     * Если это был лайк/суперлайк — разрывает связанный мэтч (если он есть).
     */
    public function destroySwipe(Swipe $swipe, User $admin): void
    {
        $before = [
            'type' => $swipe->getOriginal('type'),
            'rewinded_at' => $swipe->getOriginal('rewinded_at')
        ];

        $unmatchedMatch = false;

        DB::transaction(function () use ($swipe, $admin, $before, &$unmatchedMatch) {
            if ($swipe->isPositive() && $swipe->user_id && $swipe->target_user_id) {
                $u1 = min($swipe->user_id, $swipe->target_user_id);
                $u2 = max($swipe->user_id, $swipe->target_user_id);

                $affected = UserMatch::where('user1_id', $u1)->where('user2_id', $u2)->update([
                    'status' => UserMatch::STATUS_UNMATCHED, // ФИКС: Константа
                    'unmatched_by' => $admin->id,
                    'unmatched_at' => now(),
                ]);

                if ($affected > 0) {
                    $unmatchedMatch = true;
                }
            }

            $after = [
                'status' => 'destroyed',
                'deleted_by' => $admin->id,
                'deleted_at' => now()->toDateTimeString(),
                'match_unmatched' => $unmatchedMatch,
                'context' => [
                    'user_id' => $swipe->user_id,
                    'target_user_id' => $swipe->target_user_id
                ]
            ];

            $participants = array_filter([$swipe->user_id, $swipe->target_user_id]);

            AdminLog::record('swipe.destroy', $swipe, $admin, $before, $after, participants: $participants);

            $swipe->delete();
        });

        Cache::forget('dating_admin_stats');
    }

    /**
     * Разрыв мэтча администратором (Принудительный Unmatch).
     */
    public function destroyMatch(UserMatch $match, User $admin): void
    {
        $before = ['status' => $match->getOriginal('status')];

        DB::transaction(function () use ($match, $admin, $before) {
            $match->update([
                'status' => UserMatch::STATUS_UNMATCHED, // ФИКС: Константа
                'unmatched_by' => $admin->id,
                'unmatched_at' => now(),
            ]);

            // ФИКС: Убрали $match->refresh(), update() уже обновил атрибуты

            $after = [
                'status' => UserMatch::STATUS_UNMATCHED,
                'unmatched_by' => $admin->id,
                'unmatched_at' => now()->toDateTimeString(),
                'context' => [
                    'user1_id' => $match->user1_id,
                    'user2_id' => $match->user2_id
                ]
            ];

            $participants = array_filter([$match->user1_id, $match->user2_id]);

            AdminLog::record('match.unmatch', $match, $admin, $before, $after, participants: $participants);
        });

        Cache::forget('dating_admin_stats');
    }

    /**
     * Восстановление мэтча администратором.
     */
    public function restoreMatch(UserMatch $match, User $admin): void
    {
        // M4: Idempotency — если match уже active, early-return без duplicate AdminLog.
        if ($match->status === UserMatch::STATUS_ACTIVE) {
            return;
        }

        $before = ['status' => $match->getOriginal('status')];

        // ФИКС: Обернули в транзакцию для надежности
        DB::transaction(function () use ($match, $admin, $before) {
            $match->update([
                'status' => UserMatch::STATUS_ACTIVE, // ФИКС: Константа
                'unmatched_by' => null,
                'unmatched_at' => null,
            ]);

            // ФИКС: Убрали $match->refresh()

            $after = [
                'status' => UserMatch::STATUS_ACTIVE,
                'restored_by' => $admin->id,
                'restored_at' => now()->toDateTimeString(),
                'context' => [
                    'user1_id' => $match->user1_id,
                    'user2_id' => $match->user2_id
                ]
            ];

            $participants = array_filter([$match->user1_id, $match->user2_id]);

            AdminLog::record('match.restore', $match, $admin, $before, $after, participants: $participants);
        });

        Cache::forget('dating_admin_stats');
    }
}
