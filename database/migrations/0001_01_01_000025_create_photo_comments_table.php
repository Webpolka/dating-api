<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_comments', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('photo_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            
            $table->text('content');
            
            $table->enum('status', ['pending', 'approved', 'rejected', 'spam'])->default('pending');
            $table->string('reject_reason')->nullable();
            
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            
            $table->foreignId('parent_id')->nullable()->constrained('photo_comments')->nullOnDelete();
            
            // BigInteger для защиты от переполнения на вирусных фото
            $table->unsignedBigInteger('likes_count')->default(0);
            $table->unsignedBigInteger('reports_count')->default(0);
            $table->unsignedBigInteger('replies_count')->default(0); 
            
            $table->boolean('is_pinned')->default(false); 
            $table->timestamp('edited_at')->nullable(); 
            
            $table->timestamps();
            $table->softDeletes();
            
            // === ИНДЕКСЫ ===
            
            // Добавили created_at для моментальной пагинации дерева комментариев
            $table->index(['photo_id', 'status', 'parent_id', 'created_at']);
            
            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_comments');
    }
};