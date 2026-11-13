<?php

namespace Database\Seeders;

use App\Enums\V1\FraudAlertSeverity;
use App\Enums\V1\FraudAlertStatus;
use App\Enums\V1\FraudTriggerType;
use App\Models\FraudAlert;
use App\Models\User;
use Illuminate\Database\Seeder;

class FraudAlertSeeder extends Seeder
{
    public function run(): void
    {
        FraudAlert::query()->delete(); // Безопасная очистка через Eloquent

        $admin = User::where('role', 'admin')->first();

        $triggerTemplates = [
            [
                'trigger_type' => FraudTriggerType::LinksInChat,
                'severity' => FraudAlertSeverity::High,
                'meta' => fn() => [
                    'ip' => '194.' . rand(0, 255) . '.' . rand(0, 255) . '.' . rand(1, 254),
                    'message_text' => 'Пиши мне в тг @scammer_' . rand(100, 999),
                    'matched_rule' => 'tg_link_regex',
                ]
            ],
            [
                'trigger_type' => FraudTriggerType::MassMessaging,
                'severity' => FraudAlertSeverity::Medium,
                'meta' => fn() => [
                    'messages_sent_5min' => rand(25, 80),
                    'identical_message' => 'Привет! Давай познакомимся, я ищу щедрого мужчину...',
                    'ip' => '188.' . rand(0, 255) . '.' . rand(0, 255) . '.' . rand(1, 254),
                ]
            ],
            [
                'trigger_type' => FraudTriggerType::Prostitute,
                'severity' => FraudAlertSeverity::High,
                'meta' => fn() => [
                    'field' => 'about_me',
                    'matched_words' => ['массаж', 'выезд', 'индивидуалка'],
                    'profile_url' => '/users/' . rand(1, 100),
                ]
            ],
            [
                'trigger_type' => FraudTriggerType::SameDevice,
                'severity' => FraudAlertSeverity::Medium,
                'meta' => fn() => [
                    'device_id' => 'DEV-' . strtoupper(\Str::random(12)),
                    'accounts_count' => rand(3, 10),
                    'ip' => '45.' . rand(0, 255) . '.' . rand(0, 255) . '.' . rand(1, 254),
                ]
            ],
            [
                'trigger_type' => FraudTriggerType::ScamPhrase,
                'severity' => FraudAlertSeverity::Low,
                'meta' => fn() => [
                    'message_text' => 'Переведи мне 500 рублей на карту, я скину фотки',
                    'matched_rule' => 'money_request_regex',
                ]
            ],
        ];

        $users = User::limit(20)->get();

        $this->command->info('🚨 Генерируем 50 алертов антискама...');
        $bar = $this->command->getOutput()->createProgressBar(50);

        for ($i = 0; $i < 50; $i++) {
            $template = $triggerTemplates[array_rand($triggerTemplates)];
            $createdAt = now()->subDays(rand(0, 30))->subHours(rand(0, 23));
            
            $statusRoll = rand(1, 100);
            $status = $statusRoll <= 70 ? FraudAlertStatus::Open : ($statusRoll <= 90 ? FraudAlertStatus::Resolved : FraudAlertStatus::FalsePositive);
            
            $isAdminResolved = $status !== FraudAlertStatus::Open;
            $isUserDeleted = rand(1, 100) <= 15;

            FraudAlert::create([
                'user_id' => $isUserDeleted ? null : ($users->isNotEmpty() ? $users->random()->id : null),
                'trigger_type' => $template['trigger_type'], // Объект Enum
                'severity' => $template['severity'],          // Объект Enum
                'meta' => $template['meta'](),
                'status' => $status,                         // Объект Enum
                'admin_id' => $isAdminResolved ? ($admin->id ?? null) : null,
                'resolved_at' => $isAdminResolved ? $createdAt->copy()->addHours(rand(1, 48)) : null,
                'created_at' => $createdAt,
                'updated_at' => $isAdminResolved ? $createdAt->copy()->addHours(rand(1, 48)) : $createdAt,
            ]);
            $bar->advance();
        }
        $bar->finish();
        $this->command->newLine(2);
        $this->command->info('✅ Алерты антискама сгенерированы!');
    }
}