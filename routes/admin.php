<?php

use App\Http\Controllers\Api\V1\Admin\Auth\AuthController;
use App\Http\Controllers\Api\V1\Admin\Main\DashboardController;
use App\Http\Controllers\Api\V1\Admin\System\StaffController;
use App\Http\Controllers\Api\V1\Admin\System\LogController;
use App\Http\Controllers\Api\V1\Admin\System\SystemController;
use App\Http\Controllers\Api\V1\Admin\System\SettingController;
use App\Http\Controllers\Api\V1\Admin\System\PageController;

use App\Http\Controllers\Api\V1\Admin\System\MediaController;
use App\Http\Controllers\Api\V1\Admin\System\BlogCategoryController;
use App\Http\Controllers\Api\V1\Admin\System\BlogPostController;
use App\Http\Controllers\Api\V1\Admin\System\GeoIPLocationController;
use App\Http\Controllers\Api\V1\Admin\System\AdminLogController;
use App\Http\Controllers\Api\V1\Admin\System\BroadcastController;
use App\Http\Controllers\Api\V1\Admin\Security\FraudAlertController;
use App\Http\Controllers\Api\V1\Admin\Security\BlockSignalController;
use App\Http\Controllers\Api\V1\Admin\Communication\StopWordController;
use App\Http\Controllers\Api\V1\Admin\Communication\ChatController;
use App\Http\Controllers\Api\V1\Admin\Communication\SupportChatController;
use App\Http\Controllers\Api\V1\Admin\Communication\SupportTemplateController;
use App\Http\Controllers\Api\V1\Admin\Finance\GiftController;
use App\Http\Controllers\Api\V1\Admin\Finance\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\Admin\Finance\TransactionController;
use App\Http\Controllers\Api\V1\Admin\Moderation\PhotoController;
use App\Http\Controllers\Api\V1\Admin\Moderation\PhotoCommentController;
use App\Http\Controllers\Api\V1\Admin\Moderation\DatingController;
use App\Http\Controllers\Api\V1\Admin\Moderation\ReportController;
use App\Http\Controllers\Api\V1\Admin\Moderation\DiaryController;
use App\Http\Controllers\Api\V1\Admin\Moderation\DiaryCommentController;
use App\Http\Controllers\Api\V1\Admin\Main\UserController;
use App\Http\Controllers\Api\V1\Admin\Main\UserListingsController;

use Illuminate\Support\Facades\Route;

/**
 * ============================================================
 * ADMIN API ROUTES
 * ============================================================
 * URL: /api/admin/*
 *
 * Авторизация через Sanctum (cookie-based, дефолтный web guard).
 * Изоляция сессий web/admin достигается за счёт разных поддоменов
 * (admin.dating для админки, dating для веба) и host-only cookies.
 *
 * ------------------------------------------------------------
 * RBAC MATRIX (роли: admin / moderator / support)
 * ------------------------------------------------------------
 * Роль admin        — полный доступ ко всем разделам.
 * Роль moderator    — модерация контента (фото, жалобы, дневники,
 *                      чаты юзеров, антифрод, стоп-слова).
 * Роль support      — работа с тикетами поддержки и просмотр
 *                      шаблонов ответов.
 *
 * Founders (id из APP_FOUNDERS) — это просто admin-аккаунты с
 * дополнительной защитой: их нельзя забанить или понизить через
 * админку (см. StaffController). Founder НЕ обходит проверки
 * middleware — он должен иметь role=admin для доступа.
 *
 * ------------------------------------------------------------
 * Принцип применения middleware (через группы-обёртки):
 *  - 'admin.role'                  → все staff (admin/moderator/support)
 *  - 'admin.role:admin,moderator'  → admin + moderator
 *  - 'admin.role:admin'            → ТОЛЬКО admin
 *
 * Маршруты сгруппированы по доступу. Каждая группа — отдельная
 * обёртка с нужным middleware. Внутри группы middleware НЕ
 * дублируется на отдельные роуты.
 * ============================================================
 */

