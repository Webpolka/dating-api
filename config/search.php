<?php

return [
    // Пагинация
    'per_page' => env('SEARCH_PER_PAGE', 20),
    'max_per_page' => env('SEARCH_MAX_PER_PAGE', 50),

    // Возрастные границы (по закону для дейтинг-сайтов)
    'age_min' => 18,
    'age_max' => 99,

    // Геолокация
    'default_distance_km' => 50,
    'max_distance_km' => 1000,

    // "Новые анкеты" — за сколько дней считать новыми
    'new_users_days' => 7,

    // Кэш
    'cache_ttl' => env('SEARCH_CACHE_TTL', 60),

    // Online порог (минут)
    'online_threshold_minutes' => 5,
    'recently_active_hours' => 24,
];