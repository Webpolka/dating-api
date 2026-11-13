<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TABLE IF EXISTS interests CASCADE');

        Schema::create('interests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 100);

            $table->foreignId('category_id')
                ->nullable()
                ->constrained('interest_categories')
                ->onDelete('set null');

            // Counter cache — сколько юзеров выбрали интерес (для сортировки "популярные")
            $table->unsignedInteger('users_count')->default(0);
            
            // Для ручного управления "популярными" (админ поднимает топ-интересы)
            $table->unsignedSmallInteger('search_boost')->default(0);

            $table->timestamps();
        });

        DB::statement('ALTER TABLE interests ALTER COLUMN name TYPE citext');
        DB::statement('ALTER TABLE interests ALTER COLUMN slug TYPE citext');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS interests_name_unique ON interests (name)');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS interests_slug_unique ON interests (slug)');
        // Для UI выбора по категории
        DB::statement('CREATE INDEX IF NOT EXISTS interests_category_idx ON interests (category_id, name)');
        // Для сортировки "популярные"
        DB::statement('CREATE INDEX IF NOT EXISTS interests_popular_idx ON interests (search_boost DESC, users_count DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('interests');
    }
};