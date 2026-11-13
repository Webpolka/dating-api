<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * ============================================================
 * RATE LIMIT PROVIDER
 * ============================================================
 * Все кастомные rate limiters приложения в одном месте.
 *
 * Используются через middleware 'throttle:<name>' в routes:
 *   ->middleware('throttle:admin-login')
 *
 * Вынесено из AppServiceProvider, чтобы тот содержал только
 * регистрацию observers/макросов, а лимиты (которых много и
 * они растут с каждым разделом админки) жили отдельно.
 *
 * Добавляя новый лимитер — сохрани стиль: именованная группа
 * в формате '<область>-<сущность>-actions', комментарий с
 * объяснением WHY (почему именно такой лимит), JSON-ответ 429.
 * ============================================================
 */
class RateLimitServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerRateLimiters();
    }

    private function registerRateLimiters(): void
    {
        // ------------------------------------------------------------
        // ADMIN LOGIN: защита от брутфорса пароля админа
        // ------------------------------------------------------------
        // Лимит: RATE_LIMIT_LOGIN_PER_MIN (по умолчанию 5) попыток
        // в минуту на связку (email + ip).
        //
        // Почему email + ip, а не только ip:
        //  - если админка за корпоративным VPN/NAT, все админы под
        //    одним IP — лимит только по IP заблокировал бы их всех;
        //  - атакующий с одного IP может брутфорсить разные email'ы —
        //    для каждого получит отдельный лимит в RATE_LIMIT_LOGIN_PER_MIN.
        //
        // Если атакующий брутфорсит ОДИН email с РАЗНЫХ IP (distributed
        // attack) — текущий лимит его полностью не остановит, но
        // усложнит (нужно много прокси). Для усиления можно добавить
        // второй лимiter по email (см. комментарий ниже).
        RateLimiter::for('admin-login', function (Request $request) {
            $email = strtolower((string) $request->input('email', ''));
            $maxAttempts = (int) config('app.rate_limit_login_per_min', 5);

            return Limit::perMinute($maxAttempts)
                ->by($email . '|' . $request->ip())
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много попыток входа. Попробуйте через минуту.',
                    ], 429);
                });
        });

        // ------------------------------------------------------------
        // ОПЦИОНАЛЬНО: второй слой защиты по email (для distributed attack)
        // ------------------------------------------------------------
        // Раскомментируй, если нужна защита от брутфорса одного email
        // с разных IP. Лимит: 10 попыток в 15 минут на email.
        // После 10 неудач аккаунт "софт-лочится" на 15 минут.
        //
        RateLimiter::for('admin-login-by-email', function (Request $request) {
            $email = strtolower((string) $request->input('email', ''));

            return Limit::perMinutes(15, 10)
                ->by('email:' . $email)
                ->response(function () {
                    return response()->json([
                        'message' => 'Аккаунт временно заблокирован из-за множества неудачных попыток. Попробуйте через 15 минут.',
                    ], 429);
                });
        });

        // ------------------------------------------------------------
        // ADMIN TRANSACTION ACTIONS: throttle для sync/refund
        // ------------------------------------------------------------
        // Лимит: 5 действий в минуту на (transaction_id + admin_id).
        //
        // Защита от:
        //  - случайного двойного/тройного клика по кнопке sync/refund
        //  - намеренного спама (например, админ злится на банк и кликает)
        //
        // Не блокирует разных админов, работающих с одной транзакцией —
        // у каждого свой лимит по admin_id. Но 5 действий в минуту от
        // одного админа на одну транзакцию — это уже подозрительно.
        //
        // 429 отдаётся как JSON — фронт показывает toast.
        RateLimiter::for('admin-transaction-actions', function (Request $request) {
            // Извлекаем id транзакции из URL: /api/admin/transactions/{id}/sync
            $path = $request->path();
            $transactionId = 'unknown';
            if (preg_match('#transactions/(\d+)#', $path, $m)) {
                $transactionId = $m[1];
            }

            $adminId = $request->user()?->id ?? 'guest';

            return Limit::perMinute(5)
                ->by("tx:{$transactionId}|admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий с этой транзакцией. Подождите минуту.',
                    ], 429);
                });
        });

        // ------------------------------------------------------------
        // ADMIN STAFF ACTIONS: throttle для promote/demote
        // ------------------------------------------------------------
        // Лимит: 10 действий в минуту на admin_id.
        //
        // Защита от случайного/намеренного спама кликами по кнопкам
        // "Повысить" / "Уволить". 10 в минуту — достаточно для
        // нормальной работы (например, назначить 5 модераторов),
        // но останавливает ботов/злоумышленников.
        //
        // Не блокирует разных админов — у каждого свой лимит.
        RateLimiter::for('admin-staff-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';

            return Limit::perMinute(10)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий с ролями. Подождите минуту.',
                    ], 429);
                });
        });


        // ------------------------------------------------------------
        // ADMIN BROADCAST ACTIONS: throttle для sendNow
        // ------------------------------------------------------------
        // Лимит: 5 в минуту на admin_id. Защита от двойного клика по
        // "Отправить сейчас" — atomic update в sendNow() защитит от
        // дублей, но 5 запросов в минуту на одну рассылку — уже подозрительно.
        RateLimiter::for('admin-broadcast-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';
            return Limit::perMinute(5)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий с рассылками. Подождите минуту.',
                    ], 429);
                });
        });


        // ------------------------------------------------------------
        // ADMIN GEO ACTIONS: throttle для toggle-registration/toggle-feed
        // ------------------------------------------------------------
        // Лимит: 20 в минуту на admin_id. Блокировка региона — опасная
        // операция, но 20 в минуту достаточно для работы (например,
        // заблокировать 10 стран от регистрации). Останавливает спам.
        RateLimiter::for('admin-geo-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';
            return Limit::perMinute(20)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий с локациями. Подождите минуту.',
                    ], 429);
                });
        });


        // ------------------------------------------------------------
        // ADMIN MEDIA ACTIONS: throttle для upload
        // ------------------------------------------------------------
        // Лимит: 10 в минуту на admin_id. Загрузка — тяжёлая операция
        // (файлы на диск + Job на нарезку). 10 запросов/мин — достаточно
        // для пакетной загрузки, останавливает спам.
        RateLimiter::for('admin-media-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';
            return Limit::perMinute(10)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много загрузок. Подождите минуту.',
                    ], 429);
                });
        });


        // ------------------------------------------------------------
        // ADMIN BLOG ACTIONS: throttle для setFeatured
        // ------------------------------------------------------------
        // Лимит: 10 в минуту на admin_id. Закрепление поста — редкая
        // операция, 10 в минуту достаточно. Защита от race condition
        // при двойном клике (хотя backend уже обёрнут в транзакцию).
        RateLimiter::for('admin-blog-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';
            return Limit::perMinute(10)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий с постами. Подождите минуту.',
                    ], 429);
                });
        });


        // ------------------------------------------------------------
        // ADMIN PAGES ACTIONS: throttle для toggle/duplicate
        // ------------------------------------------------------------
        // Лимит: 20 в минуту на admin_id. Достаточно для пакетной работы,
        // останавливает спам.
        RateLimiter::for('admin-pages-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';
            return Limit::perMinute(20)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий со страницами. Подождите минуту.',
                    ], 429);
                });
        });


        // ------------------------------------------------------------
        // ADMIN SETTINGS ACTIONS: throttle для save/reset
        // ------------------------------------------------------------
        // Лимит: 10 в минуту на admin_id. Настройки редко меняются,
        // 10 в минуту достаточно. Защита от случайного спама.
        RateLimiter::for('admin-settings-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';
            return Limit::perMinute(10)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий с настройками. Подождите минуту.',
                    ], 429);
                });
        });


        // ------------------------------------------------------------
        // ADMIN LOGS ACTIONS: throttle для clear
        // ------------------------------------------------------------
        // Лимит: 5 в минуту на admin_id. Очистка логов — редкая операция,
        // 5 в минуту достаточно. Защита от случайного спама.
        RateLimiter::for('admin-logs-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';
            return Limit::perMinute(5)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий с логами. Подождите минуту.',
                    ], 429);
                });
        });


        // ------------------------------------------------------------
        // ADMIN SUPPORT ACTIONS: throttle для sendMessage
        // ------------------------------------------------------------
        // Лимит: 30 в минуту на admin_id. Саппорт активно пишет,
        // 30 в минуту достаточно. Защита от спама.
        RateLimiter::for('admin-support-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';
            return Limit::perMinute(30)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много сообщений. Подождите минуту.',
                    ], 429);
                });
        });

        // ------------------------------------------------------------
        // ADMIN TEMPLATES ACTIONS: throttle для store/update/destroy
        // ------------------------------------------------------------
        RateLimiter::for('admin-templates-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';
            return Limit::perMinute(30)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий с шаблонами. Подождите минуту.',
                    ], 429);
                });
        });

        // ------------------------------------------------------------
        // ADMIN CHAT ACTIONS: throttle для toggle-lock
        // ------------------------------------------------------------
        // Лимит: 10 действий в минуту на (chat_id + admin_id).
        //
        // Защита от:
        //  - случайного дабл-клика по кнопке блокировки
        //  - намеренного спама (каждый клик создаёт системное сообщение
        //    + запись в AdminLog, что засоряет чат и журнал)
        //
        // 10/мин — достаточно для нормальной работы (lock → unlock → lock
        // для теста), но отсекает спам. Не блокирует разных админов
        // (у каждого свой лимит по admin_id).
        //
        // 429 отдаётся как JSON — фронт показывает toast.
        RateLimiter::for('admin-chat-actions', function (Request $request) {
            // Извлекаем id чата из URL: /api/admin/chats/{chatId}/toggle-lock
            $path    = $request->path();
            $chatId  = 'unknown';
            if (preg_match('#chats/(\d+)#', $path, $m)) {
                $chatId = $m[1];
            }

            $adminId = $request->user()?->id ?? 'guest';

            return Limit::perMinute(10)
                ->by("chat:{$chatId}|admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий с этим чатом. Подождите минуту.',
                    ], 429);
                });
        });


        // ------------------------------------------------------------
        // ADMIN STOP WORDS ACTIONS: throttle для storeBulk/applyBulk/update/toggleActive/destroy
        // ------------------------------------------------------------
        // Лимит: 30 действий в минуту на admin_id.
        // Bulk-операции (storeBulk может создать 100 слов за один запрос)
        // + update/toggle/delete отдельных слов — 30 в минуту достаточно
        // для нормальной работы, останавливает спам.
        RateLimiter::for('admin-stop-words-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';
            return Limit::perMinute(30)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий со стоп-словами. Подождите минуту.',
                    ], 429);
                });
        });



        // ------------------------------------------------------------
        // ADMIN PHOTO ACTIONS: throttle для approve/reject/setPrimary/softDelete/restore/destroy
        // + bulk approve-all/reject-all
        // ------------------------------------------------------------
        // Лимит: 60 действий в минуту на admin_id.
        // Модерация фото — активная работа, 60 в минуту = 1 действие/сек.
        // Достаточно для нормальной работы, останавливает спам/DoS.
        // Каждый approve dispatch'ит ProcessApprovedPhoto job (тяжёлая
        // обработка) — throttle предотвращает flood очереди.
        RateLimiter::for('admin-photo-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';
            return Limit::perMinute(60)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий с фото. Подождите минуту.',
                    ], 429);
                });
        });


        // ------------------------------------------------------------
        // ADMIN PHOTO COMMENT ACTIONS: throttle для approve/reject/spam/softDelete/restore/destroy
        // + bulk approve-all/reject-all/approve-remaining/reject-remaining
        // ------------------------------------------------------------
        // Лимит: 60 действий в минуту — как photos. Модерация комментариев
        // активная работа, 60/мин = 1 действие/сек.
        RateLimiter::for('admin-photo-comment-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';
            return Limit::perMinute(60)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий с комментариями. Подождите минуту.',
                    ], 429);
                });
        });


        // ------------------------------------------------------------
        // ADMIN DATING ACTIONS: throttle для destroySwipe/destroyMatch/restoreMatch
        // ------------------------------------------------------------
        // Лимит: 30 действий в минуту. Dating moderation — менее активная
        // чем photos/comments, 30/мин достаточно.
        RateLimiter::for('admin-dating-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';
            return Limit::perMinute(30)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий с дейтинг-данными. Подождите минуту.',
                    ], 429);
                });
        });



        // ------------------------------------------------------------
        // ADMIN REPORT ACTIONS: throttle для resolve/reject/reopen/toggleBan/rejectPhoto
        // ------------------------------------------------------------
        // Лимит: 30 действий в минуту. Reports moderation — менее активная
        // чем photos, 30/мин достаточно.
        RateLimiter::for('admin-report-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';
            return Limit::perMinute(30)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий с жалобами. Подождите минуту.',
                    ], 429);
                });
        });



        // ------------------------------------------------------------
        // ADMIN BLOCK SIGNAL ACTIONS: throttle для ban (shadow/temp/permanent)
        // ------------------------------------------------------------
        // Лимит: 30 действий в минуту — на уровне report-actions,
        // т.к. банов по сигналам обычно немного, но это destructive.
        // По admin_id (не по user_id) — разные админы не блокируют друг друга.
        RateLimiter::for('admin-block-signal-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';
            return Limit::perMinute(30)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий с сигналами блокировок. Подождите минуту.',
                    ], 429);
                });
        });

        // ------------------------------------------------------------
        // ADMIN FRAUD ACTIONS: throttle для resolve-and-ban / resolve-with-warning / false-positive
        // ------------------------------------------------------------
        // Лимит: 30 действий в минуту — как report/block-signal.
        // По admin_id (не по alert_id) — разные админы не блокируют друг друга.
        // Защита от случайного дабл-клика по «Закрыть алерт» + от намеренного
        // спама (каждый клик = AdminLog + возможно бан / system message в чат).
        RateLimiter::for('admin-fraud-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';
            return Limit::perMinute(30)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий с антифрод-алертами. Подождите минуту.',
                    ], 429);
                });
        });

        // ------------------------------------------------------------
        // ADMIN DIARY ACTIONS: throttle для постов + рубрик
        // ------------------------------------------------------------
        // Лимит: 30 действий в минуту — стандарт для moderation-секций.
        // Покрывает: approve/reject/unpublish/settings/soft-delete/restore/force-delete
        // + store/update/destroy/toggle для рубрик.
        // По admin_id — разные админы не блокируют друг друга.
        RateLimiter::for('admin-diary-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';
            return Limit::perMinute(30)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий с дневниками. Подождите минуту.',
                    ], 429);
                });
        });

        // ------------------------------------------------------------
        // ADMIN USER ACTIONS: throttle для постов + рубрик
        // ------------------------------------------------------------
        // Лимит: 30 действий в минуту — стандарт для moderation-секций.       
        // По admin_id — разные админы не блокируют друг друга.
        RateLimiter::for('admin-user-actions', function (Request $request) {
            $adminId = $request->user()?->id ?? 'guest';
            return Limit::perMinute(30)
                ->by("admin:{$adminId}")
                ->response(function () {
                    return response()->json([
                        'message' => 'Слишком много действий с пользователями. Подождите минуту.',
                    ], 429);
                });
        });
    }
}
