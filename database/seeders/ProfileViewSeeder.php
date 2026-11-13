<?php

namespace Database\Seeders;

use App\Models\ProfileView;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProfileViewSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('👁️ Генерируем историю просмотров анкет...');

        ProfileView::truncate();

        $users = User::where('role', 'user')->get();

        if ($users->count() < 2) {
            $this->command->warn('⚠️ Нужно минимум 2 пользователя для просмотров!');
            return;
        }

        $insertData = [];
        $existingPairs = []; // Массив для защиты от дублей в памяти

        $bar = $this->command->getOutput()->createProgressBar($users->count());

        foreach ($users as $user) {
            $viewCount = rand(5, 20);
            $targets = $users->where('id', '!=', $user->id)->random(min($viewCount, $users->count() - 1));

            foreach ($targets as $target) {
                $pairKey = $user->id . '-' . $target->id;
                
                // Если пара уже есть, пропускаем
                if (!isset($existingPairs[$pairKey])) {
                    $existingPairs[$pairKey] = true;
                    
                    $insertData[] = [
                        'viewer_id' => $user->id,
                        'viewed_id' => $target->id,
                        'updated_at' => now()->subDays(rand(0, 14)),
                    ];
                }
            }
            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine(1);

        // Массовая вставка (молниеносно)
        DB::table('profile_views')->insert($insertData);

        $this->command->info('✅ Создано просмотров: ' . count($insertData));
    }
}