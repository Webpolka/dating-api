<?php

namespace Database\Seeders;

use App\Models\Verification;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\Seeder;

class VerificationSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('✅ Генерируем заявки на верификацию (синяя галочка)...');

        $admin = User::where('role', 'admin')->first();

        if (!$admin) {
            $this->command->warn('⚠️ Нет админа!');
            return;
        }

        // Очистка старых заявок
        $deletedCount = Verification::count();
        if ($deletedCount > 0) {
            Verification::query()->delete();
            $this->command->info("🗑️ Удалено {$deletedCount} старых заявок");
        }

        $bar = $this->command->getOutput()->createProgressBar(10);
        $createdCount = 0;

        // ============================================
        // 1. ОДОБРЕННЫЕ (до 5 шт)
        // ============================================
        $unverifiedUsers = User::where('role', 'user')->where('is_verified', false)->get();
        $approvedCount = min(5, $unverifiedUsers->count());
 
        foreach ($unverifiedUsers->random($approvedCount) as $user) {
            $photo = $user->photos()->inRandomOrder()->first();

            Verification::create([
                'user_id' => $user->id,
                'photo_id' => $photo?->id,
                'status' => Verification::STATUS_APPROVED,
                'moderated_by' => $admin->id,
                'moderated_at' => now()->subDays(rand(10, 30)),
                'created_at' => now()->subDays(rand(35, 40)),
            ]);

            $user->update(['is_verified' => true]); // СИНХРОНИЗАЦИЯ
            $createdCount++;
            $bar->advance();
        }

        // ============================================
        // 2. ОТКЛОНЕННЫЕ (до 3 шт)
        // ============================================
        $unverifiedUsers = User::where('role', 'user')->where('is_verified', false)->get();
        $rejectedCount = min(3, $unverifiedUsers->count());

        if ($rejectedCount > 0) {
            // ФИКС: Используем английские слаги, которые разрешены в Enum миграции
             $rejectReasons = [Verification::REASON_BLURRY, Verification::REASON_FAKE, Verification::REASON_NO_CODE];
            
            foreach ($unverifiedUsers->random($rejectedCount) as $user) {
                $photo = $user->photos()->inRandomOrder()->first();

                Verification::create([
                    'user_id' => $user->id,
                    'photo_id' => $photo?->id,
                    'status' => Verification::STATUS_REJECTED,
                    'reject_reason' => $rejectReasons[array_rand($rejectReasons)],
                    'moderated_by' => $admin->id,
                    'moderated_at' => now()->subDays(rand(1, 7)),
                    'created_at' => now()->subDays(rand(8, 14)),
                ]);

                $createdCount++;
                $bar->advance();
            }
        }

        // ============================================
        // 3. ОЖИДАЮЩИЕ (до 2 шт) - Для очереди модерации!
        // ============================================
        $unverifiedUsers = User::where('role', 'user')->where('is_verified', false)->get();
        $pendingCount = min(2, $unverifiedUsers->count());

        if ($pendingCount > 0) {
            foreach ($unverifiedUsers->random($pendingCount) as $user) {
                $photo = $user->photos()->inRandomOrder()->first();

                Verification::create([
                    'user_id' => $user->id,
                    'photo_id' => $photo?->id,
                    'status' => Verification::STATUS_PENDING,
                    'created_at' => now()->subHours(rand(1, 12)),
                ]);

                $createdCount++;
                $bar->advance();
            }
        }

        $bar->finish();
        $this->command->newLine(2);

        // ============================================
        // СТАТИСТИКА
        // ============================================
        $stats = [
            'total' => Verification::count(),
            'pending' => Verification::where('status', 'pending')->count(),
            'verified_users' => User::where('is_verified', true)->count(),
        ];

        $this->command->info('✅ Создано заявок на верификацию: ' . $stats['total']);
        $this->command->info("   🔵 Верифицированных юзеров: {$stats['verified_users']}");
        $this->command->warn("   ⏳ В очереди на модерацию: {$stats['pending']}");
    }
}