<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fraud_alerts', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            
            $table->string('trigger_type', 50)->index();
            
            $table->enum('severity', ['low', 'medium', 'high'])->default('medium');
            
            //  jsonb (для поиска по IP и текстам внутри meta)
            $table->jsonb('meta')->nullable();
            
            //  enum (экономия места и скорость индексов)
            $table->enum('status', ['open', 'resolved', 'false_positive'])->default('open')->index();
            
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();
            
            // === ИНДЕКСЫ ===
            $table->index(['status', 'severity', 'created_at']);
            $table->index(['user_id', 'trigger_type']);
        });

        // GIN-индекс для мгновенного поиска по IP и другим полям внутри JSONB
        DB::statement('CREATE INDEX fraud_alerts_meta_gin_index ON fraud_alerts USING GIN (meta)');
    }

    public function down(): void
    {
        Schema::dropIfExists('fraud_alerts');
    }
};

// Антифрод-мониторинг — это иммунная система дейтинг-платформы. 

// Мошенники (скаммеры, ботовары, проститутки) — это главная причина оттока нормальных юзеров. 
// Если девушка заходит в аппку, а там 10 сообщений от ботов "кинь на карту 500 рублей", 
// она удалит приложение навсегда.

// Таблица fraud_alerts собирает сработки правил безопасности, чтобы админ (или автоматика) 
// мог оперативно банить негодяев.

// Разбор архитектуры (Как это работает в проде):

// Паттерн "Сбор улик": Когда срабатывает правило (например, регулярка нашла ссылку на Telegram в чате), 
// воркер не банит юзера сам. Он создает запись в fraud_alerts со статусом open и складывает 
// доказательства в meta.
// Автоматизация (Severity): В админке ты сможешь настроить правила: если severity = 'high' 
// (например, ИИ распознал детскую порнографию в чате), система автоматически ставит юзеру status = 'banned' 
// в таблице users И создает алерт, чтобы админ просто подтвердил бан. Если severity = 'low', 
// юзер просто попадает в список "на проверку".
// Защита от спама алертов: Индекс ['user_id', 'trigger_type'] позволяет воркеру сделать проверку: 
// "А нет ли уже открытого алерта на этого юзера за 'links_in_chat'?". Если есть, мы не создаем дубликаты, 
// иначе таблица раздуется до миллионов строк за час.