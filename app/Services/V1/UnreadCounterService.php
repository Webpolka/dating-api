<?php

namespace App\Services\V1;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Сервис счётчиков непрочитанных сообщений.
 *
 * ВАЖНО: Все методы обёрнуты в try-catch.
 * При падении Redis:
 *  - increment / markRead / resetAll → silent (лог warning)
 *  - getCount / getTotal / getMap → возвращают 0 / пустой массив
 *
 * Это значит: чаты продолжат работать, но счётчики непрочитанных
 * могут быть неточными, пока Redis не восстановится.
 *
 * Рекомендация: добавить периодический sync из БД (cron-задача),
 * чтобы счётчики восстанавливались после сбоя Redis.
 */
class UnreadCounterService
{
    private const TTL = 604800; // 7 дней

    // ============================================
    // ЗАПИСЬ (модификация счётчиков)
    // ============================================

    /**
     * Увеличить счётчик непрочитанных для юзера в конкретном чате.
     */
    public function increment(int $userId, int $chatId): void
    {
        try {
            $hashKey = $this->messagesKey($userId);
            Redis::hincrby($hashKey, $chatId, 1);
            Redis::expire($hashKey, self::TTL);

            Redis::incrby($this->totalKey($userId), 1);
            Redis::expire($this->totalKey($userId), self::TTL);
        } catch (\Throwable $e) {
            Log::warning('UnreadCounterService: increment failed', [
                'user_id' => $userId,
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Отметить все сообщения в чате как прочитанные.
     */
    public function markRead(int $userId, int $chatId): void
    {
        try {
            $hashKey = $this->messagesKey($userId);
            $current = (int) Redis::hget($hashKey, $chatId);

            if ($current > 0) {
                Redis::hset($hashKey, $chatId, 0);

                $newTotal = Redis::incrby($this->totalKey($userId), -$current);
                if ($newTotal < 0) {
                    Redis::set($this->totalKey($userId), 0);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('UnreadCounterService: markRead failed', [
                'user_id' => $userId,
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Сбросить все счётчики юзера (например, при выходе из всех чатов).
     */
    public function resetAll(int $userId): void
    {
        try {
            Redis::del($this->messagesKey($userId));
            Redis::set($this->totalKey($userId), 0);
            Redis::expire($this->totalKey($userId), self::TTL);
        } catch (\Throwable $e) {
            Log::warning('UnreadCounterService: resetAll failed', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    // ============================================
    // ЧТЕНИЕ (получение счётчиков)
    // ============================================

    /**
     * Количество непрочитанных в конкретном чате.
     * При падении Redis возвращает 0.
     */
    public function getCount(int $userId, int $chatId): int
    {
        try {
            return (int) Redis::hget($this->messagesKey($userId), $chatId);
        } catch (\Throwable $e) {
            Log::warning('UnreadCounterService: getCount failed, returning 0', [
                'user_id' => $userId,
                'chat_id' => $chatId,
                'error'   => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Общее количество непрочитанных у юзера.
     * При падении Redis возвращает 0.
     */
    public function getTotal(int $userId): int
    {
        try {
            return max(0, (int) Redis::get($this->totalKey($userId)));
        } catch (\Throwable $e) {
            Log::warning('UnreadCounterService: getTotal failed, returning 0', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Карта [chat_id => unread_count] для юзера.
     * При падении Redis возвращает пустой массив.
     *
     * @return array<int, int>
     */
    public function getMap(int $userId): array
    {
        try {
            $data = Redis::hgetall($this->messagesKey($userId));
            return is_array($data) ? $data : [];
        } catch (\Throwable $e) {
            Log::warning('UnreadCounterService: getMap failed, returning empty', [
                'user_id' => $userId,
                'error'   => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Массовое получение общих счётчиков для списка юзеров.
     * При падении Redis возвращает пустой массив.
     *
     * @param array $userIds
     * @return array<int, int> [user_id => total_unread]
     */
    public function getTotalsForUsers(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        try {
            $responses = Redis::pipeline(function ($pipe) use ($userIds) {
                foreach ($userIds as $userId) {
                    $pipe->get($this->totalKey($userId));
                }
            });

            if (!is_array($responses)) {
                return [];
            }

            $result = [];
            foreach ($userIds as $index => $userId) {
                $value = $responses[$index] ?? 0;
                $result[$userId] = max(0, (int) $value);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('UnreadCounterService: getTotalsForUsers failed, returning empty', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    // ============================================
    // ВНУТРЕННИЕ МЕТОДЫ
    // ============================================

    private function messagesKey(int $userId): string
    {
        return "user:unread:messages:{$userId}";
    }

    private function totalKey(int $userId): string
    {
        return "user:unread:total:{$userId}";
    }
}
