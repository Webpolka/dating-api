<?php

namespace Database\Seeders;

use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BlogSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('   📝 Создание рубрик блога...');
        
        $categories = [
            ['name' => 'Советы по знакомствам', 'slug' => 'dating-tips', 'sort_order' => 1],
            ['name' => 'Психология отношений', 'slug' => 'psychology', 'sort_order' => 2],
            ['name' => 'Новости сервиса', 'slug' => 'updates', 'sort_order' => 3],
            ['name' => 'Истории успеха', 'slug' => 'success-stories', 'sort_order' => 4],
        ];

        foreach ($categories as $cat) {
            BlogCategory::create($cat);
        }

        $this->command->info('   ✅ Создано 4 рубрики');
        $this->command->info('   📝 Генерация статей блога...');

        $admin = User::where('role', 'admin')->first() ?? User::first();
        
        $postsData = [
            ['title' => 'Как составить идеальную анкету: 5 золотых правил', 'cat' => 'dating-tips', 'status' => BlogPost::STATUS_PUBLISHED, 'featured' => true],
            ['title' => 'Почему мы выбираем не тех людей? Ошибка пьедестала', 'cat' => 'psychology', 'status' => BlogPost::STATUS_PUBLISHED, 'featured' => false],
            ['title' => 'Обновление: Видеозвонки и новые стикеры уже здесь!', 'cat' => 'updates', 'status' => BlogPost::STATUS_PUBLISHED, 'featured' => false],
            ['title' => 'От первого свайпа до свадьбы: история Анны и Игоря', 'cat' => 'success-stories', 'status' => BlogPost::STATUS_PUBLISHED, 'featured' => false],
            ['title' => 'Первое сообщение: как заинтриговать и не быть банальным', 'cat' => 'dating-tips', 'status' => BlogPost::STATUS_PUBLISHED, 'featured' => false],
            ['title' => 'Тревожная привязанность в отношениях: как не разрушить любовь', 'cat' => 'psychology', 'status' => BlogPost::STATUS_PUBLISHED, 'featured' => false],
            ['title' => 'Как безопасно перейти на реальную встречу', 'cat' => 'dating-tips', 'status' => BlogPost::STATUS_PUBLISHED, 'featured' => false],
            ['title' => 'Новые фильтры поиска нашли тебе идеальную пару быстрее', 'cat' => 'updates', 'status' => BlogPost::STATUS_PUBLISHED, 'featured' => false],
            ['title' => 'Как понять, что это "тот самый" человек? 4 признака', 'cat' => 'psychology', 'status' => BlogPost::STATUS_PUBLISHED, 'featured' => false],
            ['title' => 'Они нашли друг друга в соседнем городе', 'cat' => 'success-stories', 'status' => BlogPost::STATUS_PUBLISHED, 'featured' => false],
            ['title' => '(Черновик) 10 фраз, которые сразу отталкивают', 'cat' => 'dating-tips', 'status' => BlogPost::STATUS_DRAFT, 'featured' => false],
            ['title' => '(Черновик) Как пережить расставание и вернуться в дейтинг', 'cat' => 'psychology', 'status' => BlogPost::STATUS_DRAFT, 'featured' => false],
            ['title' => '(Черновик) Готовим запуск VIP-статуса', 'cat' => 'updates', 'status' => BlogPost::STATUS_DRAFT, 'featured' => false],
            ['title' => '(Архив) Старые правила комьюнити 2022 года', 'cat' => 'updates', 'status' => BlogPost::STATUS_ARCHIVED, 'featured' => false],
            ['title' => '(Архив) Как мы тестировали алгоритм мэтчей', 'cat' => 'updates', 'status' => BlogPost::STATUS_ARCHIVED, 'featured' => false],
        ];

        foreach ($postsData as $data) {
            $category = BlogCategory::where('slug', $data['cat'])->first();
            
            $excerpt = "Краткое описание для статьи: {$data['title']}. Читайте в нашем блоге полезные советы и истории.";
            $body = "<h2>{$data['title']}</h2><p>Это тестовое содержимое статьи для проверки работы административной панели.</p><p>Здесь может быть ваш великолепный текст, отформатированный в редакторе. Мы проверяем, как выглядят абзацы, <strong>жирный текст</strong> и <em>курсив</em>.</p><p>Наша платформа знакомств постоянно развивается, и этот блог создан для того, чтобы помогать вам находить идеальные отношения!</p>";

            BlogPost::create([
                'user_id' => $admin?->id,
                'category_id' => $category?->id,
                'title' => $data['title'],
                'slug' => Str::slug($data['title']),
                'excerpt' => $excerpt,
                'body' => $body,
                'status' => $data['status'],
                'is_featured' => $data['featured'],
                'views_count' => rand(10, 1500),
            ]);
        }

        $this->command->info('   ✅ Блог успешно сгенерирован!');
    }
}