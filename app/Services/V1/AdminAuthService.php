<?php

namespace App\Services\Admin;

use App\Models\AdminLog;
use App\Models\User;
use App\Models\UserAuthLog;
use Illuminate\Http\Request;

/**
 * Сервис авторизации для админ-панели.
 *
 * Отвечает за две вещи:
 *   1. Логирование ВСЕХ попыток входа (success + failure) в user_auth_logs.
 *      Раньше туда писались только тестовые данные через seeder — реальный
 *      логин ничего не записывал. Теперь каждая попытка фиксируется с
 *      IP, User-Agent и timestamp.
 *
 *   2. Детекция брутфорса по PATTERNS, которые не видят существующие
 *      throttle'ы (admin-login 5/min, admin-login-by-email 10/15min):
 *        - Slow distributed attack (1 запрос/IP/день — throttle не триггерится)
 *        - Email-scanning (один IP бьёт много выдуманных админских ящиков)
 *      При срабатывании порога — пишет событие auth.brute_force_detected
 *      в AdminLog. НЕ блокирует вход (блокировка — задача throttle'ов),
 *      даёт админам ВИДИМОСТЬ того, что происходит.
 *
 * Пороги вынесены в .env (BRUTE_FORCE_THRESHOLD_*, BRUTE_FORCE_WINDOW_MINUTES),
 * чтобы менять без правки кода.
 */
class AdminAuthService
{
    /**
     * Зафиксировать успешный вход.
     *
     * Пишет запись в user_auth_logs с is_successful=true.
     * В AdminLog не пишем — это делает AuthController через AdminLog::record('auth.login', …),
     * чтобы сохранить существующий формат аудит-лога.
     */
    public function logSuccess(int $userId, string $ip, string $userAgent): void
    {
        UserAuthLog::log($userId, $ip, $userAgent, null, null, true);
    }

    /**
     * Зафиксировать неудачную попытку входа + проверить пороги брутфорса.
     *
     * Делает три вещи:
     *   1. Пишет в user_auth_logs (is_successful=false) — низкоуровневый аудит.
     *   2. Пишет в admin_logs action='auth.login_failed' — высокоуровневый
     *      аудит, виден в UI audit-лога рядом с auth.login.
     *   3. Если превышен любой из порогов брутфорса — пишет ДОПОЛНИТЕЛЬНО
     *      auth.brute_force_detected с деталями (какой порог, сколько попыток).
     *
     * @param Request     $request  Текущий запрос (для IP, UA)
     * @param string      $email    Email, по которому шла попытка (lowercased)
     * @param User|null   $user     Найденный юзер или null (если email не существует)
     */
    public function logFailure(Request $request, string $email, ?User $user): void
    {
        $ip = $request->ip();
        $userAgent = $request->userAgent() ?? '';
        $userId = $user?->id;

        // 1. Низкоуровневый аудит — всегда
        UserAuthLog::log($userId, $ip, $userAgent, null, null, false);

        // 2. Высокоуровневый аудит — всегда (виден в audit UI)
        //    model=$user (если найден) — в UI покажет ссылку на профиль юзера.
        //    admin=null — пытался войти неаутентифицированный (или злоумышленник).
        AdminLog::record(
            AdminLog::ACTION_AUTH_LOGIN_FAILED,
            $user,
            null,
            null,
            [
                'reason'  => 'invalid_credentials',
                'context' => [
                    'email'         => $email,
                    'ip'            => $ip,
                    'user_id'       => $userId,
                    'user_agent'    => $this->truncateUserAgent($userAgent),
                ],
            ],
        );

        // 3. Детекция брутфорса — доп. событие в AdminLog при превышении порога.
        //    Пишем ОТДЕЛЬНО от auth.login_failed, чтобы в audit UI можно было
        //    фильтровать только «тревожные» события. Не блокируем вход —
        //    блокировку делают throttle'ы.
        $this->detectAndLogBruteForce($request, $email, $user);
    }

    /**
     * Проверить пороги брутфорса и при превышении записать событие.
     *
     * Три порога (все вынесены в env):
     *   - IP + email: N неудачных попыток с одного IP по одному email
     *     → классический брутфорс одного аккаунта с одного источника
     *   - email only: M неудачных попыток со ВСЕХ IP по одному email
     *     → distributed attack (атакующий крутит прокси/ботнет)
     *   - IP only: K неудачных попыток с одного IP по ЛЮБЫМ email
     *     → сканирование (атакующий перебирает выдуманные админские ящики)
     *
     * Если превышено несколько порогов — пишем ОДНО событие со всеми деталями
     * (чтобы не плодить дубликаты в аудит-логе).
     */
    private function detectAndLogBruteForce(Request $request, string $email, ?User $user): void
    {
        $ip = $request->ip();
        $minutes = (int) config('app.brute_force_window_minutes', 15);
        $thresholdIpEmail = (int) config('app.brute_force_threshold_ip_email', 10);
        $thresholdEmail = (int) config('app.brute_force_threshold_email', 20);
        $thresholdIpScan = (int) config('app.brute_force_threshold_ip_scan', 30);

        $byIpEmail = $user
            ? UserAuthLog::countRecentFailuresByUserAndIp($user->id, $ip, $minutes)
            : 0;
        $byEmail = $user
            ? UserAuthLog::countRecentFailuresByUser($user->id, $minutes)
            : 0;
        $byIpScan = UserAuthLog::countRecentFailuresByIp($ip, $minutes);

        $triggered = [];
        if ($user && $byIpEmail >= $thresholdIpEmail) {
            $triggered['ip_email'] = [
                'count'      => $byIpEmail,
                'threshold'  => $thresholdIpEmail,
                'description' => 'Брутфорс одного аккаунта с одного IP',
            ];
        }
        if ($user && $byEmail >= $thresholdEmail) {
            $triggered['email'] = [
                'count'      => $byEmail,
                'threshold'  => $thresholdEmail,
                'description' => 'Distributed attack (много IP, один email)',
            ];
        }
        if ($byIpScan >= $thresholdIpScan) {
            $triggered['ip_scan'] = [
                'count'      => $byIpScan,
                'threshold'  => $thresholdIpScan,
                'description' => 'Сканирование email-базы с одного IP',
            ];
        }

        if (empty($triggered)) {
            return;
        }

        AdminLog::record(
            AdminLog::ACTION_AUTH_BRUTE_FORCE,
            null,   // model — нет конкретной сущности
            null,   // admin — анонимная попытка
            null,
            [
                'context' => [
                    'email'             => $email,
                    'ip'                => $ip,
                    'user_id'           => $user?->id,
                    'window_minutes'    => $minutes,
                    'triggered_rules'   => $triggered,
                ],
            ],
        );
    }

    /**
     * Обрезать User-Agent до 500 символов — защита от переполнения.
     * В схеме поле text, но осмысленные UA < 500 символов; боты могут
     * слать килобайты мусора.
     */
    private function truncateUserAgent(string $ua): string
    {
        return mb_substr($ua, 0, 500);
    }
}
