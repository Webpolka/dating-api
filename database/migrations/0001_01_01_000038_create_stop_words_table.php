<?php
 
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stop_words', function (Blueprint $table) {
            $table->id();
            
            // Ограничил 255 символами для защиты от спама в БД, regex тоже влезет
            $table->string('word', 255)->unique();
            
            $table->string('category', 50)->default('mat')->index();
            
            $table->enum('action', ['mask', 'reject', 'alert'])->default('mask');
            $table->string('replacement', 10)->default('***');
            
            $table->boolean('is_active')->default(true);
            
            $table->timestamps();
        });

        // Киллер-фича: Partial Index.
        // Воркер будет запрашивать только активные слова для кэша. Этот индекс будет весить копейки и работать мгновенно.
        DB::statement("CREATE INDEX stop_words_active_category_index ON stop_words (category) WHERE is_active = true");
    }

    public function down(): void
    {
        Schema::dropIfExists('stop_words');
    }
};

// Стоп-слова — это базовый, но критически важный фильтр. В дейтинге 80% спамеров и мошенников используют 
// стандартные фразы, номера телефонов и ссылки на мессенджеры.

// Таблица stop_words — это справочник, который мы будем кэшировать в Redis 
// (чтобы не дергать БД на каждое сообщение в чате) и прогонять через него тексты при создании анкеты, 
// комментариев и сообщений.

// Разбор архитектуры (Как это работает в проде):

// Поле action (Гибкость модерации): Это супер-фича. Например, если юзер пишет мат в чате, 
// мы не блокируем сообщение, а просто заменяем его на *** (action = 'mask'). Но если юзер 
// пишет "Переведи мне 500 рублей на карту", мы не можем это замаскировать, мы просто отклоняем 
// сообщение (action = 'reject'). А если юзер пишет "Пиши мне в телеграм @scammer", 
// мы пропускаем это (чтобы он не понял, что мы его спалили), но кидаем алерт в 
// fraud_alerts (action = 'alert'), чтобы антифрод-система наложила на него теневой бан.
// Кэширование: В коде (Laravel) мы сделаем ServisClass ContentFilter, который при старте 
// приложения будет грузить все is_active слова из БД в Redis (или в массив). Проверка текста будет происходить 
// в оперативной памяти за миллисекунды, вообще не трогая базу данных.
// replacement: Зачем поле? В некоторых дейтингах мат заменяют не на ***, 
// а на смешные слова (например, "бяка" или "вишенка"). Это поле дает админу свободу настройки 
// фильтра под tone of voice (голос) проекта.