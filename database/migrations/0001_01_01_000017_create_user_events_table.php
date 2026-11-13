<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_events', function (Blueprint $table) {
            $table->id();
            
            // Если юзер удаляется, его лента событий тоже схлопывается (cascadeOnDelete)
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            
            // Тип события (avatar_changed, birthday, status_updated, photos_uploaded)
            $table->string('type', 50)->index();
            
            // Доп. данные (например, ID нового фото или текст нового статуса)
            // jsonb работает быстрее и поддерживает индексы в Postgres
            $table->jsonb('properties')->nullable();
            
            $table->timestamps();

            // === ИНДЕКСЫ ===
            
            // Для вывода ленты активности в профиле с пагинацией (ORDER BY created_at DESC)
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_events');
    }
};