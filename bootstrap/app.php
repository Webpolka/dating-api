<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\V1\LoadUserRelations;
use App\Http\Middleware\V1\AdminRoleMiddleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        channels: __DIR__ . '/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {

        // Доверяем всем прокси (Cloudflare/Nginx) — Laravel будет
        // читать реальные IP юзеров из X-Forwarded-For.
        // ВАЖНО: если за Cloudflare, в проде лучше явно перечислить
        // IP Cloudflare вместо '*'. Но для старта '*' — нормально.
        $middleware->trustProxies(at: '*');
        // $middleware->trustProxies(at: [
        //     '127.0.0.1',           // локальный Nginx
        //     '10.0.0.5',            // внутренний IP Nginx-сервера
        //     // IP Cloudflare можно найти тут: https://www.cloudflare.com/ips/
        // ]);

        $middleware->web(append: [

            LoadUserRelations::class,
        ]);

        // Добавляем наш middleware в API группу (в начало)
        $middleware->api(append: [
            \App\Http\Middleware\V1\UpdateOnlineStatus::class,
        ]);

        // Также добавляем в web группу (на случай если есть web-роуты с auth)
        $middleware->web(append: [
            \App\Http\Middleware\V1\UpdateOnlineStatus::class,
        ]);

        // регистрируем алиас для middleware
        $middleware->alias([
            'admin.role' => AdminRoleMiddleware::class,
        ]);

        // "Route [login] not defined" (500 вместо 401 на /api/me).
        // Laravel 11-13 по умолчанию строит редирект гостей через
        // route('login') — такого роута в API-приложении нет, и исключение
        // падает прямо в middleware, ДО логики JSON-рендера. Наш логин
        // живёт на Next: для API/JSON отдаём null → 401 JSON (его подхватит
        // shouldRenderJsonWhen ниже), для гипотетических web-страниц —
        // страница логина фронтенда.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            return rtrim(env('FRONTEND_URL', 'http://localhost:3001'), '/') . '/login';
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn(Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })

    ->create();
