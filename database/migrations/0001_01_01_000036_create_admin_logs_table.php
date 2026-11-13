<?php 

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_logs', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            
            $table->string('action', 100)->index();
            
            $table->nullableMorphs('loggable'); 

            // ИЗМЕНЕНО: json -> jsonb (для быстрого парсинга диффов на фронтенде)
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->jsonb('participants')->nullable();

            $table->ipAddress('ip_address')->nullable();
            // ИЗМЕНЕНО: string -> text (защита от переполнения, боты шлют огромные UA)
            $table->text('user_agent')->nullable();

            $table->timestamps();
            
            // === ИНДЕКСЫ ===
            $table->index(['admin_id', 'created_at']);
            
            // НОВЫЙ: Для вывода истории действий над конкретным объектом (например, вся история банов юзера 5)
            $table->index(['loggable_type', 'loggable_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_logs');
    }
};

// Разбор архитектуры (Big Brother is watching):

// Полиморфность (nullableMorphs): Это магия Laravel. 
// Тебе не нужно создавать отдельные таблицы для логов банов, логов фоток, логов финансов. 
// Все пишется сюда. Если модератор забанил юзера, пишется: 
// action = 'user.ban', loggable_type = 'User', loggable_id = 10. 
// Если саппорт сделал рефанд: 
// action = 'transaction.refund', loggable_type = 'Transaction', loggable_id = 55.
// before и after: Это спасение для "ой, я не туда нажал". 
// Если админ случайно изменил цену тарифа с 500 на 5 рублей, ты открываешь лог, 
// видишь дифф before и в один клик можешь откатить значение обратно. 
// Мы будем заполнять эти поля в Observer-классах Laravel.
// Отсутствие softDeletes: Логи аудита должны быть неизменны. Их нельзя удалить никак, 
// кроме как прямым SQL-запросом в базу (к которому имеет доступ только DevOps).