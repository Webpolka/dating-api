<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Добавляет moderated_by и moderated_at в таблицу diaries.
 *
 * Симметрично с photos и photo_comments, где эти поля уже есть.
 * Без них невозможно узнать, кто и когда модерировал пост, без
 * копания в admin_logs.
 *
 * Также добавляет индекс на [status, moderated_at] для быстрого
 * вывода очереди модерации.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diaries', function (Blueprint $table) {
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete()->after('reject_reason');
            $table->timestamp('moderated_at')->nullable()->after('moderated_by');
        });
    }

    public function down(): void
    {
        Schema::table('diaries', function (Blueprint $table) {
            $table->dropForeign(['moderated_by']);
            $table->dropColumn(['moderated_by', 'moderated_at']);
        });
    }
};
