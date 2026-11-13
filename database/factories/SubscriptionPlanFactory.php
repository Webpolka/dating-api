<?php

namespace Database\Factories;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Фабрика тарифного плана подписки.
 */
class SubscriptionPlanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tier' => SubscriptionPlan::TIER_PREMIUM,
            'name' => 'Премиум на 30 дней',
            'slug' => 'premium-30-' . fake()->unique()->numberBetween(1000, 9999),
            'price' => 399.00,
            'old_price' => null,
            'currency' => 'RUB',
            'duration_days' => 30,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    /**
     * VIP-план.
     */
    public function vip(): static
    {
        return $this->state(fn (array $attributes) => [
            'tier' => SubscriptionPlan::TIER_VIP,
            'name' => 'VIP на 30 дней',
            'slug' => 'vip-30-' . fake()->unique()->numberBetween(1000, 9999),
            'price' => 799.00,
        ]);
    }

    /**
     * Неактивный (скрытый) план.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
