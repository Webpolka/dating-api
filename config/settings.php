<?php

return [
    'site_name' => [
        'default' => 'FlirtLove',
        'group' => 'general',
        'label' => 'Название сайта',
        'description' => 'Отображается в шапке сайта и во вкладке браузера',
        'type' => 'text',
        'is_public' => true,
    ],
    'site_description' => [
        'default' => 'Сайт знакомств для серьезных отношений',
        'group' => 'general',
        'label' => 'Описание сайта',
        'description' => 'Meta description для SEO',
        'type' => 'text',
        'is_public' => true,
    ],
    'contact_email' => [
        'default' => 'support@flirtlove.ru',
        'group' => 'general',
        'label' => 'Email поддержки',
        'description' => 'Адрес, на который пользователи пишут жалобы',
        'type' => 'text',
        'is_public' => true,
    ],
    'default_locale' => [
        'default' => 'ru',
        'group' => 'general',
        'label' => 'Язык по умолчанию',
        'description' => 'Код локали (ru, en)',
        'type' => 'text',
        'is_public' => true,
    ],

    // ===== Лимиты (Дейтинг) =====
    'likes_per_day_free' => [
        'default' => '30',
        'group' => 'limits',
        'label' => 'Лайков в день (Бесплатно)',
        'description' => 'Сколько свайпов вправо может делать юзер без VIP',
        'type' => 'integer',
        'is_public' => true,
    ],
    'likes_per_day_premium' => [
        'default' => '100',
        'group' => 'limits',
        'label' => 'Лайков в день (VIP)',
        'description' => 'Сколько свайпов вправо может делать юзер с VIP',
        'type' => 'integer',
        'is_public' => true,
    ],
    'free_superlikes_per_day' => [
        'default' => '1',
        'group' => 'limits',
        'label' => 'Суперлайков в день (Бесплатно)',
        'description' => 'Лимит суперлайков для обычных юзеров',
        'type' => 'integer',
        'is_public' => false,
    ],
    'premium_superlikes_per_day' => [
        'default' => '5',
        'group' => 'limits',
        'label' => 'Суперлайков в день (VIP)',
        'description' => 'Лимит суперлайков для VIP-юзеров',
        'type' => 'integer',
        'is_public' => false,
    ],

    // ===== Модерация =====
    'max_photos_per_user' => [
        'default' => '10',
        'group' => 'moderation',
        'label' => 'Максимум фото на пользователя',
        'description' => 'Лимит фотографий в профиле',
        'type' => 'integer',
        'is_public' => false,
    ],
    'moderation_auto_approve' => [
        'default' => '0',
        'group' => 'moderation',
        'label' => 'Авто-одобрение фото',
        'description' => 'Публиковать фото сразу без проверки модератором (0 - нет, 1 - да)',
        'type' => 'boolean',
        'is_public' => false,
    ],
    'require_moderation_for_new_users' => [
        'default' => '1',
        'group' => 'moderation',
        'label' => 'Модерация для новых пользователей',
        'description' => 'Отправлять ли анкеты новичков на ручную проверку',
        'type' => 'boolean',
        'is_public' => false,
    ],

    // ===== Мобильные приложения =====
    'appstore_url' => [
        'default' => 'https://vk.com/durov',
        'group' => 'store',
        'label' => 'App Store',
        'description' => 'Ссылка на приложение в App Store',
        'type' => 'text',
        'is_public' => true,
    ],
    'googleplay_url' => [
        'default' => 'https://vk.com/durov',
        'group' => 'store',
        'label' => 'Google Play',
        'description' => 'Ссылка на приложение в Google Play',
        'type' => 'text',
        'is_public' => true,
    ],

    // ===== Безопасность =====
    'min_password_length' => [
        'default' => '8',
        'group' => 'security',
        'label' => 'Минимальная длина пароля',
        'description' => 'При регистрации и смене пароля',
        'type' => 'integer',
        'is_public' => false,
    ],
    'max_login_attempts' => [
        'default' => '5',
        'group' => 'security',
        'label' => 'Максимум попыток входа',
        'description' => 'Лимит неверных вводов пароля до блокировки (Throttle)',
        'type' => 'integer',
        'is_public' => false,
    ],

    // ===== Социальные сети =====
    'vkontakte_url' => [
        'default' => 'https://vk.com/durov',
        'group' => 'social',
        'label' => 'Вконтаке',
        'description' => 'Ссылка на официальную страницу',
        'type' => 'text',
        'is_public' => true,
    ],
    'odnoklassniki_url' => [
        'default' => 'https://ok.ru',
        'group' => 'social',
        'label' => 'Одноклассники',
        'description' => 'Ссылка на официальную страницу',
        'type' => 'text',
        'is_public' => true,
    ],
    'telegram_url' => [
        'default' => 'https://t.me/durov',
        'group' => 'social',
        'label' => 'Telegram',
        'description' => 'Ссылка на официальную страницу',
        'type' => 'text',
        'is_public' => true,
    ],  

    // ===== Рассылки =====
    'broadcast_max_per_day' => [
        'default' => '3',
        'group' => 'broadcast',
        'label' => 'Максимум рассылок в день',
        'description' => 'Защита от спама юзеров от администрации',
        'type' => 'integer',
        'is_public' => false,
    ],
];