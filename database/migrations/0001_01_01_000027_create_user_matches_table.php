<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_matches', function (Blueprint $table) {
            $table->id();

            // === ГЛАВНОЕ ПРАВИЛО МЭТЧЕЙ ===
            // user1_id ВСЕГДА меньше user2_id. Защита от дубликатов (5-10 и 10-5).
            $table->foreignId('user1_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user2_id')->nullable()->constrained('users')->nullOnDelete();

            // === СТАТУС МЭТЧЕЙ ===
            $table->enum('status', ['active', 'unmatched'])->default('active')->index();

            // Кто инициировал разрыв (аналитика, саппорт)
            $table->foreignId('unmatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unmatched_at')->nullable();

            $table->timestamps();

            // === ИНДЕКСЫ ===

            // 1. Защита от дубликатов пар (с учетом правила user1 < user2)
            $table->unique(['user1_id', 'user2_id']);

            // 2. КРИТИЧЕСКИ ВАЖНЫЕ ИНДЕКСЫ ДЛЯ ЛЕНТЫ МЭТЧЕЙ
            // Запрос: WHERE user1_id = ? AND status = 'active' ORDER BY created_at DESC
            // Добавили created_at, чтобы пагинация летала без сортировки в памяти (filesort)
            $table->index(['user1_id', 'status', 'created_at']);
            $table->index(['user2_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_matches');
    }
};

// В будущем MatchService для кнопки "Лайк" !
// try {
//     $match = UserMatch::createMatch($userA, $userB);
//     // Отправляем пуш "У вас мэтч!"
// } catch (\Illuminate\Database\QueryException $e) {
//     if ($e->errorInfo[1] == 23505) { // Код ошибки дубликата в PostgreSQL
//         $match = UserMatch::where('user1_id', min($userA, $userB))->where('user2_id', max($userA, $userB))->first();
//         // Мэтч уже создан параллельным процессом, всё ок, просто берем его.
//     } else {
//         throw $e;
//     }
// }


// Главные фишки этой структуры:

// Правило user1_id < user2_id: Это золотой стандарт дейтинга (Tinder, Badoo). Если его не соблюдать, 
// тебе придется писать страшные запросы с OR для проверки дубликатов, 
// а уникальный индекс unique не сможет защитить от записи 10-5 и 5-10. В коде это будет выглядеть так:

// php

// $user1 = min($myId, $targetId);
// $user2 = max($myId, $targetId);
// UserMatch::create(['user1_id' => $user1, 'user2_id' => $user2]);


// Разрыв мэтча (Unmatch): Мы не удаляем запись из БД, когда юзеры разрывают мэтч. 
// Мы ставим status = 'unmatched'. Это нужно для того, чтобы:
// Выводить историю в админке (они сматчились 1 января, разорвали 5 января).
// Предотвращать повторные мэтчи (если они разорвали мэтч, они не должны снова появиться друг у друга в ленте).
// Нет Soft Deletes: Я не добавил softDeletes сюда. Почему? 
// Потому что поле status = 'unmatched' полностью закрывает потребность. 
// Мы не прячем мэтч, мы явно меняем его состояние. Это удобнее для запросов и аналитики.