<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    // Путь /api/* и /sanctum/csrf-cookie — стандартные для Sanctum SPA auth.
    // Если появятся другие публичные эндпоинты (например, /webhook/* от
    // платёжки), добавлять сюда.
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    // Явный список HTTP-методов вместо ['*'].
    // ['*'] включает TRACE, CONNECT, HEAD — нам не нужно.
    // HEAD технически работает для всех GET-роутов, но браузеры не шлют
    // cross-origin HEAD — нет смысла его явно разрешать.
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // Origins через env, чтобы прод легко настраивался без правки кода.
    // В env — comma-separated список: "https://example.com,https://admin.example.com"
    // Дефолт — localhost для dev-режима (веб:3000, админка:3001, оба с 127.0.0.1).
    //
    // ВАЖНО: с supports_credentials=true НЕЛЬЗЯ использовать '*' или
    // wildcard-домены — браузеры это блокируют. Только явные origin'ы.
    'allowed_origins' => array_values(array_filter(explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:3000,http://localhost:3001,http://127.0.0.1:3000,http://127.0.0.1:3001'
    )))),

    // allowed_origins_patterns — пусто. Regex-паттерны для origin'ов.
    // Используем только если нужны wildcard-поддомены (*.example.com).
    // С supports_credentials=true браузеры не примут wildcard в любом случае —
    // придётся явно перечислять поддомены в CORS_ALLOWED_ORIGINS.
    'allowed_origins_patterns' => [],

    // Явный список хедеров вместо ['*'].
    // Это хедеры, которые фронтенд МОЖЕТ послать в запросе.
    // Authorization и X-Requested-With — для Sanctum + axios.
    // X-XSRF-TOKEN — axios сам подставляет из куки (withXSRFToken: true).
    // Content-Type, Accept — стандартные.
    // Если фронт начнёт слать кастомные хедеры (X-Locale, X-Client-Version),
    // добавлять сюда.
    'allowed_headers' => [
        'Authorization',
        'Content-Type',
        'Accept',
        'X-Requested-With',
        'X-XSRF-TOKEN',
        'X-CSRF-TOKEN',
    ],

    // exposed_headers — хедеры, которые бэк ОТДАЁТ фронту и которые
    // фронт сможет прочитать через xhr.getResponseHeader().
    // Пусто — стандартные хедеры браузер и так показывает (Content-Type, Content-Length, …).
    'exposed_headers' => [],

    // max_age=0 — каждый запрос сразу летит как actual request, без preflight-кэша.
    // Для прод лучше поставить 86400 (1 день) — браузер закэширует preflight
    // ответ на 24ч, меньше лишних OPTIONS-запросов. Пока dev — 0.
    'max_age' => env('CORS_MAX_AGE', 0),

    // ОБЯЗАТЕЛЬНО true — без этого Sanctum cookie-auth не работает (браузер
    // не примет куку Set-Cookie из cross-origin ответа без этого флага).
    'supports_credentials' => true,
];
