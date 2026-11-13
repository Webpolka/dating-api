<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();

            $table->enum('type', ['in_app', 'push', 'email'])->default('in_app');
            $table->string('title');
            $table->text('message');
            $table->text('email_body')->nullable();

            // jsonb (для быстрого парсинга deep links на бэке)
            $table->jsonb('data')->nullable();

            // jsonb (чтобы база сама могла быстро искать юзеров по фильтрам внутри JSON)
            $table->jsonb('target_audience')->nullable();

            $table->enum('status', ['draft', 'scheduled', 'sending', 'sent', 'failed'])->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            // unsignedBigInteger (для защиты при рассылке по миллионам)
            $table->unsignedBigInteger('total_recipients')->default(0);
            $table->unsignedBigInteger('sent_count')->default(0);
            $table->unsignedBigInteger('failed_count')->default(0);

            $table->timestamps();

            // === ИНДЕКСЫ ===
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('broadcasts');
    }
};
