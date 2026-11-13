<?php

// Это "Большой Брат" проекта. Аудит-логи — это история всего, что трогал админ руками. Ключевая фича здесь — диффы 
// (разница) в полях before и after. Это позволяет в админке сделать кнопку "Откатить действие" в один клик. 
// Мы симулируем реальный рабочий день модератора: он банит скамеров, одобряет фото, раздает рефанды и меняет настройки.

// Здесь мы генерируем реальные события из жизни админа, с точной простановкой диффов before и after. 
// Обрати внимание на ip_address — я использовал внутреннюю подсеть 10.0.0.*, чтобы в админке было видно,
//  что логи оставили сотрудники из офиса, а не злоумышленники.

namespace Database\Seeders;
 
use App\Models\AdminLog;
use App\Models\Photo;
use App\Models\Report;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;

class AdminLogSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🕵️ Генерируем аудит-логи действий администрации...');

        $admin = User::where('role', 'admin')->first();
        $users = User::where('role', 'user')->get();
        $photos = Photo::all();
        $reports = Report::all();
        $transactions = Transaction::where('status', 'success')->get();

        if (!$admin || $users->isEmpty()) {
            $this->command->warn('⚠️ Нет админа или пользователей для генерации логов!');
            return;
        }

        // Очистка
        $deletedCount = AdminLog::count();
        if ($deletedCount > 0) {
            AdminLog::query()->delete();
            $this->command->info("🗑️ Удалено {$deletedCount} старых логов");
        }

        $logs = [];

        // ============================================
        // 1. БАНЫ ПОЛЬЗОВАТЕЛЕЙ (10 шт)
        // ============================================
        foreach ($users->random(min(10, $users->count())) as $user) {
            $reason = ['scam', 'spam', 'inappropriate', 'minor'][array_rand(['scam', 'spam', 'inappropriate', 'minor'])];
            $logs[] = [
                'admin_id' => $admin->id,
                'action' => 'user.ban',
                'loggable_type' => User::class,
                'loggable_id' => $user->id,
                'before' => ['status' => 'active', 'ban_reason' => null],
                'after' => ['status' => 'banned', 'ban_reason' => $reason],
                'ip_address' => '10.0.0.' . rand(1, 50), // Внутренняя сеть офиса
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
                'created_at' => now()->subDays(rand(0, 30))->subHours(rand(0, 24)),
            ];
        }

        // ============================================
        // 2. МОДЕРАЦИЯ ФОТО (15 шт)
        // ============================================
        foreach ($photos->random(min(15, $photos->count())) as $photo) {
            // Рандомно одобряем или отклоняем
            $isApprove = (bool) rand(0, 1);
            
            $logs[] = [
                'admin_id' => $admin->id,
                'action' => $isApprove ? 'photo.approve' : 'photo.reject',
                'loggable_type' => Photo::class,
                'loggable_id' => $photo->id,
                'before' => ['status' => 'pending'],
                'after' => $isApprove 
                    ? ['status' => 'approved'] 
                    : ['status' => 'rejected', 'reject_reason' => 'porn'],
                'ip_address' => '10.0.0.' . rand(1, 50),
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari/605.1.15',
                'created_at' => now()->subDays(rand(0, 20))->subHours(rand(0, 12)),
            ];
        }

        // ============================================
        // 3. РАЗБОР ЖАЛОБ (5 шт)
        // ============================================
        foreach ($reports->random(min(5, $reports->count())) as $report) {
            $resolution = ['ban', 'warn', 'no_action'][array_rand(['ban', 'warn', 'no_action'])];
            
            $logs[] = [
                'admin_id' => $admin->id,
                'action' => 'report.resolve',
                'loggable_type' => Report::class,
                'loggable_id' => $report->id,
                'before' => ['status' => 'pending'],
                'after' => ['status' => 'resolved', 'resolution' => $resolution],
                'ip_address' => '10.0.0.' . rand(1, 50),
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0',
                'created_at' => now()->subDays(rand(0, 15))->subHours(rand(0, 8)),
            ];
        }

        // ============================================
        // 4. ФИНАНСЫ: ВОЗВРАТЫ (3 шт)
        // ============================================
        foreach ($transactions->random(min(3, $transactions->count())) as $transaction) {
            $logs[] = [
                'admin_id' => $admin->id,
                'action' => 'transaction.refund',
                'loggable_type' => Transaction::class,
                'loggable_id' => $transaction->id,
                'before' => ['status' => 'success'],
                'after' => ['status' => 'refunded', 'reason' => 'По требованию клиента (Chargeback)'],
                'ip_address' => '10.0.0.100', // Фин. директор с конкретного IP
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Edge/120.0.0.0',
                'created_at' => now()->subDays(rand(0, 10)),
            ];
        }

        // ============================================
        // 5. ИЗМЕНЕНИЕ НАСТРОЕК (2 шт)
        // ============================================
        $logs[] = [
            'admin_id' => $admin->id,
            'action' => 'settings.update',
            'loggable_type' => null, // Настройки не полиморфны к конкретной записи, либо можно сделать morph к Setting
            'loggable_id' => null,
            'before' => ['key' => 'likes_per_day_free', 'value' => 20],
            'after' => ['key' => 'likes_per_day_free', 'value' => 30],
            'ip_address' => '10.0.0.1',
            'user_agent' => 'PostmanRuntime/7.36.3', // Админ через API
            'created_at' => now()->subDays(2),
        ];

         // Сортируем логи по времени (чтобы вставлялись красиво)
        usort($logs, function ($a, $b) {
            return $a['created_at']->timestamp - $b['created_at']->timestamp;
        });

        // ВАЖНО: Метод insert() не использует $casts из модели Eloquent!
        // Поэтому мы обязаны вручную перевести массивы в JSON для полей before и after.
        $logs = array_map(function ($log) {
            $log['before'] = json_encode($log['before']);
            $log['after'] = json_encode($log['after']);
            return $log;
        }, $logs);

        // Вставляем пачкой (для скорости)
        $bar = $this->command->getOutput()->createProgressBar(count($logs));
        $chunkSize = 50;
        foreach (array_chunk($logs, $chunkSize) as $chunk) {
            AdminLog::insert($chunk);
            $bar->advance(count($chunk));
        }
        
        $bar->finish();
        $this->command->newLine(2);

        // ============================================
        // СТАТИСТИКА
        // ============================================
        $stats = [
            'total' => AdminLog::count(),
            'user_bans' => AdminLog::where('action', 'user.ban')->count(),
            'photo_approves' => AdminLog::where('action', 'photo.approve')->count(),
            'photo_rejects' => AdminLog::where('action', 'photo.reject')->count(),
            'report_resolves' => AdminLog::where('action', 'report.resolve')->count(),
            'refunds' => AdminLog::where('action', 'transaction.refund')->count(),
        ];

        $this->command->info('✅ Сгенерировано аудит-логов: ' . $stats['total']);
        $this->command->info('');
        $this->command->info('📊 Хронология действий:');
        $this->command->info("   ┌───────────────────────────┬──────────┐");
        $this->command->info("   │ Действие                  │ Кол-во   │");
        $this->command->info("   ├───────────────────────────┼──────────┤");
        $this->command->info("   │ 🚫 Баны юзеров           │ {$stats['user_bans']}        │");
        $this->command->info("   │ ✅ Фото одобрено          │ {$stats['photo_approves']}        │");
        $this->command->info("   │ ❌ Фото отклонено         │ {$stats['photo_rejects']}        │");
        $this->command->info("   │ 🚩 Жалобы разобраны      │ {$stats['report_resolves']}        │");
        $this->command->info("   │ 💸 Возвраты средств       │ {$stats['refunds']}        │");
        $this->command->info("   └───────────────────────────┴──────────┘");
        
        $this->command->warn('💡 Подсказка: Используй AdminLog::record() в контроллерах, чтобы логи писались сами!');
    }
}