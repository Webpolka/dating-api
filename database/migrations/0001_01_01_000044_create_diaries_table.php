<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diaries', function (Blueprint $table) {
            $table->id();
            
            // Автор поста
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            
            // Рубрика
            $table->foreignId('diary_rubric_id')->nullable()->constrained('diary_rubrics')->nullOnDelete();
            
            // Контент
            $table->string('title');
            $table->longText('body'); // HTML или Markdown
                      
            // Статус и модерация
            $table->enum('status', ['draft', 'pending', 'published', 'rejected'])->default('draft')->index();
            $table->string('reject_reason')->nullable();
            
            // Даты
            $table->timestamp('published_at')->nullable();
            
            // Настройки поста (Интегрировано из add)
            $table->boolean('is_comments_enabled')->default(true);
            $table->boolean('is_quote_enabled')->default(true);
            
            // Денормализованные счетчики (Интегрировано из add + bigInteger)
            $table->unsignedBigInteger('views_count')->default(0);
            $table->unsignedBigInteger('comments_count')->default(0);
            $table->unsignedBigInteger('likes_count')->default(0);
            
            $table->timestamps();
            $table->softDeletes();

            // === ИНДЕКСЫ ===
            
            // 1. Вывод постов конкретного юзера
            $table->index(['user_id', 'status', 'published_at']);
            
            // 2. Вывод постов по рубрике
            $table->index(['diary_rubric_id', 'status', 'published_at']);
            
            // 3. Для глобальной ленты дневников (свежие посты на сайте)
            $table->index(['status', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diaries');
    }
};