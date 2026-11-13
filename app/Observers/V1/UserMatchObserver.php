<?php

namespace App\Observers;

use App\Events\NewMatch;
use App\Models\Chat;
use App\Models\User;
use App\Models\UserMatch;
use App\Notifications\NewMatchNotification;
use App\Services\UserSearchService;
use Illuminate\Support\Facades\Log;
use Throwable;

class UserMatchObserver
{
    public function __construct(
        private readonly UserSearchService $searchService
    ) {}

    public function created(UserMatch $match): void
    {
        Log::info('match.created', [
            'match_id' => $match->id,
            'user1_id' => $match->user1_id,
            'user2_id' => $match->user2_id,
            'status' => $match->status,
        ]);

        // 1. ИНВАЛИДАЦИЯ КЭША — после match юзеры не должны видеть друг друга
        //    в выдаче (они уже сматчились, дальше — чат).
        $this->searchService->invalidateUserCache($match->user1_id);
        $this->searchService->invalidateUserCache($match->user2_id);

        // 2. Загружаем юзеров с preferences (нужно для Notification)
        $user1 = User::with('preferences')->find($match->user1_id);
        $user2 = User::with('preferences')->find($match->user2_id);

        if (!$user1 || !$user2) {
            Log::error('match.users_not_found', ['match_id' => $match->id]);
            return;
        }

        // 3. Создаём чат (с защитой — если упадёт, не убьём весь observer)
        try {
            $chat = Chat::getOrCreateBetween($match->user1_id, $match->user2_id);
        } catch (Throwable $e) {
            Log::error('match.chat_creation_failed', [
                'match_id' => $match->id,
                'error' => $e->getMessage(),
            ]);
            return; // без chatId — нет смысла слать notification
        }

        // 4. Broadcast + Notification в отдельном try-catch
        //    (если упадут — матч уже создан, это не критично)
        try {
            $this->notifyUsers($match, $user1, $user2, $chat);
        } catch (Throwable $e) {
            Log::error('match.notification_failed', [
                'match_id' => $match->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    public function updated(UserMatch $match): void
    {
        if (!$match->wasChanged('status')) {
            return;
        }

        $oldStatus = $match->getOriginal('status');
        $newStatus = $match->status;

        // REMATCH: unmatched → active
        if ($oldStatus === UserMatch::STATUS_UNMATCHED && $newStatus === UserMatch::STATUS_ACTIVE) {
            Log::info('match.rematch', [
                'match_id' => $match->id,
                'user1_id' => $match->user1_id,
                'user2_id' => $match->user2_id,
            ]);

            // После rematch юзеры снова не должны видеть друг друга в выдаче
            $this->searchService->invalidateUserCache($match->user1_id);
            $this->searchService->invalidateUserCache($match->user2_id);

            try {
                broadcast(new NewMatch($match, $match->user1_id))->toOthers();
                broadcast(new NewMatch($match, $match->user2_id))->toOthers();
            } catch (Throwable $e) {
                Log::error('match.rematch_broadcast_failed', [
                    'match_id' => $match->id,
                    'error' => $e->getMessage(),
                ]);
            }
            return;
        }

        // UNMATCH: active → unmatched
        // Юзеры снова могут видеть друг друга в выдаче (раз мэтч разорван).
        if ($oldStatus === UserMatch::STATUS_ACTIVE && $newStatus === UserMatch::STATUS_UNMATCHED) {
            Log::info('match.unmatched', [
                'match_id' => $match->id,
                'user1_id' => $match->user1_id,
                'user2_id' => $match->user2_id,
            ]);

            $this->searchService->invalidateUserCache($match->user1_id);
            $this->searchService->invalidateUserCache($match->user2_id);
        }
    }

    /**
     * Отправка broadcast + notification обоим юзерам.
     */
    private function notifyUsers(
        UserMatch $match,
        User $user1,
        User $user2,
        Chat $chat
    ): void {
        // Realtime
        broadcast(new NewMatch($match, $match->user1_id))->toOthers();
        broadcast(new NewMatch($match, $match->user2_id))->toOthers();

        // Notification (email + push + database)
        $user1->notify(new NewMatchNotification(
            partnerId: $match->user2_id,
            partnerName: $user2->name,
            chatId: $chat->id,
        ));

        $user2->notify(new NewMatchNotification(
            partnerId: $match->user1_id,
            partnerName: $user1->name,
            chatId: $chat->id,
        ));

        Log::info('match.notifications_sent', [
            'match_id' => $match->id,
            'user1_id' => $match->user1_id,
            'user2_id' => $match->user2_id,
        ]);
    }
}