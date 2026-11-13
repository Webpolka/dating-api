<?php

namespace App\Observers;

use App\Enums\FraudAlertSeverity;
use App\Enums\FraudAlertStatus;
use App\Enums\FraudTriggerType;
use App\Events\ChatMessageReceived;
use App\Events\NewMessage;
use App\Events\SupportMessageReceived;
use App\Models\Chat;
use App\Models\FraudAlert;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use App\Services\ContentFilterService;
use App\Services\UnreadCounterService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MessageObserver
{
    public function __construct(
        private readonly ContentFilterService $contentFilter,
    ) {}

    /**
     * Проверка текста ПЕРЕД созданием сообщения.
     *
     * ContentFilterService проверяет body:
     *   - reject-слова → return false (отменяем создание, вернёт 422 юзеру)
     *   - mask-слова → маскируем body (заменяем на ***)
     *   - alert-слова → после создания (в created hook) генерируем FraudAlert
     *
     * ВАЖНО: creating hook срабатывает ДО save. Если return false —
     * создание отменяется, message не сохраняется. Контроллер получит
     * исключение или просто не увидит запись — зависит от вызывающего кода.
     * Для API-запросов лучше проверять в контроллере, но observer —
     * safety net на случай если кто-то создаст Message напрямую.
     */
    public function creating(Message $message): void
    {
        // System messages не проверяем — они от системы, не от юзера
        if ($message->type === 'system') {
            return;
        }

        // Проверяем только text-сообщения с body
        $body = $message->body;
        if (!$body || trim($body) === '') {
            return;
        }

        $result = $this->contentFilter->check($body);

        // Если найдены reject-слова — отменяем создание
        if ($result->hasReject) {
            Log::info('Message rejected by ContentFilter', [
                'sender_id' => $message->sender_id,
                'chat_id' => $message->chat_id,
                'matched_words' => array_column($result->matched, 'word'),
            ]);
            // Return false отменяет save. Но creating hook возвращает void в
            // новых Laravel — нужно бросать исключение.
            throw new \Illuminate\Validation\ValidationException(
                validator: validator(['body' => $body], ['body' => 'required']),
                response: response()->json([
                    'message' => 'Сообщение содержит запрещённые слова.',
                    'errors' => ['body' => ['Сообщение отклонено модерационным фильтром.']],
                ], 422)
            );
        }

        // Маскируем body (если mask-слова найдены)
        if ($result->maskedText !== $body) {
            $message->body = $result->maskedText;
            Log::info('Message masked by ContentFilter', [
                'sender_id' => $message->sender_id,
                'chat_id' => $message->chat_id,
                'masked_count' => count(array_filter($result->matched, fn($m) => $m['action'] === 'mask')),
            ]);
        }

        // Сохраняем alerts для обработки в created hook (после save)
        // Передаём через temporary attribute — Laravel не сохранит его в БД,
        // но оно доступно в created() через $message->contentFilterAlerts
        $message->contentFilterAlerts = $result->alerts;
    }

    public function created(Message $message): void
    {
        // 1. Realtime broadcast на private-chat.{chatId} — для получателя (юзера).
        //    Используется и в private, и в support-чатах (юзер слушает свой чат).
        $this->broadcastMessage($message);

        // 2. Notification + Increment unread — пер-case в зависимости от типа чата
        $this->handleUnreadAndNotify($message);

        // 3. Admin-уведомления:
        //    - support-чат → broadcast на admin.support (обновит список тикетов)
        //    - private-чат → broadcast на admin.chats (обновит список чатов юзеров)
        $this->broadcastAdminNotification($message);

        // 4. FraudAlerts — если ContentFilter нашёл alert-слова в creating hook
        $this->createFraudAlertsFromMessage($message);
    }

    /**
     * Создать FraudAlert записи для alert-слов найденных ContentFilter.
     * Вызывается после save (created hook) — message уже в БД, можно ссылаться.
     *
     * Маппинг category стоп-слова → FraudTriggerType:
     *   - prostitution → Prostitute (точное совпадение)
     *   - scam         → ScamPhrase (точное совпадение)
     *   - mat/drugs/contacts → ScamPhrase (fallback — нет отдельных
     *     trigger_type для мат/наркотиков/контактов, используем ScamPhrase
     *     как универсальный «подозрительный контент»)
     *
     * ВАЖНО: НЕ добавляем StopWordAlert в FraudTriggerType enum —
     * enum используется как карта для рендера в dropdown'ах фронта
     * (options() метод). Системные trigger_type которых нет в enum —
     * нельзя выбрать вручную, что правильно.
     *
     * Status: Open (новый алерт = открыт). Enum FraudAlertStatus имеет
     * только Open/Resolved/FalsePositive — нет 'pending'.
     */
    private function createFraudAlertsFromMessage(Message $message): void
    {
        $alerts = $message->contentFilterAlerts ?? [];
        if (empty($alerts)) {
            return;
        }

        foreach ($alerts as $alertData) {
            try {
                // trigger_type = StopWordTrigger для ВСЕХ alert-слов.
                // Точная категория стоп-слова (mat/drugs/contacts/scam/prostitution)
                // сохраняется в meta.category — фронт показывает её в деталях алерта.
                // Не пытаемся маппить category → разные FraudTriggerType — это был
                // кривой костыль (мат → ScamPhrase? наркотики → ScamPhrase? нет).
                // StopWordTrigger — честный trigger_type: «алерт сгенерирован
                // ContentFilter по стоп-слову». Категория — в meta.
                FraudAlert::create([
                    'user_id'      => $message->sender_id,
                    'trigger_type' => FraudTriggerType::StopWordTrigger,
                    'severity'     => FraudAlertSeverity::Medium,
                    'status'       => FraudAlertStatus::Open,
                    'meta'         => [
                        'message_id'      => $message->id,
                        'chat_id'         => $message->chat_id,
                        'stop_word_id'    => $alertData['stop_word_id'],
                        'word'            => $alertData['word'],
                        'category'        => $alertData['category'],
                        'message_snippet' => mb_substr($message->body ?? '', 0, 100),
                        'auto_generated'  => true,
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning('Failed to create FraudAlert from ContentFilter', [
                    'message_id' => $message->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Realtime broadcast на private-chat.{chatId} (Reverb).
     * Используется как юзерской, так и админской стороной.
     * Если упадёт — не блокирует остальное.
     */
    private function broadcastMessage(Message $message): void
    {
        try {
            broadcast(new NewMessage($message));
        } catch (\Throwable $e) {
            Log::warning('Broadcast NewMessage failed (non-blocking)', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notification + Increment unread — раздельно для private и support.
     *
     * PRIVATE чат:
     *   - Получатель = другой участник (юзер).
     *   - Redis unread counter для получателя (online status).
     *   - Email/push получателю (если хочет).
     *
     * SUPPORT чат:
     *   - Если отправитель = ЮЗЕР → increment chats.staff_unread_count
     *     (глобально для staff). Email/push — ТОЛЬКО юзеру (не spam'им staff'у,
     *     они увидят badge в сайдбаре через broadcast на admin.support).
     *     Redis unread counter для юзера — НЕ нужен (юзер сам отправил).
     *   - Если отправитель = STAFF → НЕ increment staff_unread_count
     *     (он уже обнулён в SupportChatController::sendMessage).
     *     Юзеру Redis unread + email/push (если хочет).
     *
     * ВАЖНО: support-чаты НЕ слать email/push STAFF'у — это была бы масса
     * спама (все staff'ы получили бы email на каждое сообщение юзера).
     * In-app broadcast (admin.support) — единственный канал для staff.
     * Решение пользователя: «только в колокольчик, без email».
     */
    private function handleUnreadAndNotify(Message $message): void
    {
        $chat = Chat::with('participants.user')->find($message->chat_id);
        if (!$chat) {
            return;
        }

        if ($chat->type === Chat::TYPE_SUPPORT) {
            $this->handleSupportMessage($message, $chat);
        } else {
            $this->handlePrivateMessage($message, $chat);
        }
    }

    /**
     * Private-чат: получатель = другой участник.
     */
    private function handlePrivateMessage(Message $message, Chat $chat): void
    {
        $receiverParticipant = $chat->participants->firstWhere('user_id', '!=', $message->sender_id);
        if (!$receiverParticipant || !$receiverParticipant->user) {
            return;
        }

        $receiver = $receiverParticipant->user;

        // === INCREMENT UNREAD COUNTER (REDIS) ===
        $this->incrementRedisUnread($receiver->id, $message->chat_id, $message->id);

        // === NOTIFICATION (email/push/database) ===
        $this->sendNotificationToUser($message, $receiver);
    }

    /**
     * Support-чат: поведение зависит от того кто отправитель — staff или юзер.
     */
    private function handleSupportMessage(Message $message, Chat $chat): void
    {
        $sender = User::withTrashed()->find($message->sender_id);
        $senderIsStaff = $sender?->isStaff() ?? false;

        // === STAFF → USER (админ отвечает юзеру) ===
        // staff_unread_count уже обнулён в SupportChatController::sendMessage,
        // здесь повторять не надо. Но на всякий случай — если сообщение создано
        // не через SupportChatController (например через tinker, observer, и т.д.),
        // обнулим здесь тоже.
        if ($senderIsStaff) {
            try {
                $chat->update([
                    'staff_unread_count'    => 0,
                    'staff_last_read_at'    => now(),
                    'staff_last_read_by_id' => $sender->id,
                ]);
                Cache::forget('admin_support_stats');
                Cache::forget('admin_sidebar_stats');
            } catch (\Throwable $e) {
                Log::warning('Failed to reset staff_unread_count', [
                    'chat_id' => $chat->id,
                    'error'   => $e->getMessage(),
                ]);
            }

            // Юзеру — Redis unread + email/push (для notification юзеру).
            // Берём НЕ-staff participant (юзера). В старых чатах (до рефактора)
            // в participants могло быть [admin, user] — first() без фильтра
            // мог вернуть admin и отправить ему же notification о его сообщении.
            $userParticipant = $chat->participants->first(fn($p) => $p->user && !$p->user->isStaff());
            if ($userParticipant && $userParticipant->user) {
                $this->incrementRedisUnread($userParticipant->user_id, $message->chat_id, $message->id);
                $this->sendNotificationToUser($message, $userParticipant->user);
            }
            return;
        }

        // === USER → STAFF (юзер пишет в поддержку) ===
        // Increment ГЛОБАЛЬНОГО staff_unread_count в chats.
        // Email/push staff'у НЕ шлём (только in-app broadcast через admin.support).
        try {
            $chat->increment('staff_unread_count');
            Cache::forget('admin_support_stats');
            Cache::forget('admin_sidebar_stats');
        } catch (\Throwable $e) {
            Log::warning('Failed to increment staff_unread_count', [
                'chat_id' => $chat->id,
                'error'   => $e->getMessage(),
            ]);
        }

        // Юзеру Redis unread НЕ нужен — он сам отправил, ничего не "непрочитано" для него.
        // Email/push юзеру тоже НЕ нужен — он сам написал, не надо ему уведомление о своём же сообщении.
    }

    /**
     * Increment Redis unread counter для юзера (для online-status realtime).
     */
    private function incrementRedisUnread(int $receiverId, int $chatId, int $messageId): void
    {
        try {
            app(UnreadCounterService::class)->increment($receiverId, $chatId);
        } catch (\Throwable $e) {
            Log::warning('Failed to increment unread counter', [
                'receiver_id' => $receiverId,
                'chat_id'     => $chatId,
                'message_id'  => $messageId,
                'error'       => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send email/push/database notification юзеру (если хочет — см. preferences).
     */
    private function sendNotificationToUser(Message $message, User $receiver): void
    {
        try {
            $senderName = User::find($message->sender_id)?->name;

            $receiver->notify(new NewMessageNotification(
                chatId: $message->chat_id,
                messageId: $message->id,
                senderId: $message->sender_id,
                senderName: $senderName,
                messageType: $message->type,
                messageBody: $message->body,
            ));
        } catch (\Throwable $e) {
            Log::error('Notification failed', [
                'message_id' => $message->id,
                'receiver_id' => $receiver->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Admin-уведомления через WebSocket.
     *
     * Для SUPPORT-чатов → broadcast SupportMessageReceived на admin.support
     *   (обновит список тикетов в админке — last_message, staff_unread_count, сортировка).
     *
     * Для PRIVATE-чатов (user↔user) → broadcast ChatMessageReceived на admin.chats
     *   (обновит список чатов юзеров в админке — last_message, last_message_at, сортировка).
     *
     * Стреляется для ВСЕХ сообщений (включая system messages от lock/unlock).
     * Это norm — system message тоже меняет last_message_at → список должен обновиться.
     *
     * ShouldBroadcastNow + afterCommit — срабатывает сразу после коммита транзакции.
     * Если упадёт — не блокирует остальное (try-catch).
     */
    private function broadcastAdminNotification(Message $message): void
    {
        try {
            $chat = Chat::find($message->chat_id);
            if (!$chat) {
                return;
            }

            $sender = User::withTrashed()->find($message->sender_id);

            if ($chat->type === Chat::TYPE_SUPPORT) {
                broadcast(new SupportMessageReceived(
                    chatId: $message->chat_id,
                    senderId: $message->sender_id,
                    senderName: $sender?->name ?? 'Неизвестно',
                    senderIsStaff: $sender?->isStaff() ?? false,
                    messageType: $message->type,
                    messageBody: $message->body,
                    createdAt: $message->created_at?->toIso8601String(),
                ));
                return;
            }

            if ($chat->type === Chat::TYPE_PRIVATE) {
                broadcast(new ChatMessageReceived(
                    chatId: $message->chat_id,
                    senderId: $message->sender_id,
                    senderName: $sender?->name ?? 'Неизвестно',
                    messageType: $message->type,
                    messageBody: $message->body,
                    createdAt: $message->created_at?->toIso8601String(),
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('Broadcast admin notification failed (non-blocking)', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
