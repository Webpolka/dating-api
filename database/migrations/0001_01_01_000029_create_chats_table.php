<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chats', function (Blueprint $table) {
            $table->id();

            // === ТИП ЧАТА ===
            $table->enum('type', ['private', 'support'])->default('private')->index();

            // Хэш участников (например md5(min($a,$b) . '-' . max($a,$b)))
            $table->string('participants_hash', 32)->nullable()->unique();

            // === КЭШ ПОСЛЕДНЕГО СООБЩЕНИЯ (Денормализация) ===
            $table->timestamp('last_message_at')->nullable()->index();

            // Блокировка чата админом
            $table->boolean('is_locked')->default(false)->index();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};


// Разбор архитектуры:

// Чистота таблицы: Заметь, здесь нет ни user_id, ни message_id. Это просто "коробка". 
// Если завтра ты захочешь сделать групповые чаты (по интересам) — тебе не придется менять структуру 
// этой таблицы, просто добавишь тип group и больше участников в chat_participants.
// Поле last_message_at: Это классическая денормализация для скорости. Когда юзер открывает список диалогов, 
// запрос летит мгновенно: SELECT * FROM chat_participants WHERE user_id = 1 ORDER BY last_message_at DESC. 
// А превью самого текста сообщения мы будем доставать легким подзапросом или хранить прямо в 
// participants (но это уже тонкая настройка кэширования).
// Индексы: Покрывают два главных сценария — вывод списка (по дате) и фильтрация в админке (по типу).