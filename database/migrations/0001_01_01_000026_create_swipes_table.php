<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('swipes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('type', ['like', 'dislike', 'superlike'])->default('like');
            $table->timestamp('rewinded_at')->nullable();

            $table->timestamps();

            // === ИНДЕКСЫ === 

            // 1. Чтобы юзер не мог свайпнуть одного человека дважды.
            $table->unique(['user_id', 'target_user_id']);

            // 2. Для сборки ленты (исключить тех, кого уже свайпнул)
            $table->index(['user_id', 'type']);
        });

        // Киллер-фича: Partial Index.
        // Добавил type в индекс, чтобы при проверке мэтча база сделала Index-Only Scan.
        // Запрос: WHERE target_user_id = 1 AND user_id = 2 AND type IN ('like', 'superlike')
        DB::statement("CREATE INDEX swipes_match_check_index ON swipes (target_user_id, user_id, type) WHERE rewinded_at IS NULL AND type IN ('like', 'superlike')");
    }

    public function down(): void
    {
        Schema::dropIfExists('swipes');
    }
};


// Как работает "Отмена свайпа" (Rewind):
// Представь ситуацию:

// Ты свайпнул Машу влево (type = 'dislike'). В БД появилась запись: user_id = Ты, target_user_id = Маша, type = dislike.
// Ты понял, что ошибся. Жмешь кнопку "Отменить" (в дейтингах она обычно платная/VIP).
// Laravel делает UPDATE этой записи: rewinded_at = NOW(). Свайп не удаляется из БД!
// Что происходит дальше при сборке ленты (поиска анкет):
// Когда система ищет тебе следующую анкету, она делает запрос:
// "Дай мне юзеров, которых я еще не оценивал".
// Запрос выглядит так:

// sql

// SELECT * FROM users 
// WHERE id NOT IN (
//     SELECT target_user_id FROM swipes 
//     WHERE user_id = 'Ты' AND rewinded_at IS NULL
// )
// Так как у свайпа с Машей rewinded_at теперь заполнен (не NULL), система считает, что ты её не оценивал. И Маша снова появляется у тебя в ленте! Ты можешь свайпнуть её вправо (like).

// Что произойдет в БД, когда ты свайпнешь её заново?
// Так как у нас стоит строгий уникальный индекс:
// $table->unique(['user_id', 'target_user_id']);

// Ты не сможешь создать вторую запись для Маши. БД выдаст ошибку дубликата.
// Поэтому в коде (в сервис-классе) логика будет такой:

// Проверяем: есть ли уже свайп (любой, включая отмененный) на Машу?
// Если да, делаем UPDATE: меняем type на like и сбрасываем rewinded_at = NULL.
// Если нет, делаем INSERT новой записи.
// Это очень изящный паттерн. Мы сохраняем полную историю (что ты сначала ее задизлайкал, потом передумал), не нарушаем уникальность и корректно показываем анкету снова.

// Так что поле rewinded_at — это правильный архитектурный ход.
