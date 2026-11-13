<?php 

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
 
return new class extends Migration {
    public function up(): void {
        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('subscription_plans')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();

            // Кэшируем tier (premium/vip). У юзера может быть 2 независимые записи.
            $table->enum('tier', ['premium', 'vip'])->index(); 
            
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            
            // Логика автопродления
            $table->boolean('is_auto_renew')->default(false);
            $table->string('provider_subscription_id')->nullable()->index();
            
            // Перевели string в enum для скорости индексов и экономии места
            $table->enum('status', ['active', 'canceled', 'expired', 'failed'])->default('active')->index();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('expires_notified_at')->nullable()->index(); 
            $table->timestamps();
            
            // === ИНДЕКСЫ ===
            // Для моментальной проверки в middleware: 
            // WHERE user_id = ? AND tier = 'vip' AND status = 'active' AND ends_at > now()
            $table->index(['user_id', 'tier', 'status', 'ends_at']);
            
            // Для крона (сбор просроченных подписок):
            // WHERE status = 'active' AND ends_at < now()
            $table->index(['status', 'ends_at']);
        });
    }
    public function down(): void { 
        Schema::dropIfExists('user_subscriptions'); 
    }
};