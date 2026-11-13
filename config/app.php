<?php

return [
    /*
    |--------------------------------------------------------------------------
    | ХОЗЯЕВА (по дефолту с id=1)
    |--------------------------------------------------------------------------
    |    
    */

    'founders' => array_map('intval', explode(',', env('APP_FOUNDERS', '1'))),

    // Rate limits (см. AppServiceProvider::registerRateLimiters)
    'rate_limit_login_per_min' => (int) env('RATE_LIMIT_LOGIN_PER_MIN', 5),

    // === BRUTE FORCE DETECTION (см. App\Services\AdminAuthService) ===
    // Эти пороги НЕ блокируют вход (блокировку делают throttle'ы
    // admin-login и admin-login-by-email). Они нужны только для
    // генерации audit-событий auth.brute_force_detected — когда паттерн
    // попыток выглядит как атака, в AdminLog пишется отдельная запись
    // с деталями (какой порог, сколько попыток).
    //
    // Окно в минутах — общий для всех трёх порогов (упрощает тюнинг).
    'brute_force_window_minutes'      => (int) env('BRUTE_FORCE_WINDOW_MINUTES', 15),

    // Порог 1: один IP + один email (классический брутфорс с одного источника).
    'brute_force_threshold_ip_email'  => (int) env('BRUTE_FORCE_THRESHOLD_IP_EMAIL', 10),

    // Порог 2: один email со ВСЕХ IP (distributed attack через прокси/ботнет).
    // Выше, чем порог 1, потому что распределённые попытки естественнее
    // (один админ может логиниться с телефона, ноутбука, рабочего компа).
    'brute_force_threshold_email'     => (int) env('BRUTE_FORCE_THRESHOLD_EMAIL', 20),

    // Порог 3: один IP по ЛЮБЫМ email (сканирование email-базы,
    // атакующий перебирает выдуманные админские ящики).
    'brute_force_threshold_ip_scan'   => (int) env('BRUTE_FORCE_THRESHOLD_IP_SCAN', 30),


    /*
    |--------------------------------------------------------------------------
    | Application Name
    |--------------------------------------------------------------------------
    |
    | This value is the name of your application, which will be used when the
    | framework needs to place the application's name in a notification or
    | other UI elements where an application name needs to be displayed.
    |
    */

    'name' => env('APP_NAME', 'Laravel'),

    /*
    |--------------------------------------------------------------------------
    | Application Environment
    |--------------------------------------------------------------------------
    |
    | This value determines the "environment" your application is currently
    | running in. This may determine how you prefer to configure various
    | services the application utilizes. Set this in your ".env" file.
    |
    */

    'env' => env('APP_ENV', 'production'),

    /*
    |--------------------------------------------------------------------------
    | Application Debug Mode
    |--------------------------------------------------------------------------
    |
    | When your application is in debug mode, detailed error messages with
    | stack traces will be shown on every error that occurs within your
    | application. If disabled, a simple generic error page is shown.
    |
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Application URL
    |--------------------------------------------------------------------------
    |
    | This URL is used by the console to properly generate URLs when using
    | the Artisan command line tool. You should set this to the root of
    | the application so that it's available within Artisan commands.
    |
    */

    'url' => env('APP_URL', 'http://localhost'),


    'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000'),

    /*
    |--------------------------------------------------------------------------
    | Application Timezone
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default timezone for your application, which
    | will be used by the PHP date and date-time functions. The timezone
    | is set to "UTC" by default as it is suitable for most use cases.
    |
    */

    'timezone' => 'UTC',

    /*
    |--------------------------------------------------------------------------
    | Application Locale Configuration
    |--------------------------------------------------------------------------
    |
    | The application locale determines the default locale that will be used
    | by Laravel's translation / localization methods. This option can be
    | set to any locale for which you plan to have translation strings.
    |
    */

    'locale' => env('APP_LOCALE', 'ru'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'ru'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'ru_RU'),

    /*
    |--------------------------------------------------------------------------
    | Encryption Key
    |--------------------------------------------------------------------------
    |
    | This key is utilized by Laravel's encryption services and should be set
    | to a random, 32 character string to ensure that all encrypted values
    | are secure. You should do this prior to deploying the application.
    |
    */

    'cipher' => 'AES-256-CBC',

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Mode Driver
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the driver used to determine and
    | manage Laravel's "maintenance mode" status. The "cache" driver will
    | allow maintenance mode to be controlled across multiple machines.
    |
    | Supported drivers: "file", "cache", "array"
    |
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
