<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;


/**
 * Рефакторинг стратегии модерации комментариев к дневникам (Вариант A).
 *
 * Полная аналогия с photo_comments (миграция 2026_11_11_000000).
 *
 * Принцип: «Спам» — это причина отклонения, а не отдельный статус.
 * Статусы: pending / approved / rejected (+ виртуальный karantine через soft-delete).
 * Причины rejected: profanity / insult / off_topic / spam / other / parent_rejected.
 *
 * Что делает миграция:
 *   1. Меняет default status с 'approved' на 'pending' (премодерация, симметрично с photo_comments).
 *   2. Сливает status='spam' → status='rejected' с reason='spam'.
 *   3. Переводит каскадный reason 'parent_spam' → 'parent_rejected' (единый каскад).
 *   4. Переводит reason 'mass_reject' → 'other' (контекст bulk — в admin_logs).
 *   5. Очищает reject_reason для всех не-rejected записей.
 *   6. Меняет тип колонки status на enum без 'spam' (PostgreSQL — через CHECK).
 *   7. Сужает reject_reason до CHECK-констрейнта со списком валидных значений.
 *
 * Идемпотентна: безопасна при повторном запуске.
 */
return new class extends Migration
{
    private const string TABLE = 'diary_comments';

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

        // 5. Меняем enum status: убираем 'spam', меняем default на 'pending'.
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE " . self::TABLE . " DROP CONSTRAINT IF EXISTS diary_comments_status_check");
            DB::statement("ALTER TABLE " . self::TABLE . " ADD CONSTRAINT diary_comments_status_check CHECK (status::text = ANY (ARRAY['pending'::character varying, 'approved'::character varying, 'rejected'::character varying]::text[]))");
            DB::statement("ALTER TABLE " . self::TABLE . " ALTER COLUMN status SET DEFAULT 'pending'");
        } else {
            DB::statement("ALTER TABLE " . self::TABLE . " MODIFY status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
        }

        // 6. CHECK-констрейнт на reject_reason: только валидные значения из enum'а (либо NULL).
        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE " . self::TABLE . " DROP CONSTRAINT IF EXISTS diary_comments_reject_reason_check");
            $list = "'" . implode("','", self::VALID_REJECT_REASONS) . "'";
            DB::statement("ALTER TABLE " . self::TABLE . " ADD CONSTRAINT diary_comments_reject_reason_check CHECK (reject_reason IS NULL OR reject_reason::text = ANY (ARRAY[" . $list . "]::text[]))");
        } else {
            DB::statement("ALTER TABLE " . self::TABLE . " DROP CONSTRAINT IF EXISTS diary_comments_reject_reason_check");
            $list = "'" . implode("','", self::VALID_REJECT_REASONS) . "'";
            DB::statement("ALTER TABLE " . self::TABLE . " MODIFY reject_reason VARCHAR(255) NULL");
            DB::statement("ALTER TABLE " . self::TABLE . " ADD CONSTRAINT diary_comments_reject_reason_check CHECK (reject_reason IS NULL OR reject_reason IN (" . $list . "))");
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE " . self::TABLE . " DROP CONSTRAINT IF EXISTS diary_comments_status_check");
            DB::statement("ALTER TABLE " . self::TABLE . " DROP CONSTRAINT IF EXISTS diary_comments_reject_reason_check");
            DB::statement("ALTER TABLE " . self::TABLE . " ADD CONSTRAINT diary_comments_status_check CHECK (status::text = ANY (ARRAY['approved'::character varying, 'pending'::character varying, 'rejected'::character varying, 'spam'::character varying]::text[]))");
            DB::statement("ALTER TABLE " . self::TABLE . " ALTER COLUMN status SET DEFAULT 'approved'");
        } else {
            DB::statement("ALTER TABLE " . self::TABLE . " DROP CONSTRAINT IF EXISTS diary_comments_reject_reason_check");
            DB::statement("ALTER TABLE " . self::TABLE . " MODIFY status ENUM('approved','pending','rejected','spam') NOT NULL DEFAULT 'approved'");
        }

        // Обратная миграция данных: rejected+reason='spam' → status='spam'
        DB::table(self::TABLE)
            ->where('status', 'rejected')
            ->where('reject_reason', 'spam')
            ->update(['status' => 'spam']);
    }
};
