<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geo_translations', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('geoname_id')->index(); // Связь с таблицами стран/регионов/городов
            $table->string('locale', 5)->index();      // Код языка (ru, en)
            $table->string('name');                    // Переведенное название
            $table->timestamps();
            
            // Уникальный индекс, чтобы не было дублей переводов
            $table->unique(['geoname_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geo_translations');
    }
};