<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\OnboardingController;
use App\Http\Controllers\Api\V1\FeedController;
use App\Http\Controllers\Api\V1\GeoController;
use App\Http\Controllers\Api\V1\UserActionController;
use App\Http\Controllers\Api\V1\UserProfileController;
use App\Http\Controllers\Api\V1\SwipeController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\EditProfileController;
use App\Http\Controllers\Api\V1\InterestController;


use Illuminate\Support\Facades\Route;

// Оборачиваем в 'web', чтобы гарантированно работали сессии и капча
Route::middleware(['web'])->group(function () {

    // Публичные роуты (Лента анкет)
    Route::get('/users/search', [FeedController::class, 'search'])->name('api.users.search');
    Route::get('/users/avatars', [FeedController::class, 'randomAvatars'])->name('api.users.avatars');

    // Профиль юзера
    Route::get('/user/{slug}', [UserProfileController::class, 'show'])->name('api.profile.show');

    // Поиск городов и стран
    Route::get('/geo/search', [GeoController::class, 'search'])->name('api.geo.search');

    // Публичные роуты
    Route::post('/login', [AuthController::class, 'login'])->name('api.login');
    Route::post('/register', [AuthController::class, 'register'])->name('api.register');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('api.forgot.password');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('api.reset.password');

    // Капча
    Route::get('/captcha/login', [AuthController::class, 'getCaptchaLogin'])->name('api.captcha.login');
    Route::get('/captcha/forgot', [AuthController::class, 'getCaptchaForgot'])->name('api.captcha.forgot');
    Route::post('/register/captcha', [AuthController::class, 'refreshRegisterCaptcha'])->name('api.register.captcha');

    // Мульти-шаговая регистрация
    Route::post('/register/step1', [AuthController::class, 'validateStep1'])->name('api.register.step1');
    Route::post('/register/step2', [AuthController::class, 'validateStep2'])->name('api.register.step2');
});

