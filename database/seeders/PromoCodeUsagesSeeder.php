<?php

namespace Database\Seeders;

use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PromoCodeUsagesSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🎟️ Генерируем историю использования промокодов (массовая вставка)...');

        PromoCodeUsage::truncate();
        PromoCode::query()->update(['used_count' => 0]);

        $users = User::where('role', 'user')->get();
        
        if ($users->isEmpty()) {
            $this->command->warn('⚠️ Нет юзеров для генерации.');
            return;
        }

        // 1. Грузим все промокоды и транзакции в память (O(1) запросов)
        $promoCodes = PromoCode::all();
        $transactions = Transaction::where('status', 'success')->get()->groupBy('user_id');

        $usagesData = [];
        $promoUsageCounts = []; // Счетчик использований для каждого промокода
        $now = now();

        $bar = $this->command->getOutput()->createProgressBar($users->count());

        foreach ($users as $user) {
            // Симулируем, что 30% юзеров использовали промокод
            if (rand(1, 10) > 3) {
                $bar->advance();
                continue;
            }

            // 1. Фильтруем валидные промокоды в памяти
            $validPromos = $promoCodes->filter(fn($p) => $p->isValidForUser($user))->values();
            
            if ($validPromos->isEmpty()) {
                $bar->advance();
                continue;
            }

            // 2. Берем один случайный валидный промокод
            $promo = $validPromos->random();

            // 3. Находим транзакцию в памяти (если нужна скидка)
            $transaction = null;
            if (!in_array($promo->discount_type, [PromoCode::TYPE_PREMIUM, PromoCode::TYPE_VIP])) {
                $userTransactions = $transactions->get($user->id);
                if (empty($userTransactions)) {
                    $bar->advance();
                    continue;
                }
                 $transaction = $userTransactions->random();
            }

            // 4. Считаем снапшот скидки в памяти (без вызова apply() и транзакций БД)
            $appliedDiscount = 0;
            if ($transaction) {
                $appliedDiscount = $promo->calculateDiscount((float) $transaction->amount);
            }

            $date = $now->copy()->subDays(rand(1, 30));
            
            $usagesData[] = [
                'promo_code_id' => $promo->id,
                'user_id' => $user->id,
                'transaction_id' => $transaction?->id,
                'applied_discount' => $appliedDiscount,
                'created_at' => $date,
                'updated_at' => $date,
            ];

            // Увеличиваем счетчик в памяти
            if (!isset($promoUsageCounts[$promo->id])) {
                $promoUsageCounts[$promo->id] = 0;
            }
            $promoUsageCounts[$promo->id]++;

            $bar->advance();
        }

        // 5. Массовая вставка историй использования (1 запрос)
        if (!empty($usagesData)) {
            PromoCodeUsage::insert($usagesData);
        }

        // 6. Обновляем счетчики used_count у промокодов (1 запрос на каждый промо)
        foreach ($promoUsageCounts as $promoId => $count) {
            PromoCode::where('id', $promoId)->increment('used_count', $count);
        }

        $bar->finish();
        $this->command->newLine(2);
        $this->command->info("✅ Реалистично применено промокодов: " . count($usagesData));
    }
}