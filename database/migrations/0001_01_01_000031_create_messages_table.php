<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();

            // === КОНТЕНТ ===
            $table->enum('type', ['text', 'image', 'system', 'gift'])->default('text');
            $table->text('body')->nullable();
            $table->string('attachment_url')->nullable();

            $table->foreignId('gift_id')->nullable()->constrained('gifts')->nullOnDelete();

            // === МОДЕРАЦИЯ ===
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('approved');
            $table->string('reject_reason')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();

            $table->timestamps();
            $table->softDeletes(); // Критически важно для СБ!

            // === ИНДЕКСЫ ===

            // 1. Для пагинации переписки
            $table->index(['chat_id', 'created_at']);

            // 2. Для поиска сообщений юзера (с сортировкой по дате)
            $table->index(['sender_id', 'created_at']);

            // 3. Для очереди модерации в админке (добавили created_at для сортировки)
            $table->index(['status', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
// Разбор архитектуры (Полная безопасность):

// Связь с подарками: Обрати внимание на gift_id. Если юзер шлет подарок, он падает сюда со type = 'gift'. 
// В чате это будет выглядеть как красивая карточка подарка + текст. 
// Если админ через год удалит этот подарок из магазина (gifts), сообщение в чате не сломается и не 
// исчезнет (благодаря nullOnDelete), просто картинка станет недоступна.
// Модерация по умолчанию (status = 'approved'): Мы не можем проверять каждое "Привет". 
// Но в коде (в сервис-классе) у тебя будет проверка: 
// if ($message->type === 'image') { $message->status = 'pending'; }. И фотка улетает в очередь.
// Мягкое удаление (softDeletes): Это закон в дейтинге. Если мошенник обманул юзера на деньги в чате, 
// а потом юзер удалил переписку (или мошенник удалил у себя) — для службы безопасности сообщения остаются. 
// Они помечаются как удаленные, но админ видит их в логах.
// Как работает статус rejected? Если модератор или ИИ забраковал фотку, в БД ставится status = 'rejected'. 
// На фронте (в Livewire/JS) у собеседника вместо картинки отобразится плашка "Фотография заблокирована модератором".