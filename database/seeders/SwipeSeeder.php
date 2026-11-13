<?php

namespace Database\Seeders;
 
use App\Enums\V1\SwipeType;
use App\Models\User;
use App\Models\Swipe;
use App\Models\UserMatch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SwipeSeeder extends Seeder
{
    public function run(): void
    {
        cache()->flush();
        // Берем только обычных пользователей с заполненными профилями
        $users = User::where('role', 'user')
            ->where('has_completed_onboarding', true)
            ->pluck('id')
            ->toArray();
            
        $userCount = count($users);

        if ($userCount < 2) {
            $this->command->warn('⚠️ Нужно минимум 2 обычных пользователя для создания свайпов.');
            return;
        }

        $this->command->info("👥 Найдено {$userCount} пользователей для свайпов...");

        // Очищаем старые свайпы и матчи
        $oldSwipes = Swipe::count();
        $oldMatches = UserMatch::count();
        
        if ($oldSwipes > 0 || $oldMatches > 0) {
            $this->command->info("🗑️ Удаляем старые данные: {$oldSwipes} свайпов, {$oldMatches} матчей");
            Swipe::query()->delete();
            UserMatch::query()->delete();
        }

        $totalSwipes = 0;
        $totalMatches = 0;

        // Массив в памяти для быстрого поиска взаимных лайков (без запросов к БД)
        $positiveSwipes = [];

        $this->command->info('🔄 Создаем свайпы и матчи...');
        $bar = $this->command->getOutput()->createProgressBar($userCount);

        DB::transaction(function () use ($users, &$totalSwipes, &$totalMatches, &$positiveSwipes, $bar) {
            foreach ($users as $userId) {
                // Доступные цели (исключаем себя)
                $available = array_diff($users, [$userId]);
                shuffle($available);
                
                // Каждый юзер свайпнет от 3 до 8 человек
                $count = rand(3, min(8, count($available)));
                $targets = array_slice($available, 0, $count);

                foreach ($targets as $targetId) {
                    // Рандомный тип свайпа (60% like, 30% dislike, 10% superlike)
                    $rand = rand(1, 100);

                   if ($rand <= 60) {
                        $type = SwipeType::Like->value;
                    } elseif ($rand <= 90) {
                        $type = SwipeType::Dislike->value;
                    } else {
                        $type = SwipeType::Superlike->value;
                    }

                    // Создаем свайп (rewinded_at по умолчанию null)
                    Swipe::create([
                        'user_id' => $userId,
                        'target_user_id' => $targetId,
                        'type' => $type,
                        'created_at' => now()->subDays(rand(0, 30)),
                    ]);
                    $totalSwipes++;

                    // ПРОВЕРКА НА МАТЧ (только для like и superlike)
                    if (in_array($type, [SwipeType::Like->value, SwipeType::Superlike->value])) {
                        // Записываем положительный свайп в память
                        $positiveSwipes[$userId][] = $targetId;

                        // Проверяем, есть ли встречный лайк от target к user (в памяти, без БД!)
                        if (isset($positiveSwipes[$targetId]) && in_array($userId, $positiveSwipes[$targetId])) {
                            
                            // Используем наш крутой хелпер из модели UserMatch!
                            // Он сам найдет или создаст запись, соблюдая правило (user1 < user2).
                            $match = UserMatch::createMatch($userId, $targetId);
                            
                            // Если мэтч только что создался (а не просто найден старый)
                            if ($match->wasRecentlyCreated) {
                                $totalMatches++;
                            }
                        }
                    }
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->command->newLine(2);

        // ============================================
        // СТАТИСТИКА
        // ============================================
        $stats = [
            'total_swipes' => Swipe::count(),
            'total_matches' => UserMatch::count(),
            'likes' => Swipe::where('type', SwipeType::Like->value)->count(),
            'dislikes' => Swipe::where('type', SwipeType::Dislike->value)->count(),
            'superlikes' => Swipe::where('type', SwipeType::Superlike->value)->count(),
            'users_with_swipes' => Swipe::distinct('user_id')->count(),
        ];

        $this->command->info('✅ Создано свайпов: ' . $stats['total_swipes']);
        $this->command->info('✅ Создано матчей: ' . $stats['total_matches']);
        $this->command->info('');
        $this->command->info('📊 Статистика:');
        $this->command->info("   ┌─────────────────────┬──────────┐");
        $this->command->info("   │ Тип                 │ Кол-во   │");
        $this->command->info("   ├─────────────────────┼──────────┤");
        $this->command->info("   │ Всего свайпов       │ {$stats['total_swipes']}        │");
        $this->command->info("   │ Лайки               │ {$stats['likes']}        │");
        $this->command->info("   │ Дизлайки            │ {$stats['dislikes']}        │");
        $this->command->info("   │ Суперлайки          │ {$stats['superlikes']}        │");
        $this->command->info("   ├─────────────────────┼──────────┤");
        $this->command->info("   │ Всего матчей        │ {$stats['total_matches']}        │");
        $this->command->info("   │ Пользователей       │ {$stats['users_with_swipes']}        │");
        $this->command->info("   └─────────────────────┴──────────┘");

        // Выводим примеры матчей
        if ($stats['total_matches'] > 0) {
            $exampleMatches = UserMatch::with(['user1', 'user2'])->limit(3)->get();

            if ($exampleMatches->isNotEmpty()) {
                $this->command->info('');
                $this->command->info('💑 Примеры матчей:');
                foreach ($exampleMatches as $match) {
                    $this->command->info("   - {$match->user1->name} ↔ {$match->user2->name}");
                }
            }
        }
    }
}