<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            
            // === 1. БАЗОВАЯ АВТОРИЗАЦИЯ ===
            $table->string('name')->nullable(); 
            $table->string('slug')->nullable()->unique();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('phone', 20)->nullable()->unique(); 
            $table->timestamp('phone_verified_at')->nullable(); 
            $table->string('password')->nullable(); // nullable для соцсетей
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes(); // Для восстановления аккаунта

            // === 2. РОЛЬ И СТАТУС ===
            $table->enum('role', ['user', 'admin', 'moderator', 'support'])->default('user')->index(); 
            $table->enum('status', ['active', 'banned', 'shadowbanned', 'deactivated'])->default('active')->index(); 
            $table->string('ban_reason')->nullable();
            $table->timestamp('banned_until')->nullable();

            // === 3. ПОДПИСКИ (Независимые: Premium и VIP) ===
            $table->timestamp('premium_expires_at')->nullable()->index();
            $table->timestamp('vip_expires_at')->nullable()->index();

            // === 4. ВЕРИФИКАЦИЯ И ОНБОРДИНГ ===
            $table->boolean('is_verified')->default(false);
            $table->boolean('has_completed_onboarding')->default(false);

            // === 5. АКТИВНОСТЬ И АНТИФРОД ===
            $table->timestamp('last_seen')->nullable()->index(); 
            $table->timestamp('last_login_at')->nullable();
            $table->ipAddress('last_login_ip')->nullable();
            
            // Убрали device_id и device_os. 
            // Инфу о девайсах берем из таблицы sessions или user_auth_logs.
        });

        // ============================================================
        // КРИТИЧЕСКИ ВАЖНЫЕ ИНДЕКСЫ
        // ============================================================

        // 1. ИНДЕКС НА deleted_at — Laravel НЕ создаёт его автоматически!
        // Без него каждый WHERE deleted_at IS NULL = Seq Scan на миллионах.
        // Это самый частый фильтр в любой выдаче (лента, поиск, рекомендации).
        DB::statement('CREATE INDEX users_deleted_at_index ON users (deleted_at)');

        // 2. PARTIAL INDEX под горячий путь поиска кандидатов.
        // Реальный запрос:
        //   WHERE deleted_at IS NULL 
        //     AND status = 'active' 
        //     AND has_completed_onboarding = true
        //     AND role = 'user'
        //   ORDER BY last_seen DESC
        //   LIMIT 50
        //
        // Partial index хранит ТОЛЬКО активные, неудалённые, прошедшие онбординг записи.
        // Размер индекса ~5-10x меньше, чем full composite — значит быстрее scan и меньше RAM.
        // Плюс ORDER BY last_seen DESC — IndexScan может сразу отдать LIMIT без сортировки.
        DB::statement(
            'CREATE INDEX users_search_candidates_idx ' .
            'ON users (last_seen DESC) ' .
            'WHERE deleted_at IS NULL ' .
            '  AND status = \'active\' ' .
            '  AND has_completed_onboarding = true ' .
            '  AND role = \'user\''
        );

        // 3. PARTIAL INDEX под фильтр "только верифицированные" — частый в дейтинге.
        // Когда юзер ставит галочку "искать только verified" — нужен этот индекс.
        DB::statement(
            'CREATE INDEX users_verified_active_idx ' .
            'ON users (last_seen DESC) ' .
            'WHERE is_verified = true ' .
            '  AND deleted_at IS NULL ' .
            '  AND status = \'active\''
        );

        // 4. PARTIAL INDEX для подписчиков Premium/VIP (виджет "кто онлайн из премиум").
        DB::statement(
            'CREATE INDEX users_premium_active_idx ' .
            'ON users (last_seen DESC) ' .
            'WHERE premium_expires_at IS NOT NULL ' .
            '  AND deleted_at IS NULL ' .
            '  AND status = \'active\''
        );

        // ВАЖНО про старый композитный индекс [status, has_completed_onboarding]:
        // Я его УБРАЛ из $table->index(...). 
        // Причина: partial index #2 покрывает этот кейс лучше и быстрее.
        // Если где-то в коде есть запросы БЕЗ фильтра по role/last_seen — 
        // просто добавь их сюда:
        //   DB::statement('CREATE INDEX users_status_onboarding_idx ON users (status, has_completed_onboarding) WHERE deleted_at IS NULL');

        // ============================================================
        // Sessions & password reset tokens (без изменений)
        // ============================================================

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
            
            // TTL-индекс — для крона, который чистит протухшие токены.
            // Запрос: DELETE FROM password_reset_tokens WHERE created_at < NOW() - INTERVAL '1 hour'
            $table->index('created_at');
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};