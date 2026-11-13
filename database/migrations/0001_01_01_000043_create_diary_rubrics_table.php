<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('diary_rubrics', function (Blueprint $table) {
            $table->id();

            // Кто создал рубрику. Если null — рубрика системная
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            
            $table->string('name');
            
            // slug глобально уникален, отдельный составной индекс не нужен
            $table->string('slug')->unique();
            
            $table->text('description')->nullable();
            
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            
            $table->timestamps();
 
            // Для быстрого вывода активных рубрик в меню (без filesort)
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diary_rubrics');
    }
};