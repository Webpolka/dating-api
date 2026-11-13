<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            
            $table->string('code', 50)->unique();
            
            // Тип скидки: percent, fixed, premium, vip
            $table->enum('discount_type', ['percent', 'fixed', 'premium', 'vip'])->default('percent');
            
            // Размер скидки (если percent: 50 = 50%, если fixed: 500 = 500 рублей)
            $table->decimal('discount_value', 10, 2)->default(0);
            
            // Длительность подписки (если тип premium или vip, тут указываем дни)
            $table->unsignedSmallInteger('duration_days')->nullable();
            
            $table->unsignedBigInteger('max_uses')->nullable()->index(); 
            $table->unsignedBigInteger('used_count')->default(0);        
            
            $table->timestamp('expires_at')->nullable()->index();
            
            // Персональный промокод
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();

            // НОВЫЙ ИНДЕКС: Для быстрого поиска активных и неистекших промокодов
            $table->index(['is_active', 'expires_at']);
        });
    } 

    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};