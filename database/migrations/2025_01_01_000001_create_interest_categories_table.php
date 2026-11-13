<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
          // Идемпотентность
          DB::statement('DROP TABLE IF EXISTS interest_categories CASCADE');
    
    DB::statement('CREATE EXTENSION IF NOT EXISTS citext');

        Schema::create('interest_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('slug', 50);
            $table->string('icon', 50)->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedInteger('interests_count')->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE interest_categories ALTER COLUMN name TYPE citext');
        DB::statement('ALTER TABLE interest_categories ALTER COLUMN slug TYPE citext');

        DB::statement('CREATE UNIQUE INDEX interest_categories_name_unique ON interest_categories (name)');
        DB::statement('CREATE UNIQUE INDEX interest_categories_slug_unique ON interest_categories (slug)');
        DB::statement('CREATE INDEX interest_categories_sort_name_idx ON interest_categories (sort_order, name)');
        DB::statement('CREATE INDEX interest_categories_interests_count_idx ON interest_categories (interests_count DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('interest_categories');
    }
};