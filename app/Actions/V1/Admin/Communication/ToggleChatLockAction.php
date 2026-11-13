<?php

namespace App\Actions\V1\Admin\Communication;

use App\Models\AdminLog;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Блокировка/разблокировка private-чата юзеров админом.
 *
 * Извлечено из Support\ChatController::toggleLock (S5-7).
 * Контроллер оставляет себе: разрешение модели (404), проверку type=private,
 * формирование JSON-ответа.
 */
class ToggleChatLockAction
{
    /**
     * Переключить is_locked чата + системное сообщение + AdminLog.
     *
     * Мутирует переданный $chat (is_locked, last_message_at) — после вызова
     * контроллер читает $chat->is_locked для ответа.
     */
    public function toggle(Chat $chat, User $admin): void
    {
        $beforeState            = (bool) $chat->getOriginal('is_locked');
        $beforeLastMsg          = $chat->getOriginal('last_message_at');
        $beforeLastMsgFormatted = $beforeLastMsg ? Carbon::parse($beforeLastMsg)->toDateTimeString() : null;

        DB::transaction(function () use ($chat, $admin, $beforeState, $beforeLastMsgFormatted) {
            // 1. Блокируем/разблокируем
            $chat->update(['is_locked' => !$chat->is_locked]);

            // 2. Пишем системное сообщение в ленту чата
            $systemMsgText = $chat->is_locked
                ? 'Чат заблокирован администрацией.'
                : 'Чат разблокирован администрацией.';

            $systemMessage = $chat->messages()->create([
                'sender_id' => null,
                'type'      => 'system',
                'body'      => $systemMsgText,
            ]);

            // 3. Обновляем время последнего сообщения тем же timestamp,
            // что и у системного сообщения — консистентность (раньше был
            // now() и created_at системного сообщения могли отличаться
            // на миллисекунды, что порождало несоответствие в сортировке).
            $chat->update(['last_message_at' => $systemMessage->created_at]);

            // 4. Логируем в AdminLog
            $participantIds = $chat->participants()->pluck('user_id')->toArray();

            AdminLog::record(
                action: $chat->is_locked ? 'chat.lock' : 'chat.unlock',
                model: $chat,
                admin: $admin,
                before: [
                    'is_locked'        => $beforeState,
                    'last_message_at'  => $beforeLastMsgFormatted,
                ],
                after: [
                    'is_locked'        => (bool) $chat->is_locked,
                    'last_message_at'  => $chat->last_message_at->toDateTimeString(),
                    'system_message'   => $systemMsgText,
                    'context' => [
                        'chat_id'      => $chat->id,
                        'admin_id'     => $admin->id,
                        'participants' => $participantIds,
                    ],
                ],
                participants: $participantIds,
            );
        });

        Cache::forget('admin_chat_stats');
    }
}
