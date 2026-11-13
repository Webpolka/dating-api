<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    { 
        Schema::create('diary_comment_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diary_comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            // Защита: 1 юзер = 1 лайк на коммент
            $table->unique(['diary_comment_id', 'user_id']);
            
            // Для быстрого вывода "какие комменты лайкнул юзер" (с пагинацией)
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diary_comment_likes');
    }
};