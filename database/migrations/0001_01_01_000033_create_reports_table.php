<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            
            //  Жалоба остается для СБ.
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete(); 
            $table->foreignId('reported_id')->nullable()->constrained('users')->nullOnDelete(); 
            
            $table->nullableMorphs('reportable'); 

            // enum (для скорости индексов и экономии места)
            $table->enum('reason', ['spam', 'porn', 'scam', 'insult', 'minor', 'fake'])->index(); 
            $table->text('description')->nullable();

            // enum
            $table->enum('status', ['pending', 'resolved', 'rejected'])->default('pending')->index();
 
            $table->enum('resolution', ['ban', 'temp_ban', 'warn', 'shadowban', 'photo_deleted', 'no_action'])->nullable(); 
            $table->text('resolution_note')->nullable(); 

            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable(); 

            $table->timestamps();
            $table->softDeletes(); 

            // === ИНДЕКСЫ ===
            $table->index(['status', 'created_at']);
            $table->index(['reporter_id', 'reported_id']);
            $table->index('admin_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};