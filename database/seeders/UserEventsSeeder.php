<?php

namespace Database\Seeders;

use App\Enums\UserEventType; 
use App\Models\User;
use App\Models\UserEvent;
use Illuminate\Database\Seeder;

class UserEventsSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('📊 Генерируем ленту событий пользователей...');

        UserEvent::truncate();

        $users = User::where('role', 'user')->get();
 
        // Шаблоны событий: теперь строго используем Enum!
        $templates = [
            [
                'type' => UserEventType::PhotoUpdated,
                'properties' => ['photo_id' => 1]
            ],
            [
                'type' => UserEventType::StatusChanged,
                'properties' => ['text' => 'Ищу серьезные отношения']
            ],
            [
                'type' => UserEventType::VipPurchased,
                'properties' => ['days' => 30, 'price' => 500]
            ],
            [
                'type' => UserEventType::DiaryCreated,
                'properties' => ['diary_id' => 1, 'title' => 'Мой первый день на сайте']
            ],
            [
                'type' => UserEventType::ProfileCompleted,
                'properties' => []
            ],
            [
                'type' => UserEventType::PremiumPurchased,
                'properties' => ['days' => 7]
            ],
        ];

        $insertData = [];

        foreach ($users as $user) {
            $eventsCount = rand(2, 4);

            for ($i = 0; $i < $eventsCount; $i++) {
                $template = $templates[array_rand($templates)];
                $date = now()->subDays(rand(1, 60))->subHours(rand(1, 23));

                $insertData[] = [
                    'user_id' => $user->id,
                    'type' => $template['type']->value, // Получаем строку из Enum
                    'properties' => json_encode($template['properties']),
                    'created_at' => $date,
                    'updated_at' => $date,
                ];
            }
        }

        UserEvent::insert($insertData);

        $this->command->info('✅ Лента событий сгенерирована (' . count($insertData) . ' записей).');
    }
}