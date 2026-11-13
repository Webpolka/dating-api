<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // === 1. ЛОКАЛИЗАЦИЯ И ИНТЕРФЕЙС ===
            $table->string('locale', 5)->default('ru');
            $table->string('theme', 10)->nullable();
            $table->boolean('chat_widget_enabled')->default(true);
            $table->boolean('chat_sound_enabled')->default(true);

            // === 2. ПРЕДПОЧТЕНИЯ ПОИСКА (Кого я ищу) ===
            $table->enum('preferred_gender', ['any', 'male', 'female'])->default('any')->index();
            $table->unsignedTinyInteger('preferred_age_min')->default(18);
            $table->unsignedTinyInteger('preferred_age_max')->default(99);
            $table->unsignedSmallInteger('preferred_distance_km')->default(50);
            $table->jsonb('search_filters')->nullable();

            // === 3. ПРИВАТНОСТЬ И ВИДИМОСТЬ (Кто видит меня) ===
            // hide_from_search — юзер НЕ хочет появляться в выдаче у других.
            // is_invisible — VIP-фича "инкогнито": юзер видит других, но его видят
            //   только те, кому он сам написал/лайкнул.
            //
            // Оба поля — КРИТИЧНЫ для поиска. Без индексов = Seq Scan.
            $table->boolean('hide_from_search')->default(false);
            $table->boolean('is_invisible')->default(false); // VIP
            $table->boolean('hide_intimate')->default(false); 
            $table->boolean('disable_photo_comments')->default(false);
            $table->boolean('show_quick_replies')->default(true); 
            
            // VIP-фильтр: кто меня видит (reverse-фильтр поиска!)
            $table->enum('visibility_gender', ['any', 'male', 'female'])->default('any');
            $table->unsignedTinyInteger('visibility_age_min')->default(18);
            $table->unsignedTinyInteger('visibility_age_max')->default(99);
            
            // Фильтр входящих сообщений
            $table->boolean('chat_filter_enabled')->default(false);
            $table->jsonb('chat_filter_settings')->nullable();

            // === 4. УВЕДОМЛЕНИЯ И АВТОМАТИЗАЦИЯ ===
            $table->boolean('push_enabled')->default(true);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('push_auto_recommendations')->default(true);  
            $table->boolean('email_auto_recommendations')->default(true); 
            $table->boolean('allow_auto_messages')->default(true);        
            $table->jsonb('email_settings')->nullable(); 

            $table->timestamps();
        });

        // ============================================================
        // CHECK CONSTRAINTS — защита от кривых данных на уровне БД
        // ============================================================

        // 1. preferred_age_min <= preferred_age_max
        // Защита от инверсии, которая ломает поиск.
        // Если бэк попытается записать min=99, max=18 — БД отклонит.
        DB::statement("
            ALTER TABLE user_preferences 
            ADD CONSTRAINT preferred_age_range_check 
            CHECK (preferred_age_min <= preferred_age_max)
        ");

        // 2. visibility_age_min <= visibility_age_max
        DB::statement("
            ALTER TABLE user_preferences 
            ADD CONSTRAINT visibility_age_range_check 
            CHECK (visibility_age_min <= visibility_age_max)
        ");

        // 3. preferred_age_min >= 18 (по закону для дейтинг-сайтов)
        DB::statement("
            ALTER TABLE user_preferences 
            ADD CONSTRAINT preferred_age_min_adult_check 
            CHECK (preferred_age_min >= 18)
        ");

        // 4. visibility_age_min >= 18
        DB::statement("
            ALTER TABLE user_preferences 
            ADD CONSTRAINT visibility_age_min_adult_check 
            CHECK (visibility_age_min >= 18)
        ");

        // 5. preferred_distance_km в разумных пределах (1-1000 км)
        // Защита от случайного preferred_distance_km=0 (ничего не найдёт) или = 999999 (весь мир)
        DB::statement("
            ALTER TABLE user_preferences 
            ADD CONSTRAINT preferred_distance_range_check 
            CHECK (preferred_distance_km >= 1 AND preferred_distance_km <= 1000)
        ");

        // ============================================================
        // ИНДЕКСЫ ПОД ГОРЯЧИЕ ФИЛЬТРЫ ПОИСКА
        // ============================================================

        // 1. PARTIAL INDEX: "кандидатов видно в поиске".
        // Самый частый фильтр в выдаче:
        //   WHERE hide_from_search = false AND is_invisible = false
        //
        // Хотим, чтобы Seq Scan по миллионам стал IndexScan по ~10-30% строк.
        // Partial index хранит ТОЛЬКО строки, попадающие в выдачу.
        DB::statement(
            'CREATE INDEX user_prefs_search_visible_idx ' .
            'ON user_preferences (user_id) ' .
            'WHERE hide_from_search = false AND is_invisible = false'
        );

        // 2. COMPOSITE INDEX для матчинга по предпочтениям юзера.
        // Когда юзер ищет, бэк делает:
        //   JOIN user_preferences ON candidate.user_id = user_preferences.user_id
        //   WHERE preferred_gender IN ('any', ?)
        //     AND preferred_age_min <= ? AND preferred_age_max >= ?
        //
        // Этот индекс ускорит JOIN для кандидатов, чьи предпочтения включают текущего юзера.
        DB::statement(
            'CREATE INDEX user_prefs_match_composite_idx ' .
            'ON user_preferences (preferred_gender, preferred_age_min, preferred_age_max) ' .
            'WHERE hide_from_search = false AND is_invisible = false'
        );

        // 3. PARTIAL INDEX для visibility reverse-filter.
        // Когда юзер A (мужчина 30 лет) ищет женщин, бэк фильтрует:
        //   WHERE visibility_gender IN ('any', 'male')
        //     AND visibility_age_min <= 30
        //     AND visibility_age_max >= 30
        //
        // Составной partial индекс ускорит этот запрос.
        DB::statement(
            'CREATE INDEX user_prefs_visibility_idx ' .
            'ON user_preferences (visibility_gender, visibility_age_min, visibility_age_max) ' .
            'WHERE hide_from_search = false AND is_invisible = false'
        );

        // 4. ИНДЕКСЫ ДЛЯ АВТОМАТИЗАЦИИ (push/email рассылки).
        // Крон "найди всех юзеров, кому можно отправить авто-рекомендации".
        DB::statement(
            'CREATE INDEX user_prefs_push_auto_idx ' .
            'ON user_preferences (user_id) ' .
            'WHERE push_enabled = true AND push_auto_recommendations = true'
        );
        DB::statement(
            'CREATE INDEX user_prefs_email_auto_idx ' .
            'ON user_preferences (user_id) ' .
            'WHERE email_enabled = true AND email_auto_recommendations = true'
        );
        DB::statement(
            'CREATE INDEX user_prefs_auto_messages_idx ' .
            'ON user_preferences (user_id) ' .
            'WHERE allow_auto_messages = true'
        );

        // 5. GIN-индекс для search_filters (если бэк будет делать JSONB-фильтрацию).
        // Пример: WHERE search_filters @> '{"is_premium_only": true}'
        DB::statement(
            'CREATE INDEX user_prefs_search_filters_gin ' .
            'ON user_preferences USING GIN (search_filters jsonb_path_ops) ' .
            'WHERE search_filters IS NOT NULL'
        );

        // 6. GIN-индекс для chat_filter_settings (если бэк фильтрует входящие по JSONB).
        DB::statement(
            'CREATE INDEX user_prefs_chat_filter_gin ' .
            'ON user_preferences USING GIN (chat_filter_settings jsonb_path_ops) ' .
            'WHERE chat_filter_enabled = true AND chat_filter_settings IS NOT NULL'
        );

        // 7. GIN-индекс для email_settings (для крона рассылок).
        DB::statement(
            'CREATE INDEX user_prefs_email_settings_gin ' .
            'ON user_preferences USING GIN (email_settings jsonb_path_ops) ' .
            'WHERE email_settings IS NOT NULL'
        );
    }

    public function down(): void
    {
        // Удаляем constraints (PostgreSQL автоматически дропнет с таблицей, но для чистоты)
        DB::statement('ALTER TABLE user_preferences DROP CONSTRAINT IF EXISTS preferred_age_range_check');
        DB::statement('ALTER TABLE user_preferences DROP CONSTRAINT IF EXISTS visibility_age_range_check');
        DB::statement('ALTER TABLE user_preferences DROP CONSTRAINT IF EXISTS preferred_age_min_adult_check');
        DB::statement('ALTER TABLE user_preferences DROP CONSTRAINT IF EXISTS visibility_age_min_adult_check');
        DB::statement('ALTER TABLE user_preferences DROP CONSTRAINT IF EXISTS preferred_distance_range_check');
        
        Schema::dropIfExists('user_preferences');
    }
};