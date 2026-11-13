<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP TABLE IF EXISTS user_interests CASCADE');
        
        Schema::create('user_interests', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->constrained('users')
                ->onDelete('cascade');

            $table->foreignId('interest_id')
                ->constrained('interests')
                ->onDelete('cascade');

            $table->timestamp('created_at')->useCurrent();

            $table->primary(['user_id', 'interest_id']);
            $table->index('interest_id');
        });

        DB::statement("ALTER TABLE user_interests ALTER COLUMN created_at SET DEFAULT NOW()");
    }

    public function down(): void
    {
        Schema::dropIfExists('user_interests');
    }
};