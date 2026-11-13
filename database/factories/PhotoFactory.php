<?php

namespace Database\Factories;

use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Фабрика фото для тестов модерации.
 *
 * Пути — фейковые строки: реальные файлы не нужны, пока тест не
 * затрагивает ProcessApprovedPhoto (нарезку делает job).
 */
class PhotoFactory extends Factory
{
    public function definition(): array
    {
        $uid = fake()->unique()->numberBetween(100000, 999999);

        return [
            'user_id' => User::factory(),
            'type' => 'public',
            'path_original' => "photos/test/{$uid}_original.jpg",
            'path_large' => "photos/test/{$uid}_large.jpg",
            'path_medium' => "photos/test/{$uid}_medium.jpg",
            'path_thumb' => "photos/test/{$uid}_thumb.jpg",
            'status' => Photo::STATUS_PENDING,
            'is_primary' => false,
            'is_intimate' => false,
            'position' => 0,
        ];
    }

    /**
     * Одобренное фото.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Photo::STATUS_APPROVED,
        ]);
    }

    /**
     * Отклонённое фото.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Photo::STATUS_REJECTED,
            'reject_reason' => 'other',
        ]);
    }
}
