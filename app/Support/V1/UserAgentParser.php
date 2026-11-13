<?php

namespace App\Support;

/**
 * Лёгкий парсер User-Agent без внешних зависимостей.
 *
 * Для прода при необходимости можно заменить на jenssegers/agent
 * или donatj/phpuseragentparser — интерфейс останется тот же.
 */
class UserAgentParser
{
    public static function parse(?string $ua): array
    {
        $device = 'Компьютер';
        $isMobile = false;

        if ($ua) {
            if (preg_match('/(iPad)/i', $ua)) {
                $device = 'iPad';
                $isMobile = true;
            } elseif (preg_match('/(iPhone)/i', $ua)) {
                $device = 'iPhone';
                $isMobile = true;
            } elseif (preg_match('/(Android)/i', $ua)) {
                $device = 'Android';
                $isMobile = true;
            }
        }

        $browser = 'Браузер';
        if ($ua) {
            if (preg_match('/(Edg)/i', $ua)) {
                $browser = 'Edge';
            } elseif (preg_match('/(OPR|Opera)/i', $ua)) {
                $browser = 'Opera';
            } elseif (preg_match('/(Chrome|CriOS)/i', $ua)) {
                $browser = 'Chrome';
            } elseif (preg_match('/(Firefox|FxiOS)/i', $ua)) {
                $browser = 'Firefox';
            } elseif (preg_match('/(Safari)/i', $ua)) {
                $browser = 'Safari';
            }
        }

        return [
            'device'   => $device,
            'browser'  => $browser,
            'is_mobile' => $isMobile,
        ];
    }
}
