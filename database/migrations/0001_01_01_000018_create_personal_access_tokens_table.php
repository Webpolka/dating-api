<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            
            // morphs создает tokenable_type и tokenable_id
            $table->string('tokenable_type');
            $table->unsignedBigInteger('tokenable_id');
            
            $table->string('name'); // Название устройства (например, "iPhone 15 Pro")
            
            // Хэш токена. КРИТИЧЕСКИ ВАЖНО: unique индекс для мгновенного поиска при API-запросах!
            $table->string('token', 64)->unique(); 
            
            // jsonb для прав доступа (вместо text)
            $table->jsonb('abilities')->nullable(); 
            
            $table->timestamp('last_used_at')->nullable()->index(); // Когда последний раз использовался
            $table->timestamp('expires_at')->nullable()->index(); // Когда протухает (для безопасности мобильных приложений)
            
            $table->timestamps();

            // Индекс для кнопки "Выйти со всех устройств" в личном кабинете
            $table->index(['tokenable_type', 'tokenable_id', 'created_at']);
        });
    } 

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};