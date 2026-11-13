<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_code_usages', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            
            // nullOnDelete, чтобы сохранить историю транзакций
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            
            // Снапшот примененной скидки в рублях (для фин. отчетов)
            $table->decimal('applied_discount', 10, 2)->default(0);
            
            $table->timestamps();

            // Защита: 1 юзер = 1 применение кода
            $table->unique(['promo_code_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_code_usages');
    }
};