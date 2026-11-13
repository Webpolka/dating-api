<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_views', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('viewer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('viewed_id')->constrained('users')->cascadeOnDelete();
            
            // Убрали created_at, оставили только updated_at.
            // При просмотре мы делаем updateOrCreate, обновляя только updated_at.
            $table->timestamp('updated_at')->nullable();

            // === ИНДЕКСЫ ===
            
            // 1. Защита от дубликатов (цель для upsert)
            $table->unique(['viewer_id', 'viewed_id']);
             
            // 2. Для пагинации "Кто смотрел меня" (WHERE viewed_id = ? ORDER BY updated_at DESC)
            $table->index(['viewed_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_views');
    }
};