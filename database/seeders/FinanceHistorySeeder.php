<?php

namespace Database\Seeders;

use App\Models\Transaction;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\SubscriptionPlan;
use App\Models\UserBoost;
use App\Models\UserBalance;
use App\Models\UserGift;
use App\Models\Gift;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class FinanceHistorySeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('💰 Генерируем финансовую историю (массовая вставка)...');

        // 1. Очистка старых данных
        UserGift::query()->delete();
        UserBoost::query()->delete();
        UserSubscription::query()->delete();
        Transaction::query()->delete();

        User::query()->update(['premium_expires_at' => null, 'vip_expires_at' => null]);
        UserBalance::query()->update(['credits' => 0, 'superlikes_remaining' => 1]);

        $users = User::where('role', 'user')->get();
        $balances = UserBalance::whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');
        $plans = SubscriptionPlan::all();
        $gifts = Gift::all();

        if ($users->isEmpty() || $plans->isEmpty()) {
            $this->command->warn('⚠️ Нет пользователей или тарифов!');
            return;
        }

        $bar = $this->command->getOutput()->createProgressBar($users->count());

        $transactionsData = [];
        $subsData = []; // [uuid => subData]
        $boostsData = [];
        $giftsData = [];
        $usersData = []; // Для upsert
        $now = now();

        foreach ($users as $user) {
            $balance = $balances[$user->id] ?? null;
            $currentPremium = null;
            $currentVip = null;

            if (rand(1, 10) <= 8) {
                $purchasesCount = rand(1, 4);

                for ($i = 0; $i < $purchasesCount; $i++) {
                    $roll = rand(1, 100);

                    if ($roll <= 60) {
                        // --- Покупка подписки ---
                        $plan = $plans->random();
                        $uuid = Str::uuid()->toString();
                        $transDate = $now->copy()->subDays(rand(1, 120));
                        $isSuccess = rand(1, 10) <= 8;
                        $transStatus = $isSuccess ? Transaction::STATUS_SUCCESS : (rand(0, 1) ? Transaction::STATUS_FAILED : Transaction::STATUS_PENDING);

                        $transactionsData[] = [
                            'user_id' => $user->id,
                            'amount' => $plan->price,
                            'currency' => 'RUB',
                            'type' => Transaction::TYPE_SUBSCRIPTION,
                            'status' => $transStatus,
                            'provider' => 'yookassa',
                            'provider_transaction_id' => $uuid,
                            'credits_amount' => null,
                            'meta' => json_encode(['plan_id' => $plan->id, 'plan_name' => $plan->name, 'tier' => $plan->tier, 'fail_reason' => $transStatus === 'failed' ? 'Недостаточно средств' : null]),
                            'created_at' => $transDate,
                            'updated_at' => $transDate,
                        ];

                        if ($isSuccess) {
                            $startsAt = $transDate->copy()->addMinutes(5);
                            $endsAt = $startsAt->copy()->addDays($plan->duration_days);
                            $subStatus = $endsAt->isPast() ? 'expired' : (rand(1, 10) <= 8 ? 'active' : 'canceled');

                            if ($subStatus === 'active') {
                                if ($plan->tier === 'premium') {
                                    $startFrom = $currentPremium && $currentPremium->isFuture() ? $currentPremium : $now;
                                    $currentPremium = $startFrom->copy()->addDays($plan->duration_days);
                                } elseif ($plan->tier === 'vip') {
                                    $startFrom = $currentVip && $currentVip->isFuture() ? $currentVip : $now;
                                    $currentVip = $startFrom->copy()->addDays($plan->duration_days);
                                }
                            }

                            $subsData[$uuid] = [
                                'user_id' => $user->id,
                                'plan_id' => $plan->id,
                                'transaction_id' => null, // Заполним после insert
                                'tier' => $plan->tier,
                                'starts_at' => $startsAt,
                                'ends_at' => $endsAt,
                                'is_auto_renew' => ($subStatus === 'active') ? (bool) rand(0, 1) : false,
                                'provider_subscription_id' => ($subStatus === 'active' && rand(0, 1)) ? 'sub_' . Str::random(24) : null,
                                'status' => $subStatus,
                                'canceled_at' => ($subStatus === 'canceled') ? $now->copy()->subDays(rand(1, 5)) : null,
                                'created_at' => $startsAt,
                                'updated_at' => $startsAt,
                            ];
                        }
                    } else {
                        // --- Покупка кредитов ---
                        $creditPacks = [
                            ['credits' => 80, 'price' => 80.00],
                            ['credits' => 300, 'price' => 250.00],
                            ['credits' => 650, 'price' => 500.00],
                            ['credits' => 1250, 'price' => 900.00],
                        ];
                        $creditPack = $creditPacks[array_rand($creditPacks)];
                        $uuid = Str::uuid()->toString();
                        $creditDate = $now->copy()->subDays(rand(1, 60));

                        $transactionsData[] = [
                            'user_id' => $user->id,
                            'amount' => $creditPack['price'],
                            'currency' => 'RUB',
                            'type' => Transaction::TYPE_CREDITS,
                            'status' => Transaction::STATUS_SUCCESS,
                            'provider' => 'yookassa',
                            'provider_transaction_id' => $uuid,
                            'credits_amount' => $creditPack['credits'],
                            'meta' => json_encode(['credits_purchased' => $creditPack['credits']]),
                            'created_at' => $creditDate,
                            'updated_at' => $creditDate,
                        ];

                        if ($balance) {
                            $balance->credits += $creditPack['credits'];
                        }
                    }
                }
            }

            $usersData[] = [
                'id' => $user->id,
                'premium_expires_at' => $currentPremium,
                'vip_expires_at' => $currentVip,
            ];
            $bar->advance();
        }

        // 2. ВСТАВЛЯЕМ ТРАНЗАКЦИИ ПАЧКОЙ
        Transaction::insert($transactionsData);

        // 3. ПОЛУЧАЕМ ID ТРАНЗАКЦИЙ ПО UUID И ФОРМИРУЕМ ПОДПИСКИ
        $txIds = Transaction::whereIn('provider_transaction_id', array_keys($subsData))->pluck('id', 'provider_transaction_id');
        
        $finalSubsData = [];
        foreach ($subsData as $uuid => $sub) {
            $sub['transaction_id'] = $txIds[$uuid] ?? null;
            $finalSubsData[] = $sub;
        }
        UserSubscription::insert($finalSubsData);

        // 4. ТРАТА КРЕДИТОВ НА БУСТЫ И ПОДАРКИ
        foreach ($users as $user) {
            $balance = $balances[$user->id] ?? null;
            if ($balance && $balance->credits > 0) {
                // Бусты
                $boostAttempts = rand(0, 3);
                for ($b = 0; $b < $boostAttempts; $b++) {
                    if ($balance->credits >= 80) {
                        $balance->credits -= 80;
                        $boostDate = $now->copy()->subDays(rand(0, 15));
                        $boostsData[] = [
                            'user_id' => $user->id, 'transaction_id' => null, 'type' => 'profile_boost',
                            'starts_at' => $boostDate, 'ends_at' => $boostDate->copy()->addMinutes(30),
                            'status' => 'expired', 'created_at' => $boostDate, 'updated_at' => $boostDate,
                        ];
                    }
                }

                // Подарки
                if ($gifts->isNotEmpty()) {
                    $giftAttempts = rand(1, 5); // Увеличил шанс для теста
                    for ($g = 0; $g < $giftAttempts; $g++) {
                        $affordableGifts = $gifts->filter(fn($gift) => $gift->price <= $balance->credits);
                        if ($affordableGifts->isEmpty()) break;

                        $gift = $affordableGifts->random();
                        $balance->credits -= $gift->price;

                        $receiver = $users->where('id', '!=', $user->id)->random();
                        $giftDate = $now->copy()->subDays(rand(0, 15));
                        $isRead = (bool) rand(0, 1);

                        $giftsData[] = [
                            'sender_id' => $user->id, 'receiver_id' => $receiver->id, 'gift_id' => $gift->id,
                            'snapshot_name' => $gift->name, 'snapshot_image_url' => $gift->image_url, 'snapshot_price' => $gift->price,
                            'message' => 'Подарок от тестового сида!', 'is_private' => (bool) rand(0, 1), 'is_read' => $isRead,
                            'read_at' => $isRead ? $giftDate->copy()->addHours(rand(1, 24)) : null,
                            'created_at' => $giftDate, 'updated_at' => $giftDate,
                        ];
                    }
                }
            }
        }

        // 5. ВСТАВЛЯЕМ БУСТЫ И ПОДАРКИ ПАЧКОЙ
        if (!empty($boostsData)) UserBoost::insert($boostsData);
        if (!empty($giftsData)) UserGift::insert($giftsData);

        // 6. ОБНОВЛЯЕМ БАЛАНСЫ И ДАТЫ ПОДПИСОК (Массово через 2 запроса)        
        // 6.1 Балансы: upsert работает, если передать все NOT NULL поля
        $balancesArray = $balances->map(fn($b) => [
            'user_id' => $b->user_id,
            'credits' => $b->credits,
            'superlikes_remaining' => $b->superlikes_remaining,
            'superlikes_reset_at' => $b->superlikes_reset_at,
            'created_at' => $b->created_at,
            'updated_at' => now(),
        ])->values()->toArray();
        
        UserBalance::upsert($balancesArray, ['user_id'], ['credits', 'superlikes_remaining', 'superlikes_reset_at', 'updated_at']);

        // 6.2 Юзеры: Обновляем даты через SQL CASE (1 запрос вместо 500)
        $ids = array_column($usersData, 'id');
        $premiumCases = [];
        $vipCases = [];

        foreach ($usersData as $data) {
            $id = $data['id'];
            $premiumVal = $data['premium_expires_at'] ? "'{$data['premium_expires_at']->toDateTimeString()}'" : 'NULL';
            $vipVal = $data['vip_expires_at'] ? "'{$data['vip_expires_at']->toDateTimeString()}'" : 'NULL';
            
            $premiumCases[] = "WHEN {$id} THEN {$premiumVal}";
            $vipCases[] = "WHEN {$id} THEN {$vipVal}";
        }

        $premiumSql = implode(' ', $premiumCases);
        $vipSql = implode(' ', $vipCases);

        DB::table('users')->whereIn('id', $ids)->update([
            'premium_expires_at' => DB::raw("CASE id {$premiumSql} END::timestamp"),
            'vip_expires_at' => DB::raw("CASE id {$vipSql} END::timestamp"),
        ]);
        
        foreach ($usersData as $data) {
            User::where('id', $data['id'])->update([
                'premium_expires_at' => $data['premium_expires_at'],
                'vip_expires_at' => $data['vip_expires_at'],
            ]);
        }

        $bar->finish();
        $this->command->newLine(2);

        // СТАТИСТИКА
        $stats = [
            'transactions' => count($transactionsData),
            'subscriptions' => count($finalSubsData),
            'boosts' => count($boostsData),
            'gifts' => count($giftsData),
            'revenue' => Transaction::where('status', 'success')->sum('amount'),
            'premium_users' => User::where('premium_expires_at', '>', $now)->count(),
            'vip_users' => User::where('vip_expires_at', '>', $now)->count(),
            'total_credits' => UserBalance::sum('credits'),
        ];

        $this->command->info('✅ Финансовая история сгенерирована!');
        $this->command->info('');
        $this->command->info('📊 Статистика:');
        $this->command->info("   • Создано транзакций: {$stats['transactions']}");
        $this->command->info("   • Создано подписок: {$stats['subscriptions']}");
        $this->command->info("   • Активировано бустов: {$stats['boosts']}");
        $this->command->info("   • Отправлено подарков: {$stats['gifts']}");
        $this->command->info("   • Выручка (Success): {$stats['revenue']} ₽");
        $this->command->info("   • Активных Premium: {$stats['premium_users']}");
        $this->command->info("   • Активных VIP: {$stats['vip_users']}");
        $this->command->info("   • Кредитов на балансах: {$stats['total_credits']}");
    }
}