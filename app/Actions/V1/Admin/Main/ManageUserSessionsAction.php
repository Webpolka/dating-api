<?php

namespace App\Actions\Admin\Users;

use App\Support\UserAgentParser;
use App\Models\AdminLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ManageUserSessionsAction
{
    /**
     * Список активных сессий пользователя (готовый для UI).
     *
     * @return array<int, array{
     *     id: string,
     *     ip: string|null,
     *     device: string,
     *     browser: string,
     *     is_mobile: bool,
     *     last_activity: string,
     *     exact_time: string
     * }>
     */
    public function getUserSessions(User $user): array
    {
        $sessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->get();

        return $sessions->map(function ($session) {
            $ua = UserAgentParser::parse($session->user_agent);

            // tz-aware: используем app timezone (Asia/Moscow по config/app.php)
            $time = Carbon::createFromTimestamp($session->last_activity, config('app.timezone'));

            return [
                'id'            => $session->id,
                'ip'            => $session->ip_address,
                'device'        => $ua['device'],
                'browser'       => $ua['browser'],
                'is_mobile'     => $ua['is_mobile'],
                'last_activity' => $time->diffForHumans(),
                'exact_time'    => $time->format('d.m.Y H:i:s'),
            ];
        })->values()->all();
    }

    /**
     * Завершить конкретную сессию юзера.
     */
    public function killSession(User $user, string $sessionId, User $admin): bool
    {
        $session = DB::table('sessions')
            ->where('id', $sessionId)
            ->where('user_id', $user->id) // Защита: чтобы не убили чужую сессию
            ->first();

        if (!$session) {
            return false;
        }

        // Транзакция гарантирует: сессия убита И лог записан. Иначе откат.
        DB::transaction(function () use ($session, $user, $admin) {
            DB::table('sessions')->where('id', $session->id)->delete();

            $after = [
                'status'      => 'killed',
                'killed_by'   => $admin->id,
                'killed_at'   => now()->toDateTimeString(),
                'context'     => [
                    'user_id'     => $user->id,
                    'session_id' => $session->id,
                    'ip_address' => $session->ip_address,
                    'admin_id'   => $admin->id,
                ],
            ];

            AdminLog::record('user.session_killed', $user, $admin, null, $after, participants: [$user->id]);
        });

        return true;
    }

    /**
     * Завершить ВСЕ сессии юзера (кнопка паники).
     *
     * @return int Количество завершённых сессий
     */
    public function killAllSessions(User $user, User $admin): int
    {
        $deletedCount = 0;

        DB::transaction(function () use ($user, $admin, &$deletedCount) {
            // delete() возвращает количество удалённых строк — не нужен отдельный COUNT(*).
            $deletedCount = DB::table('sessions')->where('user_id', $user->id)->delete();

            if ($deletedCount > 0) {
                $after = [
                    'status'     => 'all_killed',
                    'killed_by'  => $admin->id,
                    'killed_at'  => now()->toDateTimeString(),
                    'context'    => [
                        'user_id'      => $user->id,
                        'admin_id'     => $admin->id,
                        'killed_count' => $deletedCount,
                    ],
                ];

                AdminLog::record('user.all_sessions_killed', $user, $admin, null, $after, participants: [$user->id]);
            }
        });

        return $deletedCount;
    }
}
