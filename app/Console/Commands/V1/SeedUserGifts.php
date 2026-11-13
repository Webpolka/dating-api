<?php

namespace App\Console\Commands\V1;

use App\Models\User;
use App\Models\Gift;
use App\Models\UserGift;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;


// php artisan gifts:seed vanoo-mywbqsx7

class SeedUserGifts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Аргументы:
     *   {slug?} — slug пользователя-получателя. Если не указан — берётся первый User.
     *
     * Опции:
     *   {--count=10}   — сколько подарков создать (по умолчанию 10)
     *   {--purge}      — удалить ВСЕ старые подарки пользователя перед созданием новых
     *                    (по умолчанию просто добавляет к существующим)
     *
     * Примеры:
     *   php artisan gifts:seed                              # 10 подарков для первого user
     *   php artisan gifts:seed my-user-slug                 # 10 подарков для указанного user
     *   php artisan gifts:seed my-user-slug --count=20      # 20 подарков
     *   php artisan gifts:seed --count=15 --purge           # 15 подарков + удалить старые
     */
    protected $signature = 'gifts:seed
        {slug? : Slug пользователя-получателя (по умолчанию — первый User)}
        {--count=10 : Количество подарков для создания}
        {--purge : Удалить ВСЕ старые подарки пользователя перед созданием новых}';

    /**
     * The console command description.
     */
    protected $description = 'Создаёт тестовые подарки для указанного пользователя (для отладки UI своего профиля)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $slug = $this->argument('slug');
        $count = (int) $this->option('count');
        $purge = $this->option('purge');

        // ============================================
        // 1. НАЙТИ ПОЛУЧАТЕЛЯ
        // ============================================
        if ($slug) {
            $receiver = User::where('slug', $slug)->first();
        } else {
            $receiver = User::first();
        }

        if (!$receiver) {
            $this->error("❌ Пользователь не найден." . ($slug ? " Slug: {$slug}" : " Нет ни одного пользователя в БД."));
            return Command::FAILURE;
        }

        $this->info("👤 Получатель: {$receiver->name} ({$receiver->slug})");

        // ============================================
        // 2. НАЙТИ ОТПРАВИТЕЛЕЙ (другие пользователи)
        // ============================================
        // Берём минимум $count отправителей (или всех остальных, если меньше).
        // Если их меньше чем нужно — будем использовать повторно (по модулю).
        $senders = User::where('id', '!=', $receiver->id)
            ->inRandomOrder()
            ->limit($count)
            ->get();

        if ($senders->isEmpty()) {
            $this->error("❌ Нет других пользователей для отправителей. Создайте ещё пользователей через factory:");
            $this->line("   php artisan tinker");
            $this->line("   >>> User::factory()->count(5)->create()");
            return Command::FAILURE;
        }

        $this->info("📤 Отправители ({$senders->count()}): " . $senders->pluck('name')->join(', '));

        // ============================================
        // 3. НАЙТИ ПОДАРКИ В КАТАЛОГЕ
        // ============================================
        $gifts = Gift::where('is_active', true)
            ->inRandomOrder()
            ->limit($count)
            ->get();

        if ($gifts->isEmpty()) {
            $this->error("❌ Нет активных подарков в каталоге. Создайте подарки в таблице gifts:");
            $this->line("   php artisan tinker");
            $this->line("   >>> Gift::factory()->count(10)->create()");
            return Command::FAILURE;
        }

        $this->info("🎁 Подарки ({$gifts->count()}): " . $gifts->pluck('name')->join(', '));

        // ============================================
        // 4. ОПЦИЯ --purge — УДАЛИТЬ СТАРЫЕ ПОДАРКИ
        // ============================================
        if ($purge) {
            $deleted = UserGift::where('receiver_id', $receiver->id)->delete();
            $this->warn("🗑  Удалено старых подарков: {$deleted}");
        }

        // ============================================
        // 5. СОЗДАТЬ НОВЫЕ ПОДАРКИ
        // ============================================
        // Сообщения — случайные из списка (включая null = без сообщения)
        $messages = [
            'С наилучшими пожеланиями!',
            'Спасибо за приятное общение!',
            'Ты мне очень нравишься ❤',
            'С Днём Рождения!',
            'Маленький знак внимания',
            'Пусть этот день будет особенным',
            'Симпатия взаимная',
            'Будь счастлив(а)!',
            'С наступающим!',
            'За знакомство!',
            null,  // без сообщения
            null,
            null,
        ];

        // Случайные даты в течение последних 30 дней
        $createdAt = function () {
            return Carbon::now()
                ->subDays(rand(0, 30))
                ->subHours(rand(0, 23))
                ->subMinutes(rand(0, 59));
        };

        $created = 0;
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        for ($i = 0; $i < $count; $i++) {
            $sender = $senders[$i % $senders->count()];
            $gift = $gifts[$i % $gifts->count()];

            UserGift::create([
                'sender_id'           => $sender->id,
                'receiver_id'         => $receiver->id,
                'gift_id'             => $gift->id,
                'snapshot_name'       => $gift->name,
                // getRawOriginal — берёт сырой image_url из БД (без accessor-а,
                // который мог бы добавлять CDN-префикс)
                'snapshot_image_url'  => $gift->getRawOriginal('image_url'),
                'snapshot_price'      => $gift->price,
                'message'             => $messages[array_rand($messages)],
                'is_private'          => false,
                'is_read'             => (bool) rand(0, 1),
                'created_at'          => $createdAt(),
                'updated_at'          => now(),
            ]);

            $created++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        // ============================================
        // 6. ИТОГ
        // ============================================
        $totalGifts = UserGift::where('receiver_id', $receiver->id)->count();
        $this->info("");
        $this->info("✅ Создано {$created} новых подарков для {$receiver->name}.");
        $this->info("📊 Всего подарков у пользователя: {$totalGifts}");
        $this->info("");
        $this->info("👉 Открой /profile в браузере и проверь UI!");
        $this->info("   (залогинься под {$receiver->email} если ещё не залогинен)");

        return Command::SUCCESS;
    }
}