// Роут верификации email (клик из письма) - тоже web
Route::get('/verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])->name('verification.verify');

// Защищенные роуты (Sanctum + Sessions)
Route::middleware(['web', 'auth:sanctum'])->group(function () {
    // ============================================================
    // ЮЗЕРСКИЕ РОУТЫ (для любого залогиненного юзера)
    // ============================================================

    // Список ВСЕХ approved интересов с категориями — для UI выбора в форме.
    // Фронт дёргает этот endpoint при открытии секции "Интересы" в редактировании профиля.
    // Возвращает: { categories: [{ id, name, slug, icon, interests: [{ id, name }] }] }
    Route::get('/interests', [InterestController::class, 'index']);    

    // Свои интересы (approved + pending отдельно).  
    // Фронт дёргает при загрузке профиля.
    // Возвращает: {
    //   approved: [{ id, name, category: { id, name } }],
    //   pending:  [{ id, name, proposed_at }]  — на модерации
    // }
    Route::get('/user/interests', [InterestController::class, 'my']);

    // обновление интересов юзера
    Route::patch('/profile/interests', [EditProfileController::class, 'updateInterests']);

    // Добавить approved интерес (выбор из списка).
    // Body: { interest_id: int }
    // Бэк: INSERT INTO user_interests (user_id, interest_id)
    Route::post('/user/interests', [InterestController::class, 'attach']);

    // Убрать интерес у себя.
    // Бэк: DELETE FROM user_interests WHERE user_id=? AND interest_id=?
    Route::delete('/user/interests/{interest}', [InterestController::class, 'detach']);

    // Предложить СВОЙ интерес (откроется как pending на модерации).
    // Body: { name: string }
    // Бэк:
    //   - если имя уже approved → просто attach (мгновенно)
    //   - если уже pending → вставка в user_pending_interests
    //   - если уже rejected → 422 "ранее отклонён"
    //   - если нового → создать pending interest + связь в user_pending_interests
    Route::post('/user/interests/custom', [InterestController::class, 'proposeCustom']);


  // NEW: редактирование профиля
    Route::patch('/profile/main', [EditProfileController::class, 'updateMain']);
    Route::patch('/profile/headline', [EditProfileController::class, 'updateHeadline']);
    Route::patch('/profile/bio', [EditProfileController::class, 'updateBio']);
    Route::patch('/profile/self-portrait', [EditProfileController::class, 'updateSelfPortrait']);
    Route::patch('/profile/personal-info', [EditProfileController::class, 'updatePersonalInfo']);
    Route::patch('/profile/looking-for', [EditProfileController::class, 'updateLookingFor']);
    Route::patch('/profile/preferences', [EditProfileController::class, 'updatePreferences']);    
    

    // Подписки
    Route::get('/subscriptions/plans', [SubscriptionController::class, 'plans']);   
    Route::post('/subscriptions/pay', [SubscriptionController::class, 'pay']);
    Route::get('/subscriptions/status/{transaction}', [SubscriptionController::class, 'status']);
    Route::post('/subscriptions/renew', [SubscriptionController::class, 'renew']);

    // Подарки  
    Route::get('/gifts', [\App\Http\Controllers\Api\V1\GiftController::class, 'index'])->name('api.gifts.index');    
    Route::delete('/gifts/{giftId}', [\App\Http\Controllers\Api\V1\GiftController::class, 'destroy']);

    // Биллинг    
    Route::post('/billing/pay', [\App\Http\Controllers\Api\V1\BillingController::class, 'pay'])->name('api.billing.pay');    
    Route::get('/billing/status/{transaction}', [\App\Http\Controllers\Api\V1\BillingController::class, 'status'])->name('api.billing.status');    

    // Действия (лайк, избранное, блок, жалоба, чат)    
    Route::post('/users/{user}/gift', [\App\Http\Controllers\Api\V1\GiftController::class, 'store'])->name('api.gift.store');
    Route::post('/users/{user}/swipe', [SwipeController::class, 'store'])->name('api.user.swipe');
    Route::post('/users/{user}/favorite', [UserActionController::class, 'toggleFavorite'])->name('api.user.favorite');
    Route::post('/users/{user}/block', [UserActionController::class, 'toggleBlock'])->name('api.user.block');
    Route::post('/users/{user}/report', [UserActionController::class, 'report'])->name('api.user.report');
    Route::post('/users/{user}/chat', [UserActionController::class, 'chat'])->name('api.user.chat');

    

    Route::get('/me', [AuthController::class, 'user'])->name('api.me');
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::post('/confirm-password', [AuthController::class, 'confirmPassword'])->name('api.password.confirm');

    Route::post('/email/resend', [AuthController::class, 'resendVerification'])->name('api.verification.send');
    Route::post('/email/change', [AuthController::class, 'changeEmail'])->name('api.verification.change');

    // Сохранение темы
    Route::post('/settings/theme', function (\Illuminate\Http\Request $request) {
        $request->validate(['theme' => 'required|in:light,dark']);
        $request->user()->preferences()->update(['theme' => $request->theme]);
        return response()->json(['success' => true]);
    })->name('api.settings.theme');

    // Онбординг
    Route::get('/onboarding/photos', [OnboardingController::class, 'getPhotos']);
    Route::post('/onboarding/save', [OnboardingController::class, 'save']);
    Route::post('/onboarding/skip', [OnboardingController::class, 'skip']);
    Route::delete('/photos/{photo}', [OnboardingController::class, 'deletePhoto']);

    // ============================================
    // HEARTBEAT (онлайн-статус)
    // ============================================
    // Важно про throttle: throttle:3,1 = 3 запроса в минуту (раз в 20 сек). Если frontend шлёт heartbeat каждые 30 сек — это оставляет запас. 
    // Если юзер попытается спамить — получит 429 Too Many Requests.
    // Rate limited: max 1 запрос в 20 секунд (frontend пингует каждые 30 сек)
    Route::middleware(['throttle:3,1'])->post('/heartbeat', \App\Http\Controllers\Api\V1\HeartbeatController::class);
});






// =======================================================================================================================================
// ADMIN API ROUTES
// Все роуты из routes/admin.php будут доступны по /api/admin/*
// ==========================================================================================================================================
Route::prefix('admin')->group(function () {
    require base_path('routes/admin.php');
});