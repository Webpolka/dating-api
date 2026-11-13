<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Здесь регистрируются все broadcast-каналы приложения.
| Laravel вызывает эти замыкания когда клиент пытается подписаться на канал.
| Если вернуть true — подписка разрешена, false/null — запрещена.
|
*/

// ============================================
// ПРИВАТНЫЕ КАНАЛЫ ПОЛЬЗОВАТЕЛЕЙ
// ============================================
// Канал: private-user.{id}
// Используется для персональных ивентов: новый мэтч, новый лайк,
// новое сообщение (preview), онлайн-статус и т.д.
Broadcast::channel('user.{id}', function ($user, $id) {
    // Разрешаем слушать только СВОЙ канал
    // (int) приведение критично — ID из URL приходит строкой
    return (int) $user->id === (int) $id;
});

// ============================================
// ПРИВАТНЫЕ КАНАЛЫ ЧАТОВ
// ============================================
// Канал: private-chat.{chatId}
// Используется для realtime-сообщений в чате.
// Только участники чата могут слушать.
Broadcast::channel('chat.{chatId}', function ($user, $chatId) {
    // Проверяем что юзер — участник этого чата
    return $user->chats()
        ->where('chats.id', $chatId)
        ->exists();
});


// ============================================
// АДМИН-КАНАЛЫ
// ============================================
// Канал: private-admin.chats
// Используется для realtime-обновлений списка чатов юзеров в админке:
//   - new message в любом private-чате → invalidate list query → refetch
//     (обновится last_message, last_message_at, сортировка).
// Доступ: все staff (admin/moderator/support). Хотя по routes/admin.php
// /admin/chats доступен только admin+moderator — канал открыт всем staff,
// потому что support тоже могут хотеть видеть активность. Это не дыра —
// если support не имеет доступа к /admin/chats, фронт просто не подпишется.
Broadcast::channel('admin.chats', function ($user) {
    return $user !== null && $user->isStaff();
});


// ============================================
// АДМИН-КАНАЛЫ
// ============================================
// Канал: private-admin.transactions
// Используется для realtime-событий в финансовом разделе:
//   - transaction.refunded  (возврат обработан банком)
//   - transaction.refund_failed (банк отклонил возврат)
// Доступ: только ROLE_ADMIN. Модераторы и support не видят финансовые данные.
Broadcast::channel('admin.transactions', function ($user) {
    return $user !== null && $user->role === \App\Models\User::ROLE_ADMIN;
});


// Канал: private-admin.broadcasts
// Используется для realtime-обновлений статусов рассылок:
//   - broadcast.status.updated (старт/прогресс/завершение/ошибка)
// Доступ: только staff (admin/moderator/support). В отличие от финансов,
// рассылки видят все staff (модератор может следить за прогрессом).
Broadcast::channel('admin.broadcasts', function ($user) {
    return $user !== null && $user->isStaff();
});


// Канал: private-admin.support
// Используется для realtime-обновлений чата поддержки:
//   - support.message.received (новое сообщение в support-чате)
// Доступ: все staff (admin/moderator/support).
Broadcast::channel('admin.support', function ($user) {
    return $user !== null && $user->isStaff();
});
