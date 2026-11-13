<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // === БАЗОВАЯ ИНФА ===
            $table->enum('gender', ['male', 'female'])->nullable()->index();
            $table->date('birth_date')->nullable()->index(); 
            
            // Множественный выбор -> jsonb
            $table->jsonb('dating_goals')->nullable();
            
            // === СПРАВОЧНИКИ ===
            $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete()->index();
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            
            // === ТЕКСТОВЫЕ БЛОКИ ===
            $table->string('headline')->nullable(); 
            $table->text('bio')->nullable(); 
            $table->text('looking_for')->nullable(); 

            // JSONB (интересы живут в отдельной таблице user_interests через pivot)
            $table->jsonb('self_portrait')->nullable(); 

            // === ВНЕШНОСТЬ ===
            $table->unsignedTinyInteger('body_type')->default(0);
            $table->unsignedTinyInteger('eye_color')->default(0);
            $table->unsignedTinyInteger('hair_color')->default(0);
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('weight')->nullable();

            // === ЛИЧНЫЕ ДАННЫЕ ===
            $table->unsignedTinyInteger('relationship_status')->default(0);
            $table->unsignedTinyInteger('children_status')->default(0);
            $table->unsignedTinyInteger('pets')->default(0);
            $table->unsignedTinyInteger('housing')->default(0);
            $table->unsignedTinyInteger('has_car')->default(0);
            $table->unsignedTinyInteger('smoking')->default(0);
            $table->unsignedTinyInteger('alcohol')->default(0);
            $table->unsignedTinyInteger('zodiac_sign')->default(0);

            // === Множественный выбор -> JSONB ===
            $table->jsonb('body_decorations')->nullable(); 
            $table->jsonb('languages')->nullable();
            $table->jsonb('sports')->nullable();

            // === РАБОТА И ОБРАЗОВАНИЕ ===
            $table->unsignedTinyInteger('education_level')->default(0);
            $table->unsignedTinyInteger('income')->default(0);
            
            $table->string('institution')->nullable();
            $table->unsignedSmallInteger('institution_year')->nullable();
            $table->string('activity')->nullable();
            $table->string('position')->nullable();

            // === ГЕОЛОКАЦИЯ (PostGIS) ===
            $table->geography('location', subtype: 'point')->nullable();
            $table->string('address')->nullable();
 
            $table->timestamps();
            
            // Soft deletes для синхронизации с users
            $table->softDeletes();

            // Индекс для инвалидации кэшей (крон, админка)
            $table->index('updated_at');
        });

        // ============================================================
        // КРИТИЧЕСКИ ВАЖНЫЕ ИНДЕКСЫ
        // ============================================================

        // 1. COMPOSITE INDEX для базового поиска кандидатов.
        // WHERE gender = ? AND birth_date BETWEEN ? AND ? AND deleted_at IS NULL
        DB::statement(
            'CREATE INDEX user_profiles_gender_birthdate_active_idx ' .
            'ON user_profiles (gender, birth_date) ' .
            'WHERE deleted_at IS NULL'
        );

        // 2. GIN-индекс для dating_goals (только активные)
        DB::statement(
            'CREATE INDEX user_profiles_dating_goals_gin ' .
            'ON user_profiles USING GIN (dating_goals jsonb_path_ops) ' .
            'WHERE deleted_at IS NULL AND dating_goals IS NOT NULL'
        );

        // 3. GIN-индекс для languages
        DB::statement(
            'CREATE INDEX user_profiles_languages_gin ' .
            'ON user_profiles USING GIN (languages jsonb_path_ops) ' .
            'WHERE deleted_at IS NULL AND languages IS NOT NULL'
        );

        // 4. GIN-индекс для sports
        DB::statement(
            'CREATE INDEX user_profiles_sports_gin ' .
            'ON user_profiles USING GIN (sports jsonb_path_ops) ' .
            'WHERE deleted_at IS NULL AND sports IS NOT NULL'
        );

        // 5. ИНДЕКСЫ ДЛЯ ЧАСТЫХ ФИЛЬТРОВ (partial — только активные и непустые)
        DB::statement(
            'CREATE INDEX user_profiles_body_type_active_idx ' .
            'ON user_profiles (body_type) ' .
            'WHERE deleted_at IS NULL AND body_type > 0'
        );
        DB::statement(
            'CREATE INDEX user_profiles_smoking_active_idx ' .
            'ON user_profiles (smoking) ' .
            'WHERE deleted_at IS NULL AND smoking > 0'
        );
        DB::statement(
            'CREATE INDEX user_profiles_relationship_status_active_idx ' .
            'ON user_profiles (relationship_status) ' .
            'WHERE deleted_at IS NULL AND relationship_status > 0'
        );
        DB::statement(
            'CREATE INDEX user_profiles_education_level_active_idx ' .
            'ON user_profiles (education_level) ' .
            'WHERE deleted_at IS NULL AND education_level > 0'
        );
        DB::statement(
            'CREATE INDEX user_profiles_income_active_idx ' .
            'ON user_profiles (income) ' .
            'WHERE deleted_at IS NULL AND income > 0'
        );

        // 6. ПРОСТРАНСТВЕННЫЙ ИНДЕКС ДЛЯ ГЕОЛОКАЦИИ (partial — только непустые)
        DB::statement(
            'CREATE INDEX user_profiles_location_sidx ' .
            'ON user_profiles USING GIST (location) ' .
            'WHERE location IS NOT NULL'
        );

        // 7. PARTIAL INDEX для поиска "кандидаты рядом" (geo + gender + age)
        DB::statement(
            'CREATE INDEX user_profiles_geo_candidates_idx ' .
            'ON user_profiles (gender, birth_date) ' .
            'WHERE deleted_at IS NULL AND location IS NOT NULL'
        );
    }

    public function down(): void
    {     
        Schema::dropIfExists('user_profiles');
    }
};