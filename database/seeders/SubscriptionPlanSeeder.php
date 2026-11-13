<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
 
class SubscriptionPlanSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('💎 Создаем тарифы (Premium & VIP)...');

        $plans = [
            // === PREMIUM (Базовая подписка) ===
            [
                'tier' => SubscriptionPlan::TIER_PREMIUM,
                'name' => 'Premium на 3 дня',
                'price' => 149.00,
                'old_price' => 199.00,
                'duration_days' => 3,
                'is_active' => true,
                'sort_order' => 1,
            ],
            [
                'tier' => SubscriptionPlan::TIER_PREMIUM,
                'name' => 'Premium на 1 неделю',
                'price' => 299.00,
                'old_price' => 399.00,
                'duration_days' => 7,
                'is_active' => true,
                'sort_order' => 2,
            ],
            [
                'tier' => SubscriptionPlan::TIER_PREMIUM,
                'name' => 'Premium на 1 месяц',
                'price' => 690.00,
                'old_price' => 990.00,
                'duration_days' => 30,
                'is_active' => true,
                'sort_order' => 3,
            ],
            [
                'tier' => SubscriptionPlan::TIER_PREMIUM,
                'name' => 'Premium на 3 месяца (Выгодный)',
                'price' => 1690.00,
                'old_price' => 2970.00,
                'duration_days' => 90,
                'is_active' => true,
                'sort_order' => 4,
            ],
            
            // === VIP (Буст выдачи) ===
            [
                'tier' => \App\Models\SubscriptionPlan::TIER_VIP,
                'name' => 'VIP на 1 неделю',
                'price' => 400.00,
                'old_price' => 590.00,
                'duration_days' => 7,
                'is_active' => true,
                'sort_order' => 5,
            ],

            // === АРХИВ (Скрыт из продажи, но в БД остался для старых юзеров) ===
            [
                'tier' => SubscriptionPlan::TIER_PREMIUM,
                'name' => 'Premium OLD (Архив)',
                'price' => 999.00,
                'old_price' => null,
                'duration_days' => 60,
                'is_active' => false,
                'sort_order' => 99,
            ],
        ];

        $bar = $this->command->getOutput()->createProgressBar(count($plans));

        foreach ($plans as $plan) {
            $slug = Str::slug($plan['name']);

            // ИСПОЛЬЗУЕМ updateOrCreate для защиты от дубликатов при повторном запуске
            SubscriptionPlan::updateOrCreate(
                ['slug' => $slug],
                [
                    'tier' => $plan['tier'],
                    'name' => $plan['name'],
                    'price' => $plan['price'],
                    'old_price' => $plan['old_price'],
                    'currency' => 'RUB',
                    'duration_days' => $plan['duration_days'],
                    'apple_product_id' => 'com.flirtlove.' . $plan['tier'] . '.' . $plan['duration_days'],
                    'google_product_id' => $plan['tier'] . '_' . $plan['duration_days'] . '_days',
                    'is_active' => $plan['is_active'],
                    'sort_order' => $plan['sort_order'],
                ]
            );
            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine(2);

        // ============================================
        // СТАТИСТИКА
        // ============================================
        $total = SubscriptionPlan::count();
        $active = SubscriptionPlan::where('is_active', true)->count();
        $inactive = SubscriptionPlan::where('is_active', false)->count();
        $premiumCount = SubscriptionPlan::where('tier', 'premium')->count();
        $vipCount = SubscriptionPlan::where('tier', 'vip')->count();

        $this->command->info('✅ Создано тарифов: ' . $total);
        $this->command->info('');
        $this->command->info('📊 Статистика тарифов:');
        $this->command->info("   ┌───────────────────────┬──────────┐");
        $this->command->info("   │ Показатель            │ Кол-во   │");
        $this->command->info("   ├───────────────────────┼──────────┤");
        $this->command->info("   │ Всего                 │ {$total}        │");
        $this->command->info("   │ Активных (в продаже)  │ {$active}        │");
        $this->command->info("   │ Скрытых (архив)       │ {$inactive}        │");
        $this->command->info("   │ Premium тарифов       │ {$premiumCount}        │");
        $this->command->info("   │ VIP тарифов           │ {$vipCount}        │");
        $this->command->info("   └───────────────────────┴──────────┘");
    }
}