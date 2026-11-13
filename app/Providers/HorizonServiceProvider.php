<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
protected function gate(): void
{
    Gate::define('viewHorizon', function ($user = null) {
        // В локалке пускаем всех
        if (app()->environment('local')) {
            return true;
        }

        // В проде — только админы
        return $user && in_array($user->role, [
            \App\Models\User::ROLE_ADMIN,
        ]);
    });
}
 
}
