<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_favorites', function (Blueprint $table) {
            $table->id();
            
            // Кто добавил в избранное
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            
            // Кого добавили в избранное
            $table->foreignId('favorite_user_id')->constrained('users')->cascadeOnDelete();
            
            $table->timestamps();

            // === ИНДЕКСЫ ===
            
            // 1. Защита от дублей (Вася не может добавить Машу 10 раз)
            $table->unique(['user_id', 'favorite_user_id']);
            
            // 2. Для пагинации списка "Мое избранное"
            $table->index(['user_id', 'created_at']);
            
            // 3. Для VIP-фичи "Кто добавил меня в избранное" (с пагинацией)
            $table->index(['favorite_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_favorites');
    }
};