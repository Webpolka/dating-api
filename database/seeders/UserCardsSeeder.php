<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserCard;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserCardsSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('💳 Привязываем фейковые карты пользователям...');

        UserCard::truncate();

        $users = User::whereIn('role', ['user', 'admin'])->get();
        
        // ИСПОЛЬЗУЕМ КОНСТАНТЫ ИЗ МОДЕЛИ (Никакого хардкода!)
        $gateways = [UserCard::GATEWAY_YOOKASSA, UserCard::GATEWAY_STRIPE, 'cloudpayments'];
        $cardTypes = ['visa', 'mastercard', 'mir'];

        $insertData = [];
        $createdCount = 0;

        foreach ($users as $user) {
            $cardsToCreate = rand(1, 2);

            for ($i = 0; $i < $cardsToCreate; $i++) {
                $insertData[] = [
                    'user_id' => $user->id,
                    'gateway' => $gateways[array_rand($gateways)],
                    'payment_method_id' => 'pm_' . Str::uuid()->toString(),
                    'card_type' => $cardTypes[array_rand($cardTypes)],
                    'last4' => str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT),
                    'expiry_month' => str_pad(rand(1, 12), 2, '0', STR_PAD_LEFT),
                    'expiry_year' => (string)((int) date('Y') + rand(1, 4)),
                    'is_active' => true,
                    'is_default' => ($i === 0),
                    'created_at' => now()->subDays(rand(1, 30)),
                    'updated_at' => now()->subDays(rand(0, 10)),
                ];
                $createdCount++;
            }
        }

        // Массовая вставка (молниеносно)
        DB::table('user_cards')->insert($insertData);

        $this->command->info("✅ Привязано карт: {$createdCount} (для {$users->count()} пользователей).");
    }
}