<?php

namespace Database\Seeders;

use App\Enums\GiftCategory;
use App\Models\Gift;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GiftSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🎁 Заполняем каталог подарков...');

        // category = null для type=services (у услуг нет тематики)
        $gifts = [
            // === Романтика (romantic) ===
            ['name' => 'Красная роза',           'category' => GiftCategory::Romantic->value, 'price' => 50,    'type' => 'main',    'img' => 'rose'],
            ['name' => 'Плюшевый мишка',         'category' => GiftCategory::Romantic->value, 'price' => 100,   'type' => 'main',    'img' => 'teddybear'],
            ['name' => 'Сердце',                  'category' => GiftCategory::Romantic->value, 'price' => 30,    'type' => 'main',    'img' => 'heart'],
            ['name' => 'Шоколадка',              'category' => GiftCategory::Romantic->value, 'price' => 40,    'type' => 'main',    'img' => 'chocolate'],
            ['name' => 'Букет цветов',           'category' => GiftCategory::Romantic->value, 'price' => 150,   'type' => 'main',    'img' => 'bouquet'],
            ['name' => 'Кольцо с бриллиантом',    'category' => GiftCategory::Romantic->value, 'price' => 1000,  'type' => 'premium', 'img' => 'diamond'],

            // === Авто (cars) ===
            ['name' => 'Мерседес',               'category' => GiftCategory::Cars->value,     'price' => 5000,  'type' => 'premium', 'img' => 'mercedes'],
            ['name' => 'Спорткар',               'category' => GiftCategory::Cars->value,     'price' => 3000,  'type' => 'premium', 'img' => 'sportscar'],
            ['name' => 'Яхта',                   'category' => GiftCategory::Cars->value,     'price' => 10000, 'type' => 'premium', 'img' => 'yacht'],
            ['name' => 'Вертолет',               'category' => GiftCategory::Cars->value,     'price' => 15000, 'type' => 'premium', 'img' => 'helicopter'],

            // === 18+ (adult) ===
            ['name' => 'Клубничка',              'category' => GiftCategory::Adult->value,    'price' => 80,    'type' => 'main',    'img' => 'strawberry'],
            ['name' => 'Шампанское',             'category' => GiftCategory::Adult->value,    'price' => 120,   'type' => 'main',    'img' => 'champagne'],
            ['name' => 'Наручники',              'category' => GiftCategory::Adult->value,    'price' => 200,   'type' => 'main',    'img' => 'handcuffs'],
            ['name' => 'Пломбир',                'category' => GiftCategory::Adult->value,    'price' => 60,    'type' => 'main',    'img' => 'icecream'],

            // === Приколы (fun) ===
            ['name' => 'Ангел',                  'category' => GiftCategory::Fun->value,      'price' => 90,    'type' => 'main',    'img' => 'angel'],
            ['name' => 'Корона',                 'category' => GiftCategory::Fun->value,      'price' => 500,   'type' => 'premium', 'img' => 'crown'],
            ['name' => 'Кубок',                  'category' => GiftCategory::Fun->value,      'price' => 300,   'type' => 'main',    'img' => 'trophy'],

            // === Для него (male) ===
            ['name' => 'Крутые часы',            'category' => GiftCategory::Male->value,     'price' => 700,   'type' => 'premium', 'img' => 'watch'],
            ['name' => 'Боксерская груша',       'category' => GiftCategory::Male->value,     'price' => 450,   'type' => 'main',    'img' => 'boxing'],

            // === Для неё (female) ===
            ['name' => 'Помада',                 'category' => GiftCategory::Female->value,   'price' => 150,   'type' => 'main',    'img' => 'lipstick'],

            // === Сервисы (services) — category = null ===
            ['name' => 'Поднятие в поиске',      'category' => null,                          'price' => 100,   'type' => 'services', 'img' => 'rocket'],
            ['name' => 'Супер-лайк',             'category' => null,                          'price' => 50,    'type' => 'services', 'img' => 'star'],
            ['name' => 'Инкогнито на 1 час',     'category' => null,                          'price' => 70,    'type' => 'services', 'img' => 'spy'],
        ];

        $bar = $this->command->getOutput()->createProgressBar(count($gifts));
        $createdCount = 0;

        foreach ($gifts as $gift) {
            $slug = Str::slug($gift['name']);

            // Проверяем, есть ли локальный файл в public/gifts/100/
            $localPath = "storage/gifts/100/{$gift['img']}.png";
            $imagePath = file_exists(public_path($localPath))
                ? asset($localPath)
                : "https://placehold.co/100x100/EEE/000?text=" . urlencode($gift['name']);

            Gift::updateOrCreate(
                ['slug' => $slug],
                [
                    'name'      => $gift['name'],
                    'image_url' => $imagePath,
                    'price'     => $gift['price'],
                    'category'  => $gift['category'], // null для services
                    'type'      => $gift['type'],
                    'is_active' => ($createdCount % 10 !== 9),
                ]
            );

            $createdCount++;
            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine(2);

        // === Статистика ===
        $total   = Gift::count();
        $active  = Gift::where('is_active', true)->count();
        $inactive = Gift::where('is_active', false)->count();

        $this->command->info('✅ Создано подарков: ' . $total);
        $this->command->info('');
        $this->command->info('📊 Статистика каталога:');

        // По статусу
        $this->command->info("   ┌─────────────────────┬──────────┐");
        $this->command->info("   │ Статус              │ Кол-во   │");
        $this->command->info("   ├─────────────────────┼──────────┤");
        $this->command->info("   │ Всего               │ " . str_pad((string) $total, 8, ' ') . " │");
        $this->command->info("   │ Активных            │ " . str_pad((string) $active, 8, ' ') . " │");
        $this->command->info("   │ Скрытых (inactive)  │ " . str_pad((string) $inactive, 8, ' ') . " │");
        $this->command->info("   └─────────────────────┴──────────┘");

        // По типам
        $types = ['main' => 'Основной', 'premium' => 'Премиум', 'services' => 'Услуги'];
        $this->command->info("   ┌─────────────────────┬──────────┐");
        $this->command->info("   │ Тип                 │ Кол-во   │");
        $this->command->info("   ├─────────────────────┼──────────┤");
        foreach ($types as $typeValue => $typeLabel) {
            $count = Gift::where('type', $typeValue)->count();
            $label = str_pad(mb_substr($typeLabel, 0, 19), 19, ' ');
            $countStr = str_pad((string) $count, 8, ' ');
            $this->command->info("   │ {$label} │ {$countStr} │");
        }
        $this->command->info("   └─────────────────────┴──────────┘");

        // По категориям (включая "Услуги (без категории)" для null)
        $this->command->info("   ┌─────────────────────┬──────────┐");
        $this->command->info("   │ Категория           │ Кол-во   │");
        $this->command->info("   ├─────────────────────┼──────────┤");

        $nullCount = Gift::whereNull('category')->count();
        $nullLabel = str_pad('Услуги (без кат.)', 19, ' ');
        $nullCountStr = str_pad((string) $nullCount, 8, ' ');
        $this->command->info("   │ {$nullLabel} │ {$nullCountStr} │");

        foreach (GiftCategory::cases() as $category) {
            $count = Gift::where('category', $category->value)->count();
            $label = str_pad(mb_substr($category->label(), 0, 19), 19, ' ');
            $countStr = str_pad((string) $count, 8, ' ');
            $this->command->info("   │ {$label} │ {$countStr} │");
        }

        $this->command->info("   └─────────────────────┴──────────┘");
    }
}
