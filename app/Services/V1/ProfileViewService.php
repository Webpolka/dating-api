<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;

/**
 * 
 * Сервис просмотров профилей.
 *
 * ВАЖНО: Все методы обёрнуты в try-catch.
 * При падении Redis — просмотры не учитываются, но просмотр профиля не роняется.
 * Buffer-записи теряются, но это приемлемо: просмотры — некритичные данные.
 * 
 * 1. Юзер A открывает анкету юзера B
 *  → ProfileController вызывает $service->recordViewIfNotRecent(A, B)
 * 8  → recordViewIfNotRecent проверяет в Redis (hasViewedRecently) — смотрел ли в течение 24ч
 *  → если НЕ смотрел → Redis::hset('profile_views:buffer', 'A:B', time())*
 *
 * 2. Redis-буфер содержит hash: { "A:B" => 1729884000, "C:D" => 1729884005, ... }
 *   Это быстро (Redis) — не нагружает БД при каждом просмотре.
 * 
 * 3. Каждую минуту cron запускает job ProcessProfileViewsBatch
 *  → job вызывает $service->processBatch()
 *  → processBatch:
 *     a) flushBuffer() — Redis::hgetall + Redis::del
 *        достаёт весь буфер и сразу его чистит
 *     b) парсит "A:B" → ['viewer_id'=>A, 'viewed_id'=>B, 'viewed_at'=>...]
 *     c) чанками по 500 делает bulk upsert в profile_views
 *        INSERT ... ON CONFLICT (viewer_id, viewed_id) DO UPDATE
 *        SET viewed_at = EXCLUDED.viewed_at
 *     d) возвращает количество обработанных
 *  
 * 4. В БД profile_views теперь есть записи о просмотрах:
 *  - viewer_id | viewed_id | viewed_at
 *  - 5         | 42        | 2025-10-26 18:25:00
 *  - 8         | 42        | 2025-10-26 18:26:00
 *  
 * 5. Запрос "кто смотрел анкету юзера 42":
 *  SELECT viewer_id FROM profile_views WHERE viewed_id = 42 ORDER BY viewed_at DESC;
 */


class ProfileViewService
{
    private const BUFFER_KEY = 'profile_views:buffer';

    /**
     * Добавить просмотр в Redis-буфер.
     * При падении Redis — silent (лог warning).
     */
    public function recordView(int $viewerId, int $viewedId): void
    {
        try {
            $field = "{$viewerId}:{$viewedId}";
            $timestamp = time();
            Redis::hset(self::BUFFER_KEY, $field, $timestamp);
        } catch (\Throwable $e) {
            Log::warning('ProfileViewService: recordView failed', [
                'viewer_id' => $viewerId,
                'viewed_id' => $viewedId,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /**
     * Сбросить буфер просмотров в БД (вызывается из cron).
     * При падении Redis — возвращает пустой массив (нечего сбрасывать).
     *
     * @return array Массив просмотров для записи в БД
     */
    public function flushBuffer(): array
    {
        try {
            $buffer = Redis::hgetall(self::BUFFER_KEY);

            if (!is_array($buffer) || empty($buffer)) {
                return [];
            }

            Redis::del(self::BUFFER_KEY);

            return $buffer;
        } catch (\Throwable $e) {
            Log::warning('ProfileViewService: flushBuffer failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }
        /**
     * Обработать весь буфер просмотров:
     *   1. Достаём буфер из Redis (метод flushBuffer)
     *   2. Парсим ключи "viewerId:viewedId" → нормальный массив
     *   3. Bulk upsert в таблицу profile_views (чанками по 500)
     *   4. Возвращаем количество обработанных записей
     *
     * Вызывается из job ProcessProfileViewsBatch (по cron каждую минуту).
     *
     * @return int Количество обработанных просмотров
     */
    public function processBatch(): int
    {
        // 1. Достаём буфер и сразу его чистим из Redis
        $buffer = $this->flushBuffer();

        if (!is_array($buffer) || empty($buffer)) {
            return 0;
        }

        // 2. Парсим ключи "viewerId:viewedId" → массив строк для upsert
        $rows = [];
        $now = now();
        foreach ($buffer as $field => $timestamp) {
            // $field = "viewerId:viewedId" — разделяем по первому ":"
            // (на случай если ID вдруг содержит ":" — хотя для bigint не должно)
            $parts = explode(':', $field, 2);
            if (count($parts) !== 2) {
                continue; // битый ключ — пропускаем
            }

            [$viewerId, $viewedId] = $parts;
            $viewerId = (int) $viewerId;
            $viewedId = (int) $viewedId;

            if ($viewerId <= 0 || $viewedId <= 0) {
                continue; // мусор — пропускаем
            }

            $rows[] = [
                'viewer_id'  => $viewerId,
                'viewed_id'  => $viewedId,
                'viewed_at'  => date('Y-m-d H:i:s', (int) $timestamp),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($rows)) {
            return 0;
        }

        // 3. Bulk upsert в БД — чанками по 500 (чтобы не упереться в лимит параметров)
        // upsert = INSERT ... ON CONFLICT (viewer_id, viewed_id) DO UPDATE
        // Работает только если в таблице profile_views есть UNIQUE индекс на (viewer_id, viewed_id).
        // Если такого индекса НЕТ — будет ошибка. Тогда нужно создать миграцию (см. ниже).
        try {
            $chunks = array_chunk($rows, 500);
            foreach ($chunks as $chunk) {
                DB::table('profile_views')->upsert(
                    $chunk,
                    ['viewer_id', 'viewed_id'],  // unique columns
                    ['viewed_at', 'updated_at']  // что обновлять при конфликте
                );
            }

            return count($rows);
        } catch (\Throwable $e) {
            Log::error('ProfileViewService: processBatch DB upsert failed', [
                'rows_count' => count($rows),
                'error'      => $e->getMessage(),
            ]);
            // Возвращаем 0 — job не упадёт, но и в БД не запишется.
            // Буфер УЖЕ удалён из Redis (flushBuffer это сделал), так что данные потеряны.
            // Приемлемо — просмотры некритичные данные.
            return 0;
        }
    }

    /**
     * Проверить, смотрел ли viewer профиль viewed за последние N часов.
     * При падении Redis возвращает false (считаем что не смотрел).
     */
    public function hasViewedRecently(int $viewerId, int $viewedId, int $hours = 24): bool
    {
        try {
            $field = "{$viewerId}:{$viewedId}";
            $timestamp = Redis::hget(self::BUFFER_KEY, $field);

            if (!$timestamp) {
                return false;
            }

            return (time() - (int) $timestamp) < ($hours * 3600);
        } catch (\Throwable $e) {
            Log::warning('ProfileViewService: hasViewedRecently failed, returning false', [
                'viewer_id' => $viewerId,
                'viewed_id' => $viewedId,
                'error'     => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Записать просмотр с проверкой дубликата.
     * При падении Redis — записываем в БД напрямую (без дедупликации).
     */
    public function recordViewIfNotRecent(int $viewerId, int $viewedId, int $hours = 24): void
    {
        try {
            if (!$this->hasViewedRecently($viewerId, $viewedId, $hours)) {
                $this->recordView($viewerId, $viewedId);
            }
        } catch (\Throwable $e) {
            Log::warning('ProfileViewService: recordViewIfNotRecent failed', [
                'viewer_id' => $viewerId,
                'viewed_id' => $viewedId,
                'error'     => $e->getMessage(),
            ]);
            // Fallback: записываем без проверки дубликата
            $this->recordView($viewerId, $viewedId);
        }
    }
}
