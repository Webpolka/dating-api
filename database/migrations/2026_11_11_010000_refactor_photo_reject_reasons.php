<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Рефакторинг причин отклонения фото (Вариант A — симметрично с CommentRejectReason).
 *
 * Что делает:
 *   1. mass_reject → other (контекст bulk-операции живёт в admin_logs.action).
 *   2. report_violation → other (не было в enum, использовалось в ReportController).
 *   3. user_banned → other (не было в enum, использовалось в ToggleUserBanAction).
 *   4. Чистим reject_reason у pending/approved — причина бывает только у rejected.
 *
 * Идемпотентна: безопасна при повторном запуске.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1-3. Конвертация устаревших причин в 'other'.
        DB::table('photos')
            ->whereIn('reject_reason', ['mass_reject', 'report_violation', 'user_banned'])
            ->update(['reject_reason' => 'other']);

        // 4. Чистим reject_reason у pending/approved — причина бывает только у rejected.
        DB::table('photos')
            ->whereIn('status', ['pending', 'approved'])
            ->whereNotNull('reject_reason')
            ->update(['reject_reason' => null]);
    }

    public function down(): void
    {
        // Обратная миграция невозможна — мы потеряли информацию о том,
        // какие 'other' были изначально 'mass_reject'/'report_violation'/'user_banned'.
        // Это сознательное упрощение: контекст bulk/report/ban живёт в admin_logs.
    }
};
