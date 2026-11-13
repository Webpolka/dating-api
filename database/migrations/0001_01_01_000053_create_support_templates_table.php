<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_templates', function (Blueprint $table) {
            $table->id(); 
            
            // ФИКС: Заменили строку на Foreign Key. 
            // nullable() чтобы можно было удалить категорию, и шаблон не упал, а стал "Без категории"
            // (хотя в коде мы переносим в "Общие", но для БД безопаснее сделать nullable)
            $table->foreignId('category_id')->nullable()->constrained('support_template_categories')->nullOnDelete();
            
            $table->string('title');    
            $table->text('body');       
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // Для быстрого вывода активных шаблонов в мессенджере саппорта
            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_templates');
    }
};