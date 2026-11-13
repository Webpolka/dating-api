<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('albums', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            
            $table->string('name')->default('Общие');
            $table->text('description')->nullable();
            
            $table->boolean('is_default')->default(false);
            $table->boolean('is_private')->default(false);
                      
            $table->unsignedBigInteger('photos_count')->default(0);
            
            $table->timestamps();
            $table->softDeletes();
            
            // Добавили created_at для пагинации/сортировки альбомов
            $table->index(['user_id', 'is_private', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('albums');
    }
};