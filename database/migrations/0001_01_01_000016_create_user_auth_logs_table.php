<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_auth_logs', function (Blueprint $table) {
            $table->id();
            
            // nullOnDelete: по закону логи авторизации должны храниться даже если юзер удален!
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            
            $table->string('ip_address', 45)->index();
            $table->text('user_agent')->nullable(); // text, т.к. UA бывает огромным
            $table->string('device_os', 50)->nullable(); 
            $table->string('device_type', 50)->nullable(); 
            
            $table->boolean('is_successful')->default(true);
            
            $table->timestamps();

            // === ИНДЕКСЫ ===
            
            // 1. История входов юзера (пагинация)
            $table->index(['user_id', 'created_at']);
            
            // 2. АНТИФРОД (Критично!): мгновенный поиск неудачных входов с IP за последний час
            $table->index(['ip_address', 'is_successful', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_auth_logs');
    }
};