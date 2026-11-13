<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class LoadUserRelations
{
    /**
     * Жадно грузим настройки, профиль и фото для авторизованного юзера,
     * чтобы не было N+1 в шапке сайта (тема, локаль, аватарка).
     *
     * ВАЖНО про photos:
     *   Раньше грузились ВСЕ фото юзера — это может быть 20-50 строк на каждый запрос,
     *   плюс на странице поиска 20 карточек = 400-1000 строк photos в памяти.
     *
     *   Теперь: limit(1) + where('status', 'approved') + orderByDesc('is_primary')
     *   → одна строка с primary фото (или первое approved).
     *
     * ВАЖНО про profile.city:
     *   В шапке сайта показываем "Москва" — раньше это был отдельный запрос на каждый
     *   показ шапки (N+1). Теперь: profile.city загружается одним JOIN'ом.
     *
     * ВАЖНО про interests:
     *   Интересы НЕ грузим здесь — они нужны только в специфичных местах (страница
     *   редактирования профиля, страница матчей). Если загрузить здесь — лишний JOIN
     *   на каждый запрос. Лучше lazy-load через $user->load('interests') где нужно.
     *
     * ВАЖНО про photos_count:
     *   НЕ используем withCount здесь — он добавляет подзапрос COUNT на каждый запрос.
     *   Если нужно количество фото — лучше вынести в отдельный accessor с кэшированием,
     *   или загружать только на странице профиля.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $user = Auth::user();

            // Защита от дабл-лоада (если middleware вызывается дважды в одном запросе).
            // loadMissing уже это делает, но проверка дешевле чем повторный вызов.
            if (!$user->relationLoaded('preferences')) {
                $user->loadMissing([
                    // Настройки (locale, theme, push_enabled, email_enabled, и т.д.)
                    // Используются в шапке и для логики уведомлений.
                    'preferences',

                    // Профиль + город (для шапки "Москва", и для логики visibility).
                    // ВАЖНО: city идёт nested — это profile.city, не отдельная связь на User.
                    'profile.city',

                    // Баланс (для премиум-виджетов, показа монет).
                    'balance',

                    // Фото — только одно (primary или первое approved).
                    // limit(1) внутри closure — это ограничение на уровне SQL.
                    'photos' => fn($q) => $q->where('status', 'approved')
                        ->orderByDesc('is_primary')
                        ->limit(1),
                ]);
            }
        }

        return $next($request);
    }
}