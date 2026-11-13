<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration {
    public function up(): void {
        Schema::create('user_boosts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            
            // Фото, которое будет показываться в ленте во время буста
            $table->foreignId('photo_id')->nullable()->constrained('photos')->nullOnDelete();

            // Кэшируем тип (string оставляем, если планируешь добавить 'up_profile', 'up_search' и т.д.)
            $table->string('type', 50)->default('profile_boost')->index();
            
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            
            // Перевели в enum для скорости
            $table->enum('status', ['active', 'expired'])->default('active')->index();
            $table->timestamps();

            // === ИНДЕКСЫ ===
            
            // 1. Для крона (мгновенный поиск просроченных активных бустов)
            $table->index(['status', 'ends_at']);
            
            // 2. Для вывода истории бустов юзера (с пагинацией)
            $table->index(['user_id', 'created_at']);
        });
    }
    public function down(): void { 
        Schema::dropIfExists('user_boosts'); 
    }
};