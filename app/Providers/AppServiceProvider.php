<?php

namespace App\Providers;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Auth\Notifications\ResetPassword;
use App\Models\UserMatch;
use App\Models\Message;
use App\Models\Swipe;
use App\Models\UserBlock;
use App\Models\UserPreference;
use App\Models\UserProfile;
use App\Models\UserSubscription;
use App\Observers\UserMatchObserver;
use App\Observers\MessageObserver;
use App\Observers\SwipeObserver;
use App\Observers\UserBlockObserver;
use App\Observers\UserPreferenceObserver;
use App\Observers\UserProfileObserver;
use App\Observers\UserSubscriptionObserver;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Главный провайдер приложения.
 *
 * Содержит: SQL-дебаггер, регистрацию observers, SPA password reset.
 *
 * НЕ содержит (вынесено в отдельные провайдеры):
 *  - rate limiters      → App\Providers\RateLimitServiceProvider
 *  - Horizon            → App\Providers\HorizonServiceProvider
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // === SQL Debuger ===
        if (config('app.debug_sql', false)) {
            DB::listen(function ($query) {
                Log::debug('SQL: ' . $query->sql, ['bindings' => $query->bindings]);
            });
        }

        // === RATE LIMITERS ===
        // См. App\Providers\RateLimitServiceProvider (вынесено туда,
        // т.к. лимитов стало много — ~20 именованных групп).

        // === OBSERVERS ===

        // Realtime events (broadcast через Reverb)
        UserMatch::observe(UserMatchObserver::class);
        Message::observe(MessageObserver::class);

        // Cache invalidation (поиск анкет)
        Swipe::observe(SwipeObserver::class);
        UserBlock::observe(UserBlockObserver::class);
        UserPreference::observe(UserPreferenceObserver::class);
        UserProfile::observe(UserProfileObserver::class);

        // Когда мы создаем подписку (например, юзер оплатил VIP), нам нужно обновить поля is_premium и premium_expires_at в таблице users (чтобы middleware работало быстро).
        UserSubscription::observe(UserSubscriptionObserver::class);

        // === SPA PASSWORD RESET ===
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            $frontendUrl = config('app.frontend_url');
            $email = urlencode($notifiable->getEmailForPasswordReset());

            return "{$frontendUrl}/reset-password?token={$token}&email={$email}";
        });
    }
}
