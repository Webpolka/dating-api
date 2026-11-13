<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Отключить VIP и Premium подписки пользователя.
 *
 * Запуск:
 *   php artisan user:subscriptions:cancel 42              # по ID — отменить ВСЕ (vip + premium)
 *   php artisan user:subscriptions:cancel ivan-ivanov    # по slug — отменить ВСЕ
 *   php artisan user:subscriptions:cancel 42 --type=vip     # только VIP
 *   php artisan user:subscriptions:cancel 42 --type=premium # только Premium
 *
 * Что делает:
 *   - Находит юзера по ID (если число) или по slug (если строка)
 *   - Ищет все АКТИВНЫЕ подписки указанного типа (по умолчанию все = vip + premium)
 *   - У каждой ставит status='canceled', canceled_at=now(), ends_at=now()
 *     → подписка мгновенно заканчивается, юзер больше не VIP/Premium
 *
 * Использование:
 *   - Когда юзер просит вернуть деньги через поддержку
 *   - Когда админ руками хочет убрать VIP/Premium у неадекватного юзера
 *   - При тестировании billing-flow
 *
 * ВАЖНО: команда НЕ удаляет запись из БД — только меняет статус.
 * Это сохраняет историю (когда был отменён, кем, и т.д.).
 *
 * Если структура БД другая (например подписки хранятся как поля в user_profiles
 * типа has_vip_until / has_premium_until) — смотрите альтернативный однострочник
 * ниже в комментариях.
 */
class CancelUserSubscriptions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * {user : User ID (число) или slug (строка)}
     * {--type= : Какой тип отменять: vip, premium, или all (по умолчанию all)}
     */
    protected $signature = 'user:subscriptions:cancel
                            {user : User ID (число) или slug (строка)}
                            {--type=all : Тип подписки: vip, premium, или all}';

    /**
     * The console command description.
     */
    protected $description = 'Отменить VIP/Premium подписки пользователя (по ID или slug)';

    public function handle(): int
    {
        $userIdentifier = $this->argument('user');
        $type = $this->option('type');

        // Валидация --type
        if (!in_array($type, ['all', 'vip', 'premium'])) {
            $this->error("Неверный --type: '{$type}'. Допустимо: vip, premium, all.");
            return 1;
        }

        // Найти юзера по ID (если число) или по slug (если строка)
        $user = is_numeric($userIdentifier)
            ? User::find((int) $userIdentifier)
            : User::where('slug', $userIdentifier)->first();

        if (!$user) {
            $this->error("Юзер не найден: {$userIdentifier}");
            return 1;
        }

        $this->info("Юзер: {$user->name} (id={$user->id}, slug={$user->slug})");

        // Найти активные подписки
        // Активная = status='active' AND ends_at > now() (ещё не истекла)
        $query = UserSubscription::where('user_id', $user->id)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('ends_at')
                  ->orWhere('ends_at', '>', now());
            });

        if ($type !== 'all') {
            $query->where('type', $type);
        }

        $subscriptions = $query->get();

        if ($subscriptions->isEmpty()) {
            $this->warn("Активных подписок" . ($type !== 'all' ? " типа '{$type}'" : "") . " не найдено.");
            return 0;
        }

        // Отменяем каждую
        $canceled = 0;
        foreach ($subscriptions as $subscription) {
            $subscription->update([
                'status'      => 'canceled',
                'canceled_at' => now(),
                'ends_at'     => now(), // сразу заканчиваем
            ]);
            $this->line("  ✓ Отменена: type={$subscription->type}, id={$subscription->id}");
            $canceled++;
        }

        $this->info("Готово! Отменено подписок: {$canceled}");

        // Очищаем кэш юзера (если есть accessor-ы типа has_active_vip, has_active_premium
        // которые кэшируются). На всякий случай — refresh модели.
        $user->refresh();

        return 0;
    }
}