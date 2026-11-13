<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            
            $table->string('collection')->default('default')->index();
            $table->string('file_name');
            $table->string('disk_path');

            // ИЗМЕНЕНО: json -> jsonb (для быстрого парсинга вариантов в Postgres)
            $table->jsonb('variants')->nullable();
            
            $table->string('url')->index(); // НОВЫЙ: Индекс для быстрого поиска по URL
            
            // ИЗМЕНЕНО: string -> enum (защита от мусора и скорость)
            $table->enum('type', ['image', 'video', 'document'])->default('image')->index();
            $table->string('mime_type')->nullable();
            
            // ИЗМЕНЕНО: unsignedInteger -> unsignedBigInteger (для тяжелых видео)
            $table->unsignedBigInteger('size')->nullable(); 
            
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamps();
        }); 
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};