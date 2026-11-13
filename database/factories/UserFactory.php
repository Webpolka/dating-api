<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->e164PhoneNumber(),
            'password' => Hash::make('password'), // Один пароль для всех тестовых
            'role' => User::ROLE_USER,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
            'last_seen' => now()->subMinutes(rand(1, 43200)), // Онлайн от 1 минуты до 30 дней назад
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Админ (полный доступ к админке).
     */
    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_ADMIN,
        ]);
    }

    /**
     * Модератор (модерация контента, без финансов/стаффа).
     */
    public function moderator(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_MODERATOR,
        ]);
    }

    /**
     * Саппорт (тикеты поддержки; НЕ имеет доступа к модерации фото).
     */
    public function support(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_SUPPORT,
        ]);
    }

    /**
     * Забаненный юзер (perma-ban: banned_until = null).
     */
    public function banned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => User::STATUS_BANNED,
            'ban_reason' => 'Нарушение правил сервиса',
            'banned_until' => null,
        ]);
    }
}
