<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diary_comments', function (Blueprint $table) {
            $table->id();

            // К какому посту привязан
            $table->foreignId('diary_id')->constrained()->cascadeOnDelete();

            // Автор (nullable для сохранения истории удаленных юзеров)
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('content');

            // Для ответов (цитирований)
            $table->foreignId('parent_id')->nullable()->constrained('diary_comments')->nullOnDelete();

            // Модерация
            // default='pending' — премодерация ВКЛЮЧЕНА: все новые комментарии
            // попадают в очередь модерации. Админ видит их в moderation/diary-comments.
            $table->enum('status', ['approved', 'pending', 'rejected', 'spam'])->default('pending');
            $table->string('reject_reason')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();

            // Денормализация (BigInteger для защиты от переполнения)
            $table->unsignedBigInteger('likes_count')->default(0);
            $table->unsignedBigInteger('replies_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // === ИНДЕКСЫ ===

            // 1. Вывод комментариев под постом (Добавили created_at для сортировки!)
            $table->index(['diary_id', 'status', 'parent_id', 'created_at']);

            // 2. История юзера (его комментарии)
            $table->index(['user_id', 'created_at']);

            // 3. Очередь модерации в админке (с сортировкой по дате)
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diary_comments');
    }
};
