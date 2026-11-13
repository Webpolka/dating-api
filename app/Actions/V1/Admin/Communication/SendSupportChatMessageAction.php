<?php

namespace App\Actions\V1\Admin\Communication;

use App\Events\SupportChatUnarchived;
use App\Models\AdminLog;
use App\Models\Chat;
use App\Models\ChatParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Отправка сообщения от лица staff в support-чат.
 *
 * Извлечено из SupportChatController::sendMessage (S5-7).
 * Контроллер оставляет себе: разрешение модели (404), проверку is_locked (422),
 * валидацию и формирование JSON-ответа.
 */
class SendSupportChatMessageAction
{
    /**
     * Отправить сообщение от лица админа в чат поддержки.
     *
     * Если чат был в архиве — AUTO-UNARCHIVE (reopen тикета).
     * После отправки:
     *   - chats.last_message_at = now() (для сортировки списка)
     *   - chats.staff_unread_count = 0, staff_last_read_* обновляются
     *     (админ сам прочитал своё сообщение)
     *   - юзеру chat_participants.unread_count++ (для notification юзеру)
     *
     * @return bool Был ли чат авто-разархивирован (auto_unarchive_on_reply).
     */
    public function send(Chat $chat, User $admin, string $body): bool
    {
        $wasArchived = (bool) $chat->is_archived;
        // Берём user_id именно юзера (НЕ-staff), а не первого participant.
        // В старых чатах (до рефактора) в participants может быть [admin, user] —
        // value('user_id') без фильтра мог вернуть admin. whereHas по role
        // гарантирует что получим именно юзера.
        $userId = $chat->participants()
            ->whereHas('user', fn($q) => $q->whereNotIn('role', ['admin', 'moderator', 'support']))
            ->value('user_id');

        DB::transaction(function () use ($chat, $admin, $body) {
            Message::create([
                'chat_id'   => $chat->id,
                'sender_id' => $admin->id,
                'type'      => 'text',
                'body'      => $body,
            ]);

            // Обновляем chats.last_message_at + сбрасываем staff state
            // (админ сам прочитал своё сообщение). Если чат был в архиве —
            // auto-unarchive (is_archived = false).
            $chat->update([
                'last_message_at'       => now(),
                'staff_unread_count'    => 0,
                'staff_last_read_at'    => now(),
                'staff_last_read_by_id' => $admin->id,
                'is_archived'           => false, // auto-unarchive если был
            ]);

            // Юзеру increment unread_count — для notification юзеру
            // (email/push/колокольчик на вебе).
            // Фильтр по whereHas('user', non-staff) — чтобы не increment'нуть
            // admin records в старых чатах (если они там остались).
            ChatParticipant::where('chat_id', $chat->id)
                ->whereHas('user', fn($q) => $q->whereNotIn('role', ['admin', 'moderator', 'support']))
                ->increment('unread_count');
        });

        // Аудит отправки
        AdminLog::record(
            action: 'support.message_sent',
            model: $chat,
            admin: $admin,
            before: null,
            after: [
                'message_sent' => true,
                'auto_unarchived' => $wasArchived,
                'context' => [
                    'chat_id'  => $chat->id,
                    'user_id'  => $userId,
                    'admin_id' => $admin->id,
                    'snippet'  => Str::limit($body, 50),
                ],
            ],
            participants: array_filter([$admin->id, $userId]),
        );

        // Если был auto-unarchive — сообщим другим staff через broadcast.
        // (Обычная отправка сообщения уже стреляет SupportMessageReceived
        // из MessageObserver — это обновит last_message в списке.)
        if ($wasArchived) {
            broadcast(new SupportChatUnarchived(
                chatId: $chat->id,
                unarchivedById: $admin->id,
                unarchivedByName: $admin->name,
                unarchivedAt: now()->toIso8601String(),
                reason: 'auto_unarchive_on_reply',
            ))->toOthers();
        }

        Cache::forget('admin_support_stats');
        Cache::forget('admin_sidebar_stats');

        return $wasArchived;
    }
}
