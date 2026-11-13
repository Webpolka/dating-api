<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Auth\AdminLoginRequest;
use App\Models\AdminLog;
use App\Models\User;
use App\Services\V1\AdminAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends AdminController
{
    public function __construct(
        private readonly AdminAuthService $authService,
    ) {}

    public function login(AdminLoginRequest $request)
    {
        $credentials = $request->validated();

        // ------------------------------------------------------------
        // ПОДГОТОВКА ДАННЫХ ДЛЯ ЛОГИРОВАНИЯ
        // ------------------------------------------------------------
        // Ищем юзера по email ДО Auth::attempt. Зачем:
        //   - Если email не существует — пишем user_id=null в UserAuthLog
        //     (атака на выдуманный админский ящик всё равно логируется).
        //   - Если email существует, но пароль неверный — мы знаем user_id
        //     и запишем его в лог (видно, какой именно аккаунт брутфорсят).
        //
        // ВАЖНО: используем RAW email (как ввёл юзер), без lowercase —
        // это соответствует поведению Auth::attempt, который тоже не
        // нормализует email перед lookup'ом в БД. Если в БД email хранится
        // в смешанном регистре (Admin@X.com), только точное совпадение
        // найдёт юзера. Для логов ниже нормализуем к lowercase отдельно —
        // чтобы в audit-записях была консистентность с throttle
        // (admin-login-by-email тоже lowercase'ит email).
        $emailRaw  = (string) $credentials['email'];
        $emailLog  = strtolower($emailRaw);  // для записей в логи
        $user      = User::where('email', $emailRaw)->first();

        // ------------------------------------------------------------
        // ПОПЫТКА АВТОРИЗАЦИИ
        // ------------------------------------------------------------
        if (!Auth::guard('web')->attempt($credentials)) {
            // Неудача: логируем в user_auth_logs + admin_logs + проверяем брутфорс.
            // Метод сам решает, нужен ли alert auth.brute_force_detected.
            // В логи передаём emailLog (lowercase) — для консистентности с throttle.
            $this->authService->logFailure($request, $emailLog, $user);

            throw ValidationException::withMessages([
                'email' => ['Неверный email или пароль.'],
            ]);
        }

        // Успех: Auth::attempt гарантированно нашёл юзера (иначе был бы throw выше).
        // Поэтому $user — точно не null. Если вдруг lookup выше его не нашёл
        // (что невозможно при успешном auth, но на всякий случай), берём ID из guard.
        $ip = $request->ip();
        $this->authService->logSuccess(
            $user?->id ?? Auth::guard('web')->id(),
            $ip,
            $request->userAgent() ?? '',
        );

        /** @var User $user */
        $user = Auth::guard('web')->user();

        // ------------------------------------------------------------
        // ПОЛИТИКА ДОСТУПА (Вариант B):
        // ------------------------------------------------------------
        // Founder — это просто admin с защитой от случайной потери
        // доступа. Чтобы войти в админку, founder должен иметь
        // role=admin (или moderator/support). Founder с role=user
        // НЕ имеет автоматического доступа — это устраняет скрытый
        // бэкдор при компрометации аккаунта.
        //
        // Если founder'у случайно понизили роль — восстановить можно
        // только напрямую через БД (tinker/SQL), а не через админку.
        // ------------------------------------------------------------
        $isStaff = $user->isStaff();

        if (!$isStaff) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => ['Доступ запрещён. Эта учётная запись не имеет прав администратора.'],
            ]);
        }

        if ($user->isBanned()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => ['Аккаунт заблокирован. Обратитесь к владельцу.'],
            ]);
        }

        $request->session()->regenerate();

        // Логируем успешный вход в AdminLog (для аудита).
        // before=null, after=null — для auth-событий нет диффа данных,
        // записывается только сам факт: кто, когда, с какого IP.
        AdminLog::record(AdminLog::ACTION_AUTH_LOGIN, $user, $user);

        return response()->json([
            'message' => 'Успешный вход.',
            'user'    => $this->formatUser($user),
        ]);
    }

    /**
     * GET /api/admin/me
     *
     * Возвращает authenticated + user (или 401).
     * Layout (Server Component) вызывает это, чтобы проверить сессию.
     */
    public function me(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'authenticated' => false,
            ], 401);
        }

        return response()->json([
            'authenticated' => true,
            'user' => $this->formatUser($user),
        ]);
    }

    public function logout(Request $request)
    {
        // Сохраняем user до logout — после Auth::logout() $request->user()
        // вернёт null, и логировать будет некого.
        $user = $request->user();

        // Логируем выход в AdminLog (для аудита).
        if ($user) {
            AdminLog::record(AdminLog::ACTION_AUTH_LOGOUT, $user, $user);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'message' => 'Успешный выход.',
        ]);
    }

    /**
     * POST /api/admin/logout-everywhere
     *
     * Завершает ВСЕ сессии текущего юзера, КРОМЕ текущей.
     *
     * Сценарии:
     *   - Админ подозревает компрометацию (хочет добить старые сессии,
     *     которые могли остаться на других устройствах).
     *   - Админ потерял девайс, с которого логинился в админку.
     *   - Параноидальный режим: периодически чистить «зависшие» сессии.
     *
     * ВАЖНО: auto-invalidation при смене пароля НЕ работает, потому что
     * AuthenticateSession middleware в Laravel 13 deprecated/удалён.
     * Если нужна АВТО-инвалидация при смене пароля — реализуй это в
     * будущем контроллере смены пароля через Auth::logoutOtherDevices($password)
     * или в User observer (если password hash изменился → DB::table('sessions')
     * ->where('user_id', $user->id)->delete()).
     *
     * Текущая сессия остаётся живой, чтобы админ не выкинул сам себя.
     */
    public function logoutEverywhere(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Неавторизован.'], 401);
        }

        $currentSessionId = $request->session()->getId();

        // Удаляем все сессии текущего юзера, кроме текущей.
        // Таблица sessions — стандартная Laravel-схема (см. миграцию
        // 0001_01_01_000004_create_users_table.php, блок "sessions").
        // user_id в sessions — foreignId, проиндексирован.
        $deleted = DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();

        AdminLog::record(
            AdminLog::ACTION_AUTH_LOGOUT_EVERYWHERE,
            $user,
            $user,
            null,
            [
                'context' => [
                    'sessions_deleted'    => $deleted,
                    'kept_current_session' => true,
                ],
            ],
        );

        return response()->json([
            'message'           => $deleted > 0
                ? "Завершено сессий: {$deleted}. Текущая сессия сохранена."
                : 'Других активных сессий не найдено.',
            'sessions_deleted'  => $deleted,
        ]);
    }

    private function formatUser(User $user): array
    {
        return [
            'id'          => $user->id,
            'name'        => $user->name,
            'email'       => $user->email,
            'role'        => $user->role,
            'is_verified' => $user->is_verified,
            'avatar_url'  => $user->avatar_url,
            'is_founder'  => in_array($user->id, config('app.founders', []), true),
        ];
    }
}
