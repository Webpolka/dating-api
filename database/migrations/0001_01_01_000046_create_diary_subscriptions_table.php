<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diary_subscriptions', function (Blueprint $table) {
            $table->id();
             
            $table->foreignId('subscriber_id')->constrained('users')->nullOnDelete();
            $table->foreignId('author_id')->constrained('users')->nullOnDelete();
            
            $table->timestamps();

            // === ИНДЕКСЫ ===
            
            // 1. Защита от дублей (Иван не может подписаться на Машу дважды)
            $table->unique(['subscriber_id', 'author_id']);
            
            // Убрали index('subscriber_id'), так как он автоматом работает из unique-индекса!
            
            // 2. Для пагинации списка "Мои подписчики" (с сортировкой по дате)
            $table->index(['author_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diary_subscriptions');
    }
};