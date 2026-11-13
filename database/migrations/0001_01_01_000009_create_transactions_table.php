<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('user_id')->constrained('users')->nullable()->nullOnDelete();
            
            $table->decimal('amount', 8, 2);
            $table->string('currency', 3)->default('RUB');
            
            $table->enum('type', ['subscription', 'credits', 'refund'])->default('subscription');
            $table->enum('status', ['pending', 'success', 'failed', 'refunded'])->default('pending')->index();
            
            $table->string('provider', 30)->nullable();
            $table->string('provider_transaction_id')->nullable();
            
            // BigInteger для совпадения с таблицей user_balances
            $table->unsignedBigInteger('credits_amount')->nullable();
            
            // ИЗМЕНЕНО: json -> jsonb (для быстрого поиска внутри payload от банков)
            $table->jsonb('meta')->nullable();
            
            $table->timestamps();

            // === ИНДЕКСЫ ===
            
            // 1. КРИТИЧЕСКИ ВАЖНО: Защита от дублей вебхуков!
            // Уникальность по связке Провайдер + ID Транзакции провайдера.
            $table->unique(['provider', 'provider_transaction_id']);
            
            // 2. История платежей юзера (с пагинацией)
            $table->index(['user_id', 'status', 'created_at']);
            
            // 3. Фин. дашборд (выручка за период)
            $table->index(['status', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};

// Разбор архитектуры (Финтех-стандарты):

// Отсутствие softDeletes(): В финансах не бывает "корзины". Если транзакция провалилась, 
// она остается в БД со статусом failed. Если юзер потребовал возврат (чарджбэк в банке), 
// мы ищем эту транзакцию и меняем статус на refunded, а в meta пишем причину. 
// Удалять ничего нельзя — иначе твоя бухгалтерия разъедется с бухгалтерией банка.
// provider_transaction_id: Это спасение для саппорта. Юзер пишет: "С меня списали 500 рублей, 
// а VIP не дали!". Ты открываешь админку, видишь транзакцию со статусом failed. 
// Берешь provider_transaction_id, идешь в личный кабинет ЮKassa/Stripe, вбиваешь его и видишь, 
// что банк отклонил платеж (например, не прошел 3-D Secure). Проблема решена за 10 секунд.
// type = 'refund' и type = 'credits': Дейтинг monetizes не только через подписки. 
// Юзер может докупить 100 кредитов, чтобы отправить 10 подарков. И он может потребовать возврат за эти кредиты, если его забанили. Эта структура позволит тебе разделить выручку на потоки (подписки vs микротранзакции).
// Финансовый блок закрыт на 100%! База готова к приему реальных денег.