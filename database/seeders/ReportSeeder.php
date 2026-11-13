<?php

namespace Database\Seeders;

use App\Enums\ReportReason;
use App\Models\Photo;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Seeder;

class ReportSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::where('role', 'user')->get();
        
        if ($users->count() < 2) {
            $this->command->warn('⚠️ Нужно минимум 2 обычных пользователя для жалоб!');
            return;
        }

        $admin = User::where('role', 'admin')->first();
        $this->command->info('🚩 Создаем жалобы...');

        $reasons = ReportReason::cases();
        $descriptions = [
            'Оскорбляет других пользователей в чате',
            'Профиль выглядит фейковым',
            'Спамит ссылками на посторонние сайты',
            'Выдает себя за другого человека',
            'Рассылает неприемлемый контент',
            'Нарушает правила сообщества',
        ];

        $createdCount = 0;

        // 1. ЖАЛОБЫ НА ПОЛЬЗОВАТЕЛЕЙ (15 шт)
        $this->command->info('   📝 Создаем жалобы на пользователей...');
        for ($i = 0; $i < 15; $i++) {
            $reporter = $users->random();
            $reported = $users->where('id', '!=', $reporter->id)->random();
            
            $status = ['pending', 'resolved', 'rejected'][array_rand(['pending', 'resolved', 'rejected'])];
            $resolution = $status === 'resolved' ? ['ban', 'warn', 'shadowban'][array_rand(['ban', 'warn', 'shadowban'])] : null;

            Report::create([
                'reporter_id' => $reporter->id,
                'reported_id' => $reported->id,
                'reportable_type' => User::class,
                'reportable_id' => $reported->id,
                'reason' => $reasons[array_rand($reasons)]->value, // Используем Enum
                'description' => $descriptions[array_rand($descriptions)],
                'status' => $status,
                'resolution' => $resolution,
                'resolution_note' => $resolution ? 'Разобрано модератором' : null,
                'admin_id' => $status !== 'pending' && $admin ? $admin->id : null,
                'resolved_at' => $status !== 'pending' ? now()->subDays(rand(0, 10)) : null,
                'created_at' => now()->subDays(rand(0, 30)),
                'updated_at' => now()->subDays(rand(0, 10)),
            ]);
            $createdCount++;
        }
        $this->command->info("   ✅ Создано жалоб на пользователей: 15");

        // 2. ЖАЛОБЫ НА ФОТО (5 шт)
        $this->command->info('   📝 Создаем жалобы на фото...');
        $photos = Photo::where('status', 'approved')->get();

        if ($photos->isNotEmpty()) {
            for ($i = 0; $i < 5; $i++) {
                $reporter = $users->random();
                $photo = $photos->random();
                $reported = $photo->user;

                $status = ['pending', 'resolved', 'rejected'][array_rand(['pending', 'resolved', 'rejected'])];

                Report::create([
                    'reporter_id' => $reporter->id,
                    'reported_id' => $reported->id,
                    'reportable_type' => Photo::class,
                    'reportable_id' => $photo->id,
                    'reason' => ReportReason::Porn->value, // Жалобы на фото чаще за 18+
                    'description' => 'Неприемлемое фото: ' . $descriptions[array_rand($descriptions)],
                    'status' => $status,
                    'resolution' => $status === 'resolved' ? 'photo_deleted' : null,
                    'resolution_note' => $status === 'resolved' ? 'Фото удалено модератором' : null,
                    'admin_id' => $status !== 'pending' && $admin ? $admin->id : null,
                    'resolved_at' => $status !== 'pending' ? now()->subDays(rand(0, 5)) : null,
                    'created_at' => now()->subDays(rand(0, 20)),
                    'updated_at' => now()->subDays(rand(0, 5)),
                ]);
                $createdCount++;
            }
            $this->command->info("   ✅ Создано жалоб на фото: 5");
        } else {
            $this->command->warn("   ⚠️ Нет фото для жалоб, пропускаем...");
        }

        // 3. РАЗРЕШЕННЫЕ ЖАЛОБЫ (с админом)
        if ($admin) {
            $this->command->info('   📝 Создаем разрешенные жалобы (с админом)...');
            for ($i = 0; $i < 5; $i++) {
                $reporter = $users->random();
                $reported = $users->where('id', '!=', $reporter->id)->random();
                
                Report::create([
                    'reporter_id' => $reporter->id,
                    'reported_id' => $reported->id,
                    'reportable_type' => User::class,
                    'reportable_id' => $reported->id,
                    'reason' => $reasons[array_rand($reasons)]->value, // Используем Enum
                    'description' => $descriptions[array_rand($descriptions)],
                    'status' => 'resolved',
                    'resolution' => 'ban',
                    'resolution_note' => 'Пользователь забанен за нарушение правил',
                    'admin_id' => $admin->id,
                    'resolved_at' => now()->subDays(rand(1, 15)),
                    'created_at' => now()->subDays(rand(10, 30)),
                    'updated_at' => now()->subDays(rand(1, 10)),
                ]);
                $createdCount++;
            }
            $this->command->info("   ✅ Создано разрешенных жалоб: 5");
        } else {
            $this->command->warn("   ⚠️ Нет админа для модерации, пропускаем...");
        }

        $this->command->newLine();
        $this->command->info('✅ Всего создано жалоб: ' . $createdCount);
    }
}