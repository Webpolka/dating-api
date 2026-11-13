<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================
 * UserInterestsSeeder — привязывает интересы к юзерам
 * ============================================================
 *
 * Создаёт:
 *   - Каждому юзеру (role='user', status='active') — 3-6 случайных интересов
 *
 * Использует DB::table() напрямую — не зависит от модели User.
 * Безопасен для повторного запуска — очищает связи перед заполнением.
 *
 * Запуск:
 *   php artisan db:seed --class=UserInterestsSeeder
 */
class UserInterestsSeeder extends Seeder
{
    public function run(): void
    {
        // Все интересы
        $interestIds = DB::table('interests')->pluck('id')->toArray();

        if (empty($interestIds)) {
            $this->command->warn('❌ Нет интересов! Сначала запусти: php artisan db:seed --class=InterestSeeder');
            return;
        }

        // Все обычные юзеры (не админы/модераторы)
        $users = DB::table('users')
            ->where('role', 'user')
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->get(['id']);

        if ($users->isEmpty()) {
            $this->command->warn('❌ Нет активных юзеров! Создай юзеров сначала.');
            return;
        }

        // Очищаем старые связи (для повторного запуска)
        DB::table('user_interests')->delete();
        $now = now();

        $count = 0;
        $rows = [];

        foreach ($users as $user) {
            // Каждому юзеру — 3-6 случайных интересов
            $randomCount = rand(3, 6);
            // shuffle + slice для уникальных ID
            shuffle($interestIds);
            $randomInterests = array_slice($interestIds, 0, $randomCount);

            foreach ($randomInterests as $interestId) {
                $rows[] = [
                    'user_id'     => $user->id,
                    'interest_id' => $interestId,
                    'created_at'  => $now,
                ];
                $count++;
            }

            // Батч-вставка каждые 500 строк (для производительности на больших базах)
            if (count($rows) >= 500) {
                DB::table('user_interests')->insert($rows);
                $rows = [];
            }
        }

        // Вставляем остатки
        if (!empty($rows)) {
            DB::table('user_interests')->insert($rows);
        }

        $this->command->info("✅ Интересы привязаны к {$users->count()} юзерам.");
        $this->command->info("   Всего связей: {$count}");
        $this->command->info('   Каждому назначено 3-6 случайных интересов.');
    }
}