<?php 

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gifts', function (Blueprint $table) {
            $table->id();
            
            $table->string('name');
            $table->string('slug')->unique();
            
            $table->string('image_url');
            
            // ИЗМЕНЕНО: unsignedInteger -> unsignedBigInteger (для совпадения с user_balances)
            $table->unsignedBigInteger('price');
            
            $table->string('category', 50)->nullable()->index();
            $table->enum('type', ['main', 'premium', 'services'])->default('main')->index();
            $table->boolean('is_active')->default(true)->index();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gifts');
    }
};


// Разбор архитектуры:

// Здесь всё максимально лаконично и правильно.

// price (Цена в кредитах): Заметь, мы используем unsignedInteger, а не decimal. 
// В дейтингах внутренняя валюта (кредиты/монеты) всегда целые числа, чтобы избежать проблем с 
// плавающей запятой (0.1 + 0.2 = 0.30000000000000004).
// is_active: Позволяет админу скрывать подарки на праздники (например, новогодние) или убирать 
// нерентабельные, не нарушая историю отправленных.