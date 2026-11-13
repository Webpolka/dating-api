<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();

            $table->string('slug')->unique();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cover_media_id')->nullable()->constrained('media')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('blog_categories')->nullOnDelete();

            $table->string('title');
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();

            // ИЗМЕНЕНО: string -> enum (защита от мусора и скорость)
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft')->index();

            $table->boolean('is_featured')->default(false);

            // ИЗМЕНЕНО: unsignedInteger -> unsignedBigInteger (защита от переполнения на вирусных постах)
            $table->unsignedBigInteger('views_count')->default(0);

            $table->timestamps();
            $table->softDeletes();

            // === ИНДЕКСЫ ===

            // 1. Главный индекс ленты блога (все опубликованные по дате)
            $table->index(['status', 'created_at']);

            // 2. НОВЫЙ: Для вывода постов внутри конкретной категории (с пагинацией)
            $table->index(['category_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
    }
};
