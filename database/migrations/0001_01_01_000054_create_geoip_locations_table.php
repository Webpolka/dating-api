<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geoip_locations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('geoip_locations')
                ->cascadeOnDelete();

            $table->enum('type', ['country', 'region', 'city'])->index();
            $table->string('name');
            $table->string('name_ru')->nullable();

            $table->string('iso_code', 10)->nullable()->index();

            $table->double('latitude')->nullable();
            $table->double('longitude')->nullable();

            $table->boolean('is_registration_blocked')->default(false)->index();
            $table->boolean('is_feed_blocked')->default(false)->index();

            $table->timestamps();
        });

        // КРИТИЧЕСКИ ВАЖНО для PostgreSQL: Частичные уникальные индексы!
        // Стандартный unique() с NULL в PostgreSQL не работает (пропускает дубли стран).
        // 1. Уникальность для СТРАН (parent_id IS NULL)
        DB::statement("CREATE UNIQUE INDEX geoip_countries_unique ON geoip_locations (type, name) WHERE parent_id IS NULL");

        // 2. Уникальность для РЕГИОНОВ И ГОРОДОВ (parent_id IS NOT NULL)
        DB::statement("CREATE UNIQUE INDEX geoip_children_unique ON geoip_locations (parent_id, type, name) WHERE parent_id IS NOT NULL");
    }

    public function down(): void
    {
        Schema::dropIfExists('geoip_locations');
    }
};
