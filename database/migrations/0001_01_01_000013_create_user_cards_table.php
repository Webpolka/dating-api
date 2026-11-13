<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_cards', function (Blueprint $table) {
            $table->id();
            
            // === СВЯЗИ ===
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            // === ДАННЫЕ КАРТЫ ===
            $table->string('gateway', 30)->default('yookassa')->index();
            
            // Токен карты. Уникальность делаем по связке Шлюз + Токен, 
            // чтобы не было коллизий при добавлении новых шлюзов (Stripe, CloudPayments)
            $table->string('payment_method_id');
            
            $table->string('card_type', 30)->nullable(); // visa, mastercard, mir
            $table->string('last4', 4)->nullable();
            
            $table->string('expiry_month', 2)->nullable();
            $table->string('expiry_year', 4)->nullable();

            // === СТАТУСЫ ===
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false); 
            
            $table->timestamps();
            $table->softDeletes(); // КРИТИЧЕСКИ ВАЖНО для фин. отчетов

            // === ИНДЕКСЫ ===
            $table->unique(['gateway', 'payment_method_id']);
            
            // Моментальный поиск активных карт юзера (с приоритетом default-карты)
            $table->index(['user_id', 'is_active', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_cards');
    }
};