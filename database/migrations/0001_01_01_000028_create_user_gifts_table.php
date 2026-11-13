<?php 

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_gifts', function (Blueprint $table) {
            $table->id();
            
            // === КТО И КОМУ ===
            // nullOnDelete: Если отправитель/получатель удалит аккаунт, 
            // история подарков (и фин. отчетность) не должна схлопнуться.
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('receiver_id')->nullable()->constrained('users')->nullOnDelete();
            
            // === СВЯЗЬ С КАТАЛОГОМ ===
            $table->foreignId('gift_id')->nullable()->constrained('gifts')->nullOnDelete();
            
            // === СНАПШОТ (Данные на момент отправки) ===
            $table->string('snapshot_name'); 
            $table->string('snapshot_image_url'); 
            $table->unsignedInteger('snapshot_price'); 

            // === ДОП. ИНФА ===
            $table->string('message')->nullable(); 
            $table->boolean('is_private')->default(false);
            
            // === СТАТУС ПРОЧТЕНИЯ ===
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();

            $table->timestamps();
            
            // Мягкое удаление (юзер скрыл подарок со страницы, но в БД он остался)
            $table->softDeletes();

            // === ИНДЕКСЫ ===
            
            // 1. Для вывода подарков в анкете юзера (только публичные)
            $table->index(['receiver_id', 'is_private']);
            
            // 2. Для пагинации истории "Подарки мне" и "Мои отправленные"
            $table->index(['receiver_id', 'created_at']);
            $table->index(['sender_id', 'created_at']);
            
            // 3. Для счетчика непрочитанных подарков
            $table->index(['receiver_id', 'is_read']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_gifts');
    }
};