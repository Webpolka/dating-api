<?php

namespace Database\Seeders;

use App\Enums\UserBlockReason;
use App\Models\User; 
use App\Models\UserBlock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UserBlockSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('user_blocks')->truncate();
        $this->command->info('🚫 Генерируем черный список (блокировки)...');

        $users = User::where('role', 'user')->get();

        if ($users->count() < 20) {
            $this->command->warn('⚠️ Нужно минимум 20 пользователей! Создаем...');
            User::factory()->count(20 - $users->count())->create();
            $users = User::where('role', 'user')->get();
        }

        $reasons = UserBlockReason::cases();   
        $createdCount = 0;

        // ============================================
        // 1. СЛУЧАЙНЫЕ БЛОКИРОВКИ (50 шт)
        // ============================================
        $this->command->info('🔀 Генерируем случайные блокировки...');
        for ($i = 0; $i < 50; $i++) {
            $blocker = $users->random();
            $blocked = $users->where('id', '!=', $blocker->id)->random();

            // ФИКС: Используем firstOrCreate, чтобы избежать ошибки UniqueConstraint
            $block = UserBlock::firstOrCreate(
                ['blocker_id' => $blocker->id, 'blocked_id' => $blocked->id],
                [
                    'reason'     => $reasons[array_rand($reasons)]->value,
                    'created_at' => now()->subDays(rand(1, 30)),
                ]
            );

            if ($block->wasRecentlyCreated) {
                $createdCount++;
            }
        }

        // ============================================
        // 2. МАССОВЫЕ БЛОКИРОВКИ (Создаем 3-х проблемных юзеров)
        // ============================================
        $this->command->info('🚨 Создаем массовиков...');

        $spammers = $users->random(3);

        foreach ($spammers as $index => $spammer) {
            $blocksCount = [5, 7, 12][$index] ?? 5;
            
            // Случайные "жертвы", которые его заблокируют
            $innocentUsers = $users->where('id', '!=', $spammer->id)->random(min($blocksCount, $users->count() - 1));

            $isRecent = true;
            foreach ($innocentUsers as $innocentUser) {
                $reason = $index === 2 ? UserBlockReason::Scam : UserBlockReason::Spam;

                // ФИКС: Тоже firstOrCreate
                $block = UserBlock::firstOrCreate(
                    ['blocker_id' => $innocentUser->id, 'blocked_id' => $spammer->id],
                    [
                        'reason'     => $reason->value,
                        'created_at' => $isRecent ? now()->subDays(rand(0, 6)) : now()->subDays(rand(10, 25)),
                    ]
                );

                if ($block->wasRecentlyCreated) {
                    $createdCount++;
                }
                $isRecent = !$isRecent;
            }
        }

        $this->command->newLine(2);

        // ============================================
        // СТАТИСТИКА
        // ============================================
        $mostBlocked = UserBlock::select('blocked_id', DB::raw('count(*) as blocks_count'))
            ->groupBy('blocked_id')
            ->orderByDesc('blocks_count')
            ->first();

        $this->command->info('✅ Создано блокировок: ' . $createdCount);
        
        if ($mostBlocked) {
            $badUser = User::find($mostBlocked->blocked_id);
            $this->command->info("   🚩 Самый блокируемый: {$badUser->name} (заблокирован {$mostBlocked->blocks_count} раз)");
        }
    }
}