Route::middleware(['web'])->group(function () {
    // Публичные роуты (логин)
    // throttle:admin-login — защита от брутфорса пароля.
    // 5 попыток/мин на (email + ip). См. AppServiceProvider::registerRateLimiters().
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware(['throttle:admin-login', 'throttle:admin-login-by-email'])
        ->name('admin.login');

    // ============================================================
    // ГРУППА 1: ВСЕ STAFF (admin + moderator + support)
    // ============================================================
    // Базовая авторизация, дашборд, тикеты поддержки и чтение
    // шаблонов ответов — то, что нужно каждой роли.
    Route::middleware(['auth:sanctum', 'admin.role'])->group(function () {

        // === АВТОРИЗАЦИЯ ===
        Route::get('/me', [AuthController::class, 'me'])->name('admin.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('admin.logout');

        // Завершение ВСЕХ других сессий текущего юзера (кроме текущей).
        // Используется при подозрении на компрометацию или потере девайса.
        // См. AuthController::logoutEverywhere() — там же сценарии использования.
        // Throttle НЕ нужен — это safe action (удаляет только свои сессии),
        // не может быть использован для атаки на других юзеров.
        Route::post('/logout-everywhere', [AuthController::class, 'logoutEverywhere'])
            ->name('admin.logout-everywhere');

        // === DASHBOARD === (внутри контроллера данные делятся по ролям)
        Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->name('admin.dashboard.stats');

        // === SUPPORT CHATS (Тикеты поддержки) ===
        // Основная работа support. Moderator/admin тоже могут отвечать
        // на эскалированные тикеты.
        Route::get('/support-chats', [SupportChatController::class, 'index']);
        Route::get('/support-chats/templates', [SupportChatController::class, 'templates']);
        Route::get('/support-chats/{chatId}', [SupportChatController::class, 'show']);
        Route::post('/support-chats/{chatId}/mark-as-read', [SupportChatController::class, 'markAsRead']);
        Route::post('/support-chats/{chatId}/archive', [SupportChatController::class, 'archive']);
        Route::post('/support-chats/{chatId}/unarchive', [SupportChatController::class, 'unarchive']);
        Route::post('/support-chats/{chatId}/send', [SupportChatController::class, 'sendMessage'])
            ->middleware('throttle:admin-support-actions');
        Route::post('/support-chats/start/{userId}', [SupportChatController::class, 'startWithUser']);

        // === SUPPORT TEMPLATES (чтение) ===
        // Все staff используют шаблоны при ответах в чатах.
        // Управление (POST/PUT/DELETE) — в группе admin-only ниже.
        Route::get('/support-templates', [SupportTemplateController::class, 'index']);
        Route::get('/support-templates/categories', [SupportTemplateController::class, 'categoriesIndex']);
        Route::get('/support-templates/{id}', [SupportTemplateController::class, 'show']);
    });

    // ============================================================
    // ГРУППА 2: ADMIN + MODERATOR
    // ============================================================
    // Модерация контента: фото, комментарии, дневники, жалобы,
    // свайпы/мэтчи, чаты юзеров, антифрод, стоп-слова.
    Route::middleware(['auth:sanctum', 'admin.role:admin,moderator'])->group(function () {

        // === USERS (Просмотр — все staff, действия — admin/moderator) ===
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/stats', [UserController::class, 'stats']);

        Route::get('/users/{user}', [UserController::class, 'show']);
        Route::post('/users/{user}/profile/clear-field', [UserController::class, 'clearProfileField']);
        Route::get('/users/{user}/ban-history', [UserController::class, 'banHistory']);
        Route::get('/users/{user}/sessions', [UserController::class, 'sessions']);
        Route::post('/users/{user}/sessions/kill', [UserController::class, 'killSession'])
            ->middleware('throttle:admin-user-actions');
        Route::post('/users/{user}/sessions/kill-all', [UserController::class, 'killAllSessions'])
            ->middleware('throttle:admin-user-actions');

        Route::get('/users/{user}/reports', [UserController::class, 'reports']);
        Route::get('/users/{user}/blocks', [UserController::class, 'blocks']);
        Route::post('/users/{user}/blocks/unblock', [UserController::class, 'unblockBlock'])
            ->middleware('throttle:admin-user-actions');

        // === USER TABS: пер-юзер листинги (UserListingsController) ===
        // Read-only списки для табов страницы /users/{id}. Мутации — через
        // глобальные разделы модерации (там же throttle и RBAC).
        // Роли — зеркально глобальным аналогам: фото/дневники/комменты/
        // знакомства/чаты — moderator+admin (как их разделы модерации).
        Route::get('/users/{user}/photos', [UserListingsController::class, 'photos']);
        Route::get('/users/{user}/photo-comments', [UserListingsController::class, 'photoComments']);
        Route::get('/users/{user}/diaries', [UserListingsController::class, 'diaries']);
        Route::get('/users/{user}/diary-comments', [UserListingsController::class, 'diaryComments']);
        Route::get('/users/{user}/dating', [UserListingsController::class, 'dating']);
        Route::get('/users/{user}/chats', [UserListingsController::class, 'chats']);
        Route::get('/users/{user}/events', [UserListingsController::class, 'events']);

        // === USERS MUTATIONS ===
        Route::post('/users/{userId}/ban', [UserController::class, 'ban'])
            ->middleware('throttle:admin-user-actions');
        Route::post('/users/{userId}/unban', [UserController::class, 'unban'])
            ->middleware('throttle:admin-user-actions');
        Route::post('/users/{userId}/restore', [UserController::class, 'restore'])
            ->middleware('throttle:admin-user-actions');
        Route::delete('/users/{userId}', [UserController::class, 'destroy'])
            ->middleware('throttle:admin-user-actions');

        // === FRAUD ALERTS (Антифрод) ===
        // Список / счётчики — без throttle (read-only).
        // ВАЖНО: /counts ДО /{alert}/... — иначе Laravel спутает 'counts' с {alert}.
        Route::get('/fraud-alerts', [FraudAlertController::class, 'index']);
        Route::get('/fraud-alerts/counts', [FraudAlertController::class, 'counts']);
        // mutations — destructive, throttle 30/мин (как report-actions / block-signal-actions).
        Route::post('/fraud-alerts/{alert}/resolve-and-ban', [FraudAlertController::class, 'resolveAndBan'])
            ->middleware('throttle:admin-fraud-actions');
        Route::post('/fraud-alerts/{alert}/resolve-with-warning', [FraudAlertController::class, 'resolveWithWarning'])
            ->middleware('throttle:admin-fraud-actions');
        Route::post('/fraud-alerts/{alert}/false-positive', [FraudAlertController::class, 'markAsFalsePositive'])
            ->middleware('throttle:admin-fraud-actions');

        // === BLOCK SIGNALS (Сигналы блокировок) ===
        // Список / счётчики — без throttle (read-only).
        // ВАЖНО: /counts ДО /{userId}/... — иначе Laravel спутает 'counts' с {userId}.
        Route::get('/block-signals', [BlockSignalController::class, 'index']);
        Route::get('/block-signals/counts', [BlockSignalController::class, 'counts']);
        Route::get('/block-signals/{userId}/blockers', [BlockSignalController::class, 'blockers']);
        // ban — destructive, throttle 30/мин (как report-actions).
        Route::post('/block-signals/{userId}/ban', [BlockSignalController::class, 'ban'])
            ->middleware('throttle:admin-block-signal-actions');



        // === PHOTOS MODERATION ===
        // ВАЖНО: статичные пути (counts, user/{id}/...) ДО динамического /{id},
        // иначе Laravel спутает 'counts' с {id} и 'user' с {id}.
        Route::get('/photos', [PhotoController::class, 'index']);
        Route::get('/photos/counts', [PhotoController::class, 'counts']);
        Route::post('/photos/user/{userId}/approve-all', [PhotoController::class, 'approveAllForUser'])
            ->middleware('throttle:admin-photo-actions');
        Route::post('/photos/user/{userId}/reject-all', [PhotoController::class, 'rejectAllForUser'])
            ->middleware('throttle:admin-photo-actions');
        // Actions для конкретного фото
        Route::post('/photos/{id}/approve', [PhotoController::class, 'approve'])
            ->middleware('throttle:admin-photo-actions');
        Route::post('/photos/{id}/reject', [PhotoController::class, 'reject'])
            ->middleware('throttle:admin-photo-actions');
        Route::post('/photos/{id}/set-primary', [PhotoController::class, 'setPrimary'])
            ->middleware('throttle:admin-photo-actions');
        Route::post('/photos/{id}/soft-delete', [PhotoController::class, 'softDelete'])
            ->middleware('throttle:admin-photo-actions');
        Route::post('/photos/{id}/restore', [PhotoController::class, 'restore'])
            ->middleware('throttle:admin-photo-actions');
        Route::delete('/photos/{id}', [PhotoController::class, 'destroy'])
            ->middleware('throttle:admin-photo-actions');


        // === PHOTO COMMENTS MODERATION ===
        Route::get('/photo-comments', [PhotoCommentController::class, 'index']);
        Route::get('/photo-comments/counts', [PhotoCommentController::class, 'counts']);
        // Глобальные массовые действия (ВСЕ pending)
        Route::post('/photo-comments/approve-all', [PhotoCommentController::class, 'approveAllPending'])
            ->middleware('throttle:admin-photo-comment-actions');
        Route::post('/photo-comments/reject-all', [PhotoCommentController::class, 'rejectAllPending'])
            ->middleware('throttle:admin-photo-comment-actions');
        // Массовые действия по фото
        Route::post('/photo-comments/photo/{photoId}/approve-remaining', [PhotoCommentController::class, 'approveRemaining'])
            ->middleware('throttle:admin-photo-comment-actions');
        Route::post('/photo-comments/photo/{photoId}/reject-remaining', [PhotoCommentController::class, 'rejectRemaining'])
            ->middleware('throttle:admin-photo-comment-actions');
        // Одиночные действия над комментарием
        Route::post('/photo-comments/{id}/approve', [PhotoCommentController::class, 'approve'])
            ->middleware('throttle:admin-photo-comment-actions');
        Route::post('/photo-comments/{id}/reject', [PhotoCommentController::class, 'reject'])
            ->middleware('throttle:admin-photo-comment-actions');
        // /spam убран: спам теперь — это reject с reason='spam' (Вариант A).
        // Шорткат для UI: POST /reject с телом {reason:'spam'}.
        Route::post('/photo-comments/{id}/restore', [PhotoCommentController::class, 'restore'])
            ->middleware('throttle:admin-photo-comment-actions');
        Route::post('/photo-comments/{id}/soft-delete', [PhotoCommentController::class, 'softDelete'])
            ->middleware('throttle:admin-photo-comment-actions');
        Route::delete('/photo-comments/{id}', [PhotoCommentController::class, 'destroy'])
            ->middleware('throttle:admin-photo-comment-actions');


        // === DATING MODERATION (Свайпы и Матчи) ===
        Route::get('/dating', [DatingController::class, 'index']);
        Route::get('/dating/counts', [DatingController::class, 'counts']);
        Route::delete('/dating/swipes/{id}', [DatingController::class, 'destroySwipe'])
            ->middleware('throttle:admin-dating-actions');
        Route::delete('/dating/matches/{id}', [DatingController::class, 'destroyMatch'])
            ->middleware('throttle:admin-dating-actions');
        Route::post('/dating/matches/{id}/restore', [DatingController::class, 'restoreMatch'])
            ->middleware('throttle:admin-dating-actions');


        // === REPORTS MODERATION (Жалобы) ===
        Route::get('/reports', [ReportController::class, 'index']);
        Route::get('/reports/counts', [ReportController::class, 'counts']);
        Route::post('/reports/{id}/resolve', [ReportController::class, 'resolve'])
            ->middleware('throttle:admin-report-actions');
        Route::post('/reports/{id}/reject', [ReportController::class, 'reject'])
            ->middleware('throttle:admin-report-actions');
        Route::post('/reports/{id}/reopen', [ReportController::class, 'reopen'])
            ->middleware('throttle:admin-report-actions');
        Route::post('/reports/user/{userId}/ban', [ReportController::class, 'toggleBan'])
            ->middleware('throttle:admin-report-actions');
        Route::post('/reports/photo/{photoId}/reject', [ReportController::class, 'rejectPhoto'])
            ->middleware('throttle:admin-report-actions');

        // === DIARY MODERATION (Посты + Рубрики) ===
        // Список / детальный просмотр — без throttle (read-only).
        // ВАЖНО: /counts ДО /{id}/... — иначе Laravel спутает 'counts' с {id}.
        Route::get('/diary', [DiaryController::class, 'index']);
        Route::get('/diary/counts', [DiaryController::class, 'counts']);
        Route::get('/diary/{id}', [DiaryController::class, 'show']);

        // Посты — mutations.
        Route::post('/diary/{id}/approve', [DiaryController::class, 'approve'])
            ->middleware('throttle:admin-diary-actions');
        Route::post('/diary/{id}/reject', [DiaryController::class, 'reject'])
            ->middleware('throttle:admin-diary-actions');
        Route::post('/diary/{id}/unpublish', [DiaryController::class, 'unpublish'])
            ->middleware('throttle:admin-diary-actions');
        Route::post('/diary/{id}/settings', [DiaryController::class, 'updateSettings'])
            ->middleware('throttle:admin-diary-actions');
        Route::post('/diary/{id}/soft-delete', [DiaryController::class, 'destroy'])
            ->middleware('throttle:admin-diary-actions');
        Route::post('/diary/{id}/restore', [DiaryController::class, 'restore'])
            ->middleware('throttle:admin-diary-actions');
        Route::delete('/diary/{id}', [DiaryController::class, 'forceDelete'])
            ->middleware('throttle:admin-diary-actions');

        // Рубрики — mutations (в том же контроллере).
        // ВАЖНО: /rubrics/check-name ДО /rubrics/{id}/... — иначе Laravel
        // спутает 'check-name' с {id}.
        Route::get('/diary/rubrics/check-name', [DiaryController::class, 'checkRubricName']);
        Route::post('/diary/rubrics', [DiaryController::class, 'storeRubric'])
            ->middleware('throttle:admin-diary-actions');
        Route::put('/diary/rubrics/{id}', [DiaryController::class, 'updateRubric'])
            ->middleware('throttle:admin-diary-actions');
        Route::delete('/diary/rubrics/{id}', [DiaryController::class, 'destroyRubric'])
            ->middleware('throttle:admin-diary-actions');
        Route::post('/diary/rubrics/{id}/toggle', [DiaryController::class, 'toggleRubricStatus'])
            ->middleware('throttle:admin-diary-actions');

        // === DIARY COMMENTS MODERATION ===
        // Полная аналогия с photo-comments (Вариант A — спам = причина, не статус).
        // /spam endpoint убран: спам выбирается в dropdown причин отклонения.
        // /restore-status убран: restore работает в двух режимах (из карантина / из rejected)
        //   через единый endpoint /restore — бэк разруливает по trashed-флагу.
        Route::get('/diary-comments', [DiaryCommentController::class, 'index']);
        Route::get('/diary-comments/counts', [DiaryCommentController::class, 'counts']);
        Route::post('/diary-comments/{id}/approve', [DiaryCommentController::class, 'approve'])
            ->middleware('throttle:admin-diary-actions');
        Route::post('/diary-comments/{id}/reject', [DiaryCommentController::class, 'reject'])
            ->middleware('throttle:admin-diary-actions');
        Route::post('/diary-comments/{id}/restore', [DiaryCommentController::class, 'restore'])
            ->middleware('throttle:admin-diary-actions');
        Route::post('/diary-comments/{id}/soft-delete', [DiaryCommentController::class, 'softDelete'])
            ->middleware('throttle:admin-diary-actions');
        Route::delete('/diary-comments/{id}', [DiaryCommentController::class, 'destroy'])
            ->middleware('throttle:admin-diary-actions');

        // === CHATS (Все чаты юзеров) ===
        // Moderator просматривает чаты при разбирательствах (жалобы и т.д.).
        // Support НЕ имеет доступа — он работает через /support-chats.
        Route::get('/chats', [ChatController::class, 'index']);
        Route::get('/chats/{chatId}', [ChatController::class, 'show']);
        Route::post('/chats/{chatId}/toggle-lock', [ChatController::class, 'toggleLock'])
            ->middleware('throttle:admin-chat-actions');



        // === STOP WORDS ===
        Route::get('/stop-words', [StopWordController::class, 'index']);
        Route::get('/stop-words/counts', [StopWordController::class, 'counts']);
        Route::post('/stop-words', [StopWordController::class, 'storeBulk'])
            ->middleware('throttle:admin-stop-words-actions');
        Route::post('/stop-words/bulk', [StopWordController::class, 'applyBulk'])
            ->middleware('throttle:admin-stop-words-actions');
        // Редактирование одного слова (word/category/action/replacement/is_active).
        Route::put('/stop-words/{id}', [StopWordController::class, 'update'])
            ->middleware('throttle:admin-stop-words-actions');
        Route::post('/stop-words/{id}/toggle', [StopWordController::class, 'toggleActive'])
            ->middleware('throttle:admin-stop-words-actions');
        Route::delete('/stop-words/{id}', [StopWordController::class, 'destroy'])
            ->middleware('throttle:admin-stop-words-actions');
    });



    // ============================================================
    // ГРУППА 3: ТОЛЬКО ADMIN
    // ============================================================
    // Управление ролями, финансами, настройками, CMS-контентом,
    // рассылками, системными функциями. Moderator/support не имеют
    // доступа к этим ресурсам — иначе privilege escalation.
    Route::middleware(['auth:sanctum', 'admin.role:admin'])->group(function () {


        // === STAFF (РОЛИ И АДМИНЫ) ===
        // Управление ролями — прерогатива admin. Moderator/support не должны
        // повышать друг друга или самих себя — иначе privilege escalation.
        Route::get('/staff', [StaffController::class, 'index'])->name('admin.staff.index');
        Route::get('/staff/search', [StaffController::class, 'search'])->name('admin.staff.search');
        Route::post('/staff/{userId}/promote', [StaffController::class, 'promote'])
            ->middleware('throttle:admin-staff-actions')
            ->name('admin.staff.promote');
        Route::post('/staff/{userId}/demote', [StaffController::class, 'demote'])
            ->middleware('throttle:admin-staff-actions')
            ->name('admin.staff.demote');
        Route::patch('/staff/batch-update', [StaffController::class, 'batchUpdate'])->name('admin.staff.batch');

        // === USER TABS: пер-юзер листинги — admin only (UserListingsController) ===
        // Финансы, карты, промокоды, рассылки, аудиторские данные —
        // зеркально глобальным аналогам (transactions/gifts/broadcasts/
        // audit-logs доступны только admin).
        Route::get('/users/{user}/finances', [UserListingsController::class, 'finances']);
        Route::get('/users/{user}/gifts', [UserListingsController::class, 'gifts']);
        Route::get('/users/{user}/broadcasts', [UserListingsController::class, 'broadcasts']);
        Route::get('/users/{user}/admin-logs', [UserListingsController::class, 'adminLogs']);
        Route::get('/users/{user}/cards', [UserListingsController::class, 'cards']);
        Route::get('/users/{user}/promocodes', [UserListingsController::class, 'promocodes']);
        Route::get('/users/{user}/auth-logs', [UserListingsController::class, 'authLogs']);

        // === SYSTEM LOGS ===
        // Технические логи Laravel могут содержать sensitive data (stack traces,
        // SQL с параметрами). Clear — отдельно критично: стирание логов =
        // уничтожение следов.
        Route::get('/system/logs', [LogController::class, 'index'])->name('admin.system.logs');
        Route::post('/system/logs/clear', [LogController::class, 'clear'])
            ->middleware('throttle:admin-logs-actions')
            ->name('admin.system.logs.clear');

        // === SETTINGS ===
        // Глобальные настройки: security, finance, registration, features.
        Route::get('/settings/groups', [SettingController::class, 'groups'])->name('admin.settings.groups');
        Route::get('/settings', [SettingController::class, 'index'])->name('admin.settings.index');
        Route::post('/settings', [SettingController::class, 'save'])
            ->middleware('throttle:admin-settings-actions')
            ->name('admin.settings.save');
        Route::post('/settings/reset', [SettingController::class, 'reset'])
            ->middleware('throttle:admin-settings-actions')
            ->name('admin.settings.reset');

        // === PAGES (CMS) ===
        // Статические страницы сайта — контент-менеджмент, не модерация.
        Route::get('/pages', [PageController::class, 'index'])->name('admin.pages.index');
        Route::post('/pages', [PageController::class, 'store'])->name('admin.pages.store');
        Route::get('/pages/{id}', [PageController::class, 'show'])->name('admin.pages.show');
        Route::put('/pages/{id}', [PageController::class, 'update'])->name('admin.pages.update');
        Route::delete('/pages/{id}', [PageController::class, 'destroy'])->name('admin.pages.destroy');
        Route::post('/pages/{id}/toggle-status', [PageController::class, 'toggleStatus'])
            ->middleware('throttle:admin-pages-actions')
            ->name('admin.pages.toggle');
        Route::post('/pages/{id}/duplicate', [PageController::class, 'duplicate'])
            ->middleware('throttle:admin-pages-actions')
            ->name('admin.pages.duplicate');
        Route::post('/pages/bulk', [PageController::class, 'bulkAction'])->name('admin.pages.bulk');

        // === МЕДИА ===
        // Медиабиблиотека — управление файлами проекта. Moderator работает
        // с фото юзеров через /photos, а не через общую медиатеку.
        Route::get('/media', [MediaController::class, 'index']);
        Route::get('/media/counts', [MediaController::class, 'counts']);   // ✅ ДО /{media}
        Route::post('/media', [MediaController::class, 'upload'])
            ->middleware('throttle:admin-media-actions');
        Route::post('/media/bulk-delete', [MediaController::class, 'bulkDelete']);
        Route::get('/media/{media}', [MediaController::class, 'show']);    // ✅ После /counts
        Route::delete('/media/{media}', [MediaController::class, 'destroy']);

        // === БЛОГ: КАТЕГОРИИ ===
        Route::get('/blog/categories', [BlogCategoryController::class, 'index']);
        Route::post('/blog/categories', [BlogCategoryController::class, 'store']);
        Route::put('/blog/categories/{category}', [BlogCategoryController::class, 'update']);
        Route::delete('/blog/categories/{category}', [BlogCategoryController::class, 'destroy']);

        // === БЛОГ: ПОСТЫ ===
        Route::get('/blog/posts/counts', [BlogPostController::class, 'counts']);
        Route::get('/blog/posts', [BlogPostController::class, 'index']);
        Route::post('/blog/posts', [BlogPostController::class, 'store']);
        Route::get('/blog/posts/{post}', [BlogPostController::class, 'show']);
        Route::put('/blog/posts/{post}', [BlogPostController::class, 'update']);
        Route::delete('/blog/posts/{post}', [BlogPostController::class, 'destroy']);
        Route::post('/blog/posts/{post}/duplicate', [BlogPostController::class, 'duplicate']);
        Route::post('/blog/posts/{post}/toggle', [BlogPostController::class, 'toggle']);
        Route::post('/blog/posts/{post}/archive', [BlogPostController::class, 'archive']);
        Route::post('/blog/posts/{post}/restore', [BlogPostController::class, 'restore']);
        Route::post('/blog/posts/{post}/feature', [BlogPostController::class, 'setFeatured'])
            ->middleware('throttle:admin-blog-actions');
        Route::post('/blog/posts/bulk', [BlogPostController::class, 'bulk']);

        // === GEO IP LOCATIONS ===
        // Блокировка регионов от регистрации/ленты — критичная операция.
        // Один клик может закрыть весь регион для миллионов юзеров.
        Route::get('/geoip/locations', [GeoIPLocationController::class, 'index']);
        Route::get('/geoip/locations/counts', [GeoIPLocationController::class, 'counts']);
        Route::post('/geoip/locations/{location}/toggle-registration', [GeoIPLocationController::class, 'toggleRegistration'])
            ->middleware('throttle:admin-geo-actions');
        Route::post('/geoip/locations/{location}/toggle-feed', [GeoIPLocationController::class, 'toggleFeed'])
            ->middleware('throttle:admin-geo-actions');

        // === AUDIT LOGS (Журнал действий) ===
        // Кто что делал в админке. Moderator/support не должны видеть
        // действия других админов (включая свои же следы).
        Route::get('/audit/logs', [AdminLogController::class, 'index']);
        Route::post('/audit/logs/refresh', [AdminLogController::class, 'refresh']);

        // === BROADCASTS (Рассылки) ===
        // Массовые уведомления миллионам юзерам — критично.
        Route::get('/broadcasts', [BroadcastController::class, 'index']);
        Route::get('/broadcasts/counts', [BroadcastController::class, 'counts']);
        Route::get('/broadcasts/search-users', [BroadcastController::class, 'searchUsers']); // ✅ Подняли ВЫШЕ!
        Route::post('/broadcasts', [BroadcastController::class, 'store']);
        Route::post('/broadcasts/bulk-delete', [BroadcastController::class, 'bulkDestroy']);
        Route::get('/broadcasts/{broadcast}', [BroadcastController::class, 'show']); // Теперь {broadcast} не перехватит search-users
        Route::put('/broadcasts/{broadcast}', [BroadcastController::class, 'update']);
        Route::post('/broadcasts/{broadcast}/send-now', [BroadcastController::class, 'sendNow'])
            ->middleware('throttle:admin-broadcast-actions');
        Route::post('/broadcasts/{broadcast}/duplicate', [BroadcastController::class, 'duplicate']);
        Route::delete('/broadcasts/{broadcast}', [BroadcastController::class, 'destroy']);

        // === TRANSACTIONS (Финансы) ===
        // Возвраты, синхронизация с банком — финансовые операции.
        // sync/refund дополнительно защищены throttle:admin-transaction-actions
        // (5 в минуту на transaction_id+admin_id) — защита от спама кликов.
        // См. AppServiceProvider::registerRateLimiters().
        Route::get('/transactions', [TransactionController::class, 'index']);
        Route::get('/transactions/{id}', [TransactionController::class, 'show']);
        Route::post('/transactions/{id}/sync', [TransactionController::class, 'sync'])
            ->middleware('throttle:admin-transaction-actions');
        Route::post('/transactions/{id}/refund', [TransactionController::class, 'refund'])
            ->middleware('throttle:admin-transaction-actions');

        // === SUBSCRIPTION PLANS ===
        // Тарифы подписок — финансовая конфигурация.
        Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index']);
        Route::post('/subscription-plans', [SubscriptionPlanController::class, 'store']);
        Route::put('/subscription-plans/{id}', [SubscriptionPlanController::class, 'update']);
        Route::post('/subscription-plans/{id}/toggle', [SubscriptionPlanController::class, 'toggleActive']);

        // === GIFTS (Каталог) ===
        Route::get('/gifts', [GiftController::class, 'index']);
        Route::post('/gifts', [GiftController::class, 'store']);
        Route::put('/gifts/{id}', [GiftController::class, 'update']);
        Route::delete('/gifts/{id}', [GiftController::class, 'destroy']);
        Route::post('/gifts/{id}/toggle', [GiftController::class, 'toggleStatus']);

        // === GIFTS HISTORY (Финансовая история) ===
        Route::get('/gifts/history', [GiftController::class, 'history']);
        Route::post('/gifts/history/{id}/hide', [GiftController::class, 'hideUserGift']);
        Route::post('/gifts/history/{id}/restore', [GiftController::class, 'restoreUserGift']);

        // === SUPPORT TEMPLATE CATEGORIES (управление) ===
        // Чтение (GET /categories) — в группе 1 (все staff).
        // Здесь — только создание/обновление/удаление (admin only).
        Route::post('/support-templates/categories', [SupportTemplateController::class, 'storeCategory']);
        Route::put('/support-templates/categories/{id}', [SupportTemplateController::class, 'updateCategory']);
        Route::delete('/support-templates/categories/{id}', [SupportTemplateController::class, 'destroyCategory']);

        // === SUPPORT TEMPLATES (управление) ===
        // Чтение — в группе 1 (все staff). Здесь — только запись/удаление.
        Route::post('/support-templates', [SupportTemplateController::class, 'store'])
            ->middleware('throttle:admin-templates-actions');
        Route::put('/support-templates/{id}', [SupportTemplateController::class, 'update'])
            ->middleware('throttle:admin-templates-actions');
        Route::delete('/support-templates/{id}', [SupportTemplateController::class, 'destroy'])
            ->middleware('throttle:admin-templates-actions');

        // === SYSTEM CACHE ===
        Route::post('/system/clear-cache', [SystemController::class, 'clearCache'])
            ->name('admin.system.clear-cache');

        Route::post('/system/clear-search-cache', [SystemController::class, 'clearSearchCache'])
            ->name('admin.system.clear-search-cache');
    });
});
