<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');

            // jsonb (Laravel по умолчанию пишет сюда JSON).
            // jsonb в PostgreSQL работает быстрее и позволяет делать запросы внутри уведомления.
            $table->jsonb('data');

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // Твой индекс - просто золото. Он закрывает главный запрос:
            // "Дай мне все непрочитанные уведомления юзера"
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
