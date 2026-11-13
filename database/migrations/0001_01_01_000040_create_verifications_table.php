<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verifications', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('photo_id')->nullable()->constrained('photos')->nullOnDelete();
            
            // enum (скорость индексов и защита от опечаток)
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->index();
            
            // enum (стандартный паттерн модерации)
            $table->enum('reject_reason', ['blurry', 'fake', 'no_code', 'minor', 'other'])->nullable();
            
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            
            $table->timestamps();

            // === ИНДЕКСЫ ===
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verifications');
    }
};