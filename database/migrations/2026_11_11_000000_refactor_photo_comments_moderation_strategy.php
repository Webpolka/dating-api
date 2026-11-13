<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Рефакторинг стратегии модерации комментариев к фото (Вариант A).
 *
 * Принцип: «Спам» — это причина отклонения, а не отдельный статус.
 * Статусы: pending / approved / rejected (+ виртуальный karantine через soft-delete).
 * Причины rejected: profanity / insult / off_topic / spam / other / parent_rejected.
 *
 * Что делает миграция:
 *   1. Сливает status='spam' → status='rejected' с reason='spam'.
 *   2. Переводит каскадный reason 'parent_spam' → 'parent_rejected' (единый каскад).
 *   3. Переводит reason 'mass_reject' → 'other' (причина из enum'а, контекст — в логе).
 *   4. Очищает reject_reason для всех не-rejected записей (pending/approved не должны иметь причину).
 *   5. Меняет тип колонки status на enum без 'spam' (PostgreSQL — через USING-приведение).
 *   6. Сужает reject_reason до CHECK-констрейнта со списком валидных значений.
 *
 * Идемпотентна: безопасна при повторном запуске (UPDATE'ы — no-op для уже мигрировавших строк).
 */
return new class extends Migration
{
    private const string TABLE = 'photo_comments';

    // Валидные причины отклонения после рефакторинга.
    private const array VALID_REJECT_REASONS = [
        'profanity',
        'insult',
        'off_topic',
        'spam',
        'other',
        'parent_rejected',
    ];

    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // 1. Сливаем status='spam' → status='rejected' с reason='spam'.
        //    Каскадные дети от spam-родителя имели reason='parent_spam' → тоже
        //    становятся rejected с reason='parent_rejected' (шаг 2).
        DB::table(self::TABLE)
            ->where('status', 'spam')
            ->update([
                'status' => 'rejected',
                'reject_reason' => DB::raw("COALESCE(NULLIF(reject_reason, 'parent_spam'), 'spam')"),
            ]);

        // 2. Единый каскадный reason 'parent_rejected' для всех детей отклонённых.
        DB::table(self::TABLE)
            ->where('reject_reason', 'parent_spam')
            ->update(['reject_reason' => 'parent_rejected']);

        // 3. mass_reject → other (контекст bulk-операции живёт в admin_logs).
        DB::table(self::TABLE)
            ->where('reject_reason', 'mass_reject')
            ->update(['reject_reason' => 'other']);

        // 4. Чистим reject_reason у pending/approved — причина бывает только у rejected.
        DB::table(self::TABLE)
            ->whereIn('status', ['pending', 'approved'])
            ->whereNotNull('reject_reason')
            ->update(['reject_reason' => null]);

        // 5. Меняем enum status: убираем 'spam'.
        if ($driver === 'pgsql') {
            // PostgreSQL: enum-колонка хранится как varchar с CHECK-констрейнтом.
            // Сначала снимаем старый CHECK, затем накладываем новый.
            DB::statement("ALTER TABLE " . self::TABLE . " DROP CONSTRAINT IF EXISTS photo_comments_status_check");
            DB::statement("ALTER TABLE " . self::TABLE . " ADD CONSTRAINT photo_comments_status_check CHECK (status::text = ANY (ARRAY['pending'::character varying, 'approved'::character varying, 'rejected'::character varying]::text[]))");
        } else {
            // MySQL / MariaDB — пересоздаём enum.
            DB::statement("ALTER TABLE " . self::TABLE . " MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
        }

        // 6. CHECK-констрейнт на reject_reason: только валидные значения из enum'а (либо NULL).
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE " . self::TABLE . " DROP CONSTRAINT IF EXISTS photo_comments_reject_reason_check");
            $list = "'" . implode("','", self::VALID_REJECT_REASONS) . "'";
            DB::statement("ALTER TABLE " . self::TABLE . " ADD CONSTRAINT photo_comments_reject_reason_check CHECK (reject_reason IS NULL OR reject_reason::text = ANY (ARRAY[" . $list . "]::text[]))");
        } else {
            // MySQL 8+: можно через CHECK (игнорируется старыми версиями, но синтаксически валиден).
            $list = "'" . implode("','", self::VALID_REJECT_REASONS) . "'";
            DB::statement("ALTER TABLE " . self::TABLE . " MODIFY reject_reason VARCHAR(255) NULL");
            DB::statement("ALTER TABLE " . self::TABLE . " ADD CONSTRAINT photo_comments_reject_reason_check CHECK (reject_reason IS NULL OR reject_reason IN (" . $list . "))");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        // Снимаем констрейнты
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE " . self::TABLE . " DROP CONSTRAINT IF EXISTS photo_comments_status_check");
            DB::statement("ALTER TABLE " . self::TABLE . " DROP CONSTRAINT IF EXISTS photo_comments_reject_reason_check");
            // Возвращаем enum со 'spam'
            DB::statement("ALTER TABLE " . self::TABLE . " ADD CONSTRAINT photo_comments_status_check CHECK (status::text = ANY (ARRAY['pending'::character varying, 'approved'::character varying, 'rejected'::character varying, 'spam'::character varying]::text[]))");
        } else {
            DB::statement("ALTER TABLE " . self::TABLE . " DROP CONSTRAINT IF EXISTS photo_comments_reject_reason_check");
            DB::statement("ALTER TABLE " . self::TABLE . " MODIFY status ENUM('pending','approved','rejected','spam') NOT NULL DEFAULT 'pending'");
        }

        // Обратная миграция данных: rejected+reason='spam' → status='spam', reason='spam'
        // (так было в исходной схеме).
        DB::table(self::TABLE)
            ->where('status', 'rejected')
            ->where('reject_reason', 'spam')
            ->update(['status' => 'spam']);

        // parent_rejected → parent_spam для тех детей, чей родитель spam
        // (восстановить точно нельзя — мы потеряли эту информацию при up()).
        // Поэтому оставляем parent_rejected как есть — это безопасно.
    }
};
