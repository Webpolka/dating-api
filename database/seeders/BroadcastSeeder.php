<?php

namespace Database\Seeders;

use App\Models\Broadcast;
use App\Models\User;
use Illuminate\Database\Seeder;

class BroadcastSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::where('role', 'user')->get();
        $admin = User::where('role', 'admin')->first();

        if ($users->isEmpty() || !$admin) {
            $this->command->warn('⚠️ Нет пользователей или админа для создания рассылок!');
            return;
        }

        // В нашей БД типы: in_app, push, email (system убрали)
        $types = ['in_app', 'email', 'push'];
        $statuses = ['draft', 'sent', 'scheduled'];

        $templates = [
            [
                'title' => 'Добро пожаловать на LovePlanet!',
                'message' => 'Рады приветствовать вас на нашем сайте знакомств. Заполните свой профиль, загрузите фото и начните общение!',
                'type' => Broadcast::TYPE_IN_APP,
            ],
            [
                'title' => 'Ваш профиль прошел модерацию',
                'message' => 'Поздравляем! Ваша анкета проверена и опубликована. Теперь другие пользователи могут вас найти.',
                'type' => Broadcast::TYPE_IN_APP,
            ],
            [
                'title' => 'У вас новое сообщение 💬',
                'message' => 'Пользователь Иван отправил вам сообщение. Перейдите в чат, чтобы ответить.',
                'type' => Broadcast::TYPE_PUSH,
            ],
            [
                'title' => '🔥 У вас новый лайк!',
                'message' => 'Кому-то понравился ваш профиль. Посмотрите, кто это!',
                'type' => Broadcast::TYPE_PUSH,
            ],
            [
                'title' => 'Подписка на премиум активирована',
                'message' => 'Спасибо за покупку! Все возможности сайта теперь доступны. Удачи в поиске!',
                'type' => Broadcast::TYPE_EMAIL,
            ],
            [
                'title' => 'Акция: скидка на премиум',
                'message' => 'Только до конца недели — 30% на все подписки. Успейте! 🎉',
                'type' => Broadcast::TYPE_EMAIL,
            ],
            [
                'title' => 'Совет дня: как привлечь внимание',
                'message' => 'Вставьте интересный факт о себе в описание — это отличный способ начать разговор!',
                'type' => Broadcast::TYPE_IN_APP,
            ],
        ];

        // Очищаем старые рассылки
        $deletedCount = Broadcast::count();
        if ($deletedCount > 0) {
            Broadcast::query()->delete();
            $this->command->info("🗑️ Удалено {$deletedCount} старых рассылок");
        }

        $this->command->info('📨 Создаем рассылки (кампании)...');

        $bar = $this->command->getOutput()->createProgressBar(count($templates) * 2);
        $createdCount = 0;

        // ============================================
        // 1. ТАРГЕТИРОВАННЫЕ РАССЫЛКИ (на конкретного юзера)
        // ============================================
        foreach ($templates as $template) {
            $count = rand(1, 3);
            
            for ($i = 0; $i < $count; $i++) {
                $status = $statuses[array_rand($statuses)];
                $user = $users->random();
                
                $data = [
                    'admin_id' => $admin->id, // Кто создал (админ)
                    'type' => $template['type'],
                    'title' => $template['title'],
                    'message' => $template['message'],
                    'status' => $status,
                    'target_audience' => ['user_id' => $user->id], // Таргет на юзера
                    'scheduled_at' => $status === 'scheduled' ? now()->addDays(rand(1, 5)) : null,
                    'data' => ['action_url' => url('/')],
                    'created_at' => now()->subDays(rand(0, 30)),
                ];

                // Если отправлено — симулируем статистику
                if ($status === 'sent') {
                    $data['sent_at'] = now()->subDays(rand(0, 10));
                    $data['started_at'] = $data['sent_at'];
                    $data['total_recipients'] = 1;
                    $data['sent_count'] = 1;
                    $data['failed_count'] = 0;
                }

                Broadcast::create($data);
                $createdCount++;
                $bar->advance();
            }
        }

        // ============================================
        // 2. МАССОВЫЕ РАССЫЛКИ (на всех или сегмент)
        // ============================================
        $this->command->newLine();
        $this->command->info('   📢 Создаем массовые рассылки...');

        $massTemplates = [
            [
                'title' => '🎉 Обновление сайта',
                'message' => 'Мы добавили новые функции! Теперь вы можете искать по интересам и фильтровать по городам.',
                'audience' => [], // Все
            ],
            [
                'title' => '💝 С Днем Святого Валентина!',
                'message' => 'Желаем вам любви и счастья! Специальный промокод LOVE2025 на скидку 20%.',
                'audience' => [], // Все
            ],
            [
                'title' => '🌟 Новый дизайн сайта',
                'message' => 'Мы обновили дизайн LovePlanet. Наслаждайтесь новым интерфейсом и удобной навигацией!',
                'audience' => ['gender' => 'male', 'is_premium' => "true"], // Только мужчины без VIP
            ],
        ];

        foreach ($massTemplates as $template) {
            $status = $statuses[array_rand($statuses)];
            
            $data = [
                'admin_id' => $admin->id,
                'type' => Broadcast::TYPE_IN_APP,
                'title' => $template['title'],
                'message' => $template['message'],
                'status' => $status,
                'target_audience' => $template['audience'], // Сегмент аудитории
                'scheduled_at' => $status === 'scheduled' ? now()->addDays(rand(1, 5)) : null,
                'data' => ['action_url' => url('/')],
                'created_at' => now()->subDays(rand(0, 20)),
            ];

            if ($status === 'sent') {
                $data['sent_at'] = now()->subDays(rand(0, 7));
                $data['started_at'] = $data['sent_at'];
                // Симулируем статистику массовой рассылки
                $data['total_recipients'] = rand(100, 5000);
                $data['sent_count'] = $data['total_recipients'] - rand(0, 50);
                $data['failed_count'] = $data['total_recipients'] - $data['sent_count'];
            }

            Broadcast::create($data);
            $createdCount++;
            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine(2);

        // ============================================
        // СТАТИСТИКА
        // ============================================
        $stats = [
            'total' => Broadcast::count(),
            'draft' => Broadcast::where('status', 'draft')->count(),
            'sent' => Broadcast::where('status', 'sent')->count(),
            'scheduled' => Broadcast::where('status', 'scheduled')->count(),
            'in_app' => Broadcast::where('type', 'in_app')->count(),
            'email' => Broadcast::where('type', 'email')->count(),
            'push' => Broadcast::where('type', 'push')->count(),
        ];

        $this->command->info('✅ Всего создано рассылок: ' . $stats['total']);
        $this->command->info('');
        $this->command->info('📊 Статистика:');
        $this->command->info("   ┌────────────────────────┬──────────┐");
        $this->command->info("   │ Тип                    │ Кол-во   │");
        $this->command->info("   ├────────────────────────┼──────────┤");
        $this->command->info("   │ Всего                  │ {$stats['total']}        │");
        $this->command->info("   │ Черновики              │ {$stats['draft']}        │");
        $this->command->info("   │ Отправленные           │ {$stats['sent']}        │");
        $this->command->info("   │ Запланированные        │ {$stats['scheduled']}        │");
        $this->command->info("   ├────────────────────────┼──────────┤");
        $this->command->info("   │ In-App (Колокольчик)   │ {$stats['in_app']}        │");
        $this->command->info("   │ Email                  │ {$stats['email']}        │");
        $this->command->info("   │ Push                   │ {$stats['push']}        │");
        $this->command->info("   └────────────────────────┴──────────┘");
    }
}