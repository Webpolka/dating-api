<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_participants', function (Blueprint $table) {
            $table->id();

            $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // BigInteger для защиты от переполнения счетчика
            $table->unsignedBigInteger('unread_count')->default(0);

            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('last_read_at')->nullable();

            $table->boolean('is_hidden')->default(false)->index();
            $table->boolean('is_muted')->default(false);
            $table->boolean('is_blocked')->default(false);

            $table->timestamps();

            // === ИНДЕКСЫ ===

            // 1. Защита от дубликатов
            $table->unique(['chat_id', 'user_id']);

            // 2. Для вывода списка "Мои диалоги"          
            $table->index(['user_id', 'is_hidden', 'last_message_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_participants');
    }
};

// Разбор архитектуры:

// Почему unread_count здесь? Это классическая денормализация. Когда собеседник пишет сообщение, 
// воркер делает UPDATE chat_participants SET unread_count = unread_count + 1 WHERE chat_id = X AND user_id != Отправитель. 
// Это работает в сотни раз быстрее, чем высчитывать непрочитанные на лету.

// Флаги is_hidden, is_muted, is_blocked: В современных дейтингах юзер хочет управлять конкретным диалогом. 
// Например, замьютить навязчивого ухажера, не удаляя переписку. 
// Эти флаги относятся не к чату в целом (там два человека), а именно к участию конкретного юзера в этом чате. 
// Поэтому они лежат здесь.
// Безопасность удаления: Я убрал onDelete('cascade') с user_id. Если Вася удалит аккаунт, 
// Маша должна иметь возможность открыть чат с Васей и увидеть его последние 
// сообщения (хотя написать уже не сможет). Если бы стоял каскад, чат бы испарился и у Маши, 
// что вызвало бы баги на фронте.