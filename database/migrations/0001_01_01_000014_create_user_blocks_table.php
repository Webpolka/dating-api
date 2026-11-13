<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_blocks', function (Blueprint $table) {
            $table->id();
            
            // Кто заблокировал
            $table->foreignId('blocker_id')->constrained('users')->cascadeOnDelete();
            
            // Кого заблокировал
            $table->foreignId('blocked_id')->constrained('users')->cascadeOnDelete();
            
            // Причина блокировки (для аналитики в админке)
            $table->string('reason')->nullable();
            
            $table->timestamps();

            // === ИНДЕКСЫ ===
            
            // 1. Защита от дубликатов (нельзя заблокировать дважды)
            $table->unique(['blocker_id', 'blocked_id']);
            
            // 2. Для пагинации списка "Кого я заблокировал"
            $table->index(['blocker_id', 'created_at']);
            
            // 3. Для админки: найти юзеров, которых блокируют чаще всего
            $table->index(['blocked_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_blocks');
    }
};