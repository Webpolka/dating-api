<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserAuthLog;
use Illuminate\Database\Seeder;

class UserAuthLogsSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🔐 Генерируем историю заходов...');

        UserAuthLog::truncate();

        $users = User::where('role', 'user')->get();
        $devices = [
            ['os' => 'Windows 11', 'type' => 'desktop', 'agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)...'],
            ['os' => 'macOS', 'type' => 'desktop', 'agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)...'],
            ['os' => 'Android 13', 'type' => 'mobile', 'agent' => 'Mozilla/5.0 (Linux; Android 13; Pixel 7)...'],
            ['os' => 'iOS 17', 'type' => 'mobile', 'agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)...'],
        ];
        $ips = ['192.168.1.1', '95.153.132.10', '178.66.50.22', '45.12.30.5', '185.220.101.1']; 

        $insertData = [];

        foreach ($users as $user) {
            $logsCount = rand(2, 5);

            for ($i = 0; $i < $logsCount; $i++) {
                $device = $devices[array_rand($devices)];
                $date = now()->subDays(rand(1, 30))->subHours(rand(1, 23));
                
                $insertData[] = [
                    'user_id' => $user->id,
                    'ip_address' => $ips[array_rand($ips)],
                    'user_agent' => $device['agent'],
                    'device_os' => $device['os'],
                    'device_type' => $device['type'],
                    'is_successful' => true,
                    'created_at' => $date,
                    'updated_at' => $date,
                ];
            }
        }

        // Массовая вставка (молниеносно)
        UserAuthLog::insert($insertData);

        $this->command->info('✅ История заходов сгенерирована (' . count($insertData) . ' записей).');
    }
}