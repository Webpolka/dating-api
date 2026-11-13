<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
 
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('album_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('type', ['profile', 'verification'])->default('profile');
            
            $table->string('path_original');
            $table->string('path_large')->nullable();    
            $table->string('path_medium')->nullable();   
            $table->string('path_thumb')->nullable();    

            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending')->index();
            $table->string('reject_reason')->nullable();
            
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();

            $table->boolean('is_primary')->default(false);
            $table->boolean('is_intimate')->default(false);
            $table->unsignedInteger('position')->default(0);
            
            $table->string('title')->nullable();
            $table->text('description')->nullable();

            $table->string('phash', 16)->nullable()->index(); 

            $table->timestamps();
            $table->softDeletes();

            // === ИНДЕКСЫ ===
            $table->index(['user_id', 'status', 'type']);
            $table->index(['user_id', 'is_primary']);
            $table->index(['album_id', 'position']);
            $table->index(['status', 'type', 'created_at']); 
        });

        // Киллер-фича: Гарантирует, что у юзера будет только ОДНА аватарка (среди неудаленных)
        DB::statement('CREATE UNIQUE INDEX photos_user_primary_unique ON photos (user_id) WHERE is_primary = true AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('photos');
    }
};