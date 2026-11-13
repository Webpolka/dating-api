<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Добавляет глобальное staff-state в таблицу chats для support-чатов.
 *
 * ДО этой миграции: each admin had per-admin state через chat_participants
 * (свой unread_count, свой is_hidden для архива). Это значило что тикет
 * виден только конкретному админу, и «прочитано» было per-admin.
 *
 * ПОСЛЕ: support-чаты видят ВСЕ staff. State «прочитано/архив» — глобальный
 * на уровне чата. Если один staff прочитал → все остальные тоже видят
 * «прочитано». Если архивнул → у всех в архиве.
 *
 * Это модель «одно лицо для support team» — стандартная для небольших
 * команд support'а (Freshdesk, Zendesk работают так).
 *
 * Поля применяются ТОЛЬКО к support-чатам (type='support'). Для private
 * чатов эти поля просто не используются (default 0 / false / null).
 *
 * chat_participants для support-чатов теперь хранит ТОЛЬКО юзера
 * (одного участника). Админ не добавляется в participants — он
 * «виртуальный» участник через staff-роль.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            // Сколько сообщений от юзера staff ещё не видел (глобально).
            // Increment'ится в MessageObserver когда юзер шлёт сообщение
            // в support-чат. Сбрасывается в 0 в markAsRead (любой staff).
            // Для private чатов всегда 0 (не используется).
            $table->unsignedInteger('staff_unread_count')->default(0)->after('is_locked');

            // Когда любой staff последний раз прочитал чат.
            // Для отображения «Прочитал {admin} 5 мин назад» в UI.
            $table->timestamp('staff_last_read_at')->nullable()->after('staff_unread_count');

            // Кто из staff последний раз прочитал (для аудита).
            // FK на users с nullOnDelete — если админа удалили,
            // просто останется null, история не теряется в AdminLog.
            $table->foreignId('staff_last_read_by_id')
                ->nullable()
                ->after('staff_last_read_at')
                ->constrained('users')
                ->nullOnDelete();
        });

        Schema::table('chats', function (Blueprint $table) {
            // Глобальный архив для support-чатов.
            // Для private чатов всегда false (не используется —
            // там архив per-user через chat_participants.is_hidden).
            //
            // Indекс нужен потому что фильтр archived = true используется
            // в index() запросе. Boolean сам по себе low-selectivity,
            // но в комбинации с type='support' уже полезен.
            $table->boolean('is_archived')->default(false)->index()->after('staff_last_read_by_id');
        });

        // === PARTIAL INDEXES для support-чатов ===
        // Покрывают три фильтра в SupportChatController::index():
        //   - unread:  type=support AND staff_unread_count > 0 AND is_archived=false
        //   - active:  type=support AND staff_unread_count = 0 AND is_archived=false
        //   - archived: type=support AND is_archived=true
        //
        // Partial indexes хранят только support-чаты с нужным состоянием —
        // размер ~10-100x меньше чем full composite. List views летают
        // даже на миллионах support-чатов.
        //
        // ORDER BY last_message_at DESC — IndexScan может сразу отдать
        // LIMIT без сортировки (matching index order).
        DB::statement(
            'CREATE INDEX chats_support_unread_idx ' .
                'ON chats (last_message_at DESC) ' .
                "WHERE type = 'support' AND staff_unread_count > 0 AND is_archived = false"
        );

        DB::statement(
            'CREATE INDEX chats_support_active_idx ' .
                'ON chats (last_message_at DESC) ' .
                "WHERE type = 'support' AND staff_unread_count = 0 AND is_archived = false"
        );

        DB::statement(
            'CREATE INDEX chats_support_archived_idx ' .
                'ON chats (last_message_at DESC) ' .
                "WHERE type = 'support' AND is_archived = true"
        );

        // Индекс на staff_last_read_by_id — для запросов «какие чаты
        // прочитал админ X». FK в PostgreSQL НЕ создаёт индекс автоматически
        // (в отличие от MySQL), добавляем явно.
        DB::statement(
            'CREATE INDEX chats_staff_last_read_by_id_idx ON chats (staff_last_read_by_id)'
        );
    }

    public function down(): void
    {
        // Сначала дропаем partial indexes (они зависят от полей которые удаляем)
        DB::statement('DROP INDEX IF EXISTS chats_support_unread_idx');
        DB::statement('DROP INDEX IF EXISTS chats_support_active_idx');
        DB::statement('DROP INDEX IF EXISTS chats_support_archived_idx');
        DB::statement('DROP INDEX IF EXISTS chats_staff_last_read_by_id_idx');

        Schema::table('chats', function (Blueprint $table) {
            // Сначала дропаем FK constraint, потом колонку
            $table->dropForeign(['staff_last_read_by_id']);
            $table->dropIndex('chats_is_archived_index'); // индекс на boolean
            $table->dropColumn([
                'staff_unread_count',
                'staff_last_read_at',
                'staff_last_read_by_id',
                'is_archived',
            ]);
        });
    }
};
