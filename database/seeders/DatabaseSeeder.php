<?php

namespace Database\Seeders;

use App\Models\{
    AdminLog, UserAuthLog, UserEvent, 
    BlogCategory, BlogPost, Broadcast, Chat, ChatParticipant, FraudAlert, 
    Gift, Media, Message, Photo, PhotoComment, Album, Report, StopWord, 
    SubscriptionPlan, Swipe, Transaction, User, UserGift, UserMatch, 
    UserPreference, UserProfile, UserSubscription, Setting, Diary, DiaryComment, 
    DiarySubscription, DiaryRubric, SupportTemplate, GeoIPLocation, UserCard, 
    PromoCode, PromoCodeUsage, Page, DiaryLike, DiaryCommentLike, ProfileView, Interest,
    SystemLog
};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🚀 Запуск полной генерации базы данных LovePlanet...');
        $this->command->info('');

        // 1. ОЧИСТКА БАЗЫ И КЭША
        $this->command->info('🗑️ Очистка базы данных...');
        $this->cleanDatabase();
        $this->command->info('✅ База данных очищена!');
        
        $this->command->info('📁 Очистка папок с фото...');
        $this->cleanPhotoDirectories();
        
        Cache::forget('settings_v1'); 
        Cache::forget('stop_words_active');  
        $this->command->info('🗑️ Кеш очищен!');
        $this->command->info('');

        // 2. БАЗОВЫЕ ГЕО-ДАННЫЕ
        $this->seedBaseGeo();

        // 3. ЗАПУСК СИДЕРОВ ПО ЭТАПАМ
        $this->command->info('📦 Запуск сидеров...');
        $this->command->info('');

        // ЭТАП 1: БАЗА И АДМИНЫ
        $this->command->info('📌 ЭТАП 1: Базовые сущности');
        $this->call([
            GeoIPLocationsSeeder::class,
            AdminSeeder::class,
            StaffSeeder::class,
            UserSeeder::class,
            SettingSeeder::class,
            InterestSeeder::class,
            UserInterestsSeeder::class,
        ]);

        // ЭТАП 2: СПРАВОЧНИКИ И БЛОГ
        $this->command->info('📌 ЭТАП 2: Справочники и Блог');
        $this->call([
            SubscriptionPlanSeeder::class,
            GiftSeeder::class,
            StopWordSeeder::class,
            PageSeeder::class,  
            PromoCodeSeeder::class,
            BlogSeeder::class,
            SupportTemplateSeeder::class,
        ]);

        // ЭТАП 3: КОНТЕНТ ЮЗЕРОВ
        $this->command->info('📌 ЭТАП 3: Контент');
        $this->call([
            PhotoAlbumsSeeder::class,
            PhotoCommentSeeder::class,
            ProfileViewSeeder::class,
            DiaryModuleSeeder::class, 
        ]);

        // ЭТАП 4: СОЦИАЛЬНЫЙ ГРАФ
        $this->command->info('📌 ЭТАП 4: Взаимодействия');
        $this->call([
            SwipeSeeder::class,
            ChatSeeder::class,
        ]);

        // ЭТАП 5: МОНЕТИЗАЦИЯ
        $this->command->info('📌 ЭТАП 5: Монетизация');
        $this->call([          
            UserCardsSeeder::class,
            FinanceHistorySeeder::class,
            PromoCodeUsagesSeeder::class,
        ]);

        // ЭТАП 6: БЕЗОПАСНОСТЬ И МОДЕРАЦИЯ
        $this->command->info('📌 ЭТАП 6: Безопасность');
        $this->call([
            ReportSeeder::class,
            FraudAlertSeeder::class,
            VerificationSeeder::class, 
            AdminLogSeeder::class,
            UserBlockSeeder::class,
        ]);

        // ЭТАП 7: МАРКЕТИНГ, ЛОГИ И ТЕСТЫ
        $this->command->info('📌 ЭТАП 7: Рассылки, логи и тесты');
        $this->call([
            BroadcastSeeder::class,         
            UserAuthLogsSeeder::class,   
            UserEventsSeeder::class,
            TestLogsSeeder::class,
        ]);

        $this->command->info('');
        $this->command->info('🎉 Все сидеры выполнены успешно!');
        $this->command->info('');

        // 4. ИТОГОВАЯ СТАТИСТИКА
        $this->command->info('📊 Итоговая статистика базы:');
        $this->command->info('   ┌───────────────────────────┬────────────┐');
        $this->command->info('   │ Сущность                  │ Количество │');
        $this->command->info('   ├───────────────────────────┼────────────┤');
        
        $this->command->info('   │ 👑 Админов                │ ' . str_pad(User::where('role', 'admin')->count(), 8, ' ', STR_PAD_LEFT) . ' │');
        $this->command->info('   │ 👤 Пользователей          │ ' . str_pad(User::where('role', 'user')->count(), 8, ' ', STR_PAD_LEFT) . ' │');
        $this->command->info('   │ 📸 Фото                   │ ' . str_pad(Photo::count(), 8, ' ', STR_PAD_LEFT) . ' │');
        $this->command->info('   │ 💬 Комментариев (фото)    │ ' . str_pad(PhotoComment::count(), 8, ' ', STR_PAD_LEFT) . ' │');
        $this->command->info('   │ 📔 Дневников              │ ' . str_pad(Diary::count(), 8, ' ', STR_PAD_LEFT) . ' │');
        $this->command->info('   │ 👉 Свайпов                │ ' . str_pad(Swipe::count(), 8, ' ', STR_PAD_LEFT) . ' │');
        $this->command->info('   │ ❤️ Матчей                 │ ' . str_pad(UserMatch::count(), 8, ' ', STR_PAD_LEFT) . ' │');
        $this->command->info('   │ 💌 Чатов                  │ ' . str_pad(Chat::count(), 8, ' ', STR_PAD_LEFT) . ' │');
        $this->command->info('   │ 💬 Сообщений              │ ' . str_pad(Message::count(), 8, ' ', STR_PAD_LEFT) . ' │');
        $this->command->info('   │ 🎁 Подарков (каталог)     │ ' . str_pad(Gift::count(), 8, ' ', STR_PAD_LEFT) . ' │');
        $this->command->info('   │ 💝 Подарков (отправлено)  │ ' . str_pad(UserGift::count(), 8, ' ', STR_PAD_LEFT) . ' │');
        $this->command->info('   │ 💳 Транзакций             │ ' . str_pad(Transaction::count(), 8, ' ', STR_PAD_LEFT) . ' │');
        $this->command->info('   │ 🚩 Жалоб                  │ ' . str_pad(Report::count(), 8, ' ', STR_PAD_LEFT) . ' │');        
        $this->command->info('   │ 🚨 Антифрод алертов       │ ' . str_pad(FraudAlert::count(), 8, ' ', STR_PAD_LEFT) . ' │');
        $this->command->info('   │ 🕵️ Логов админа           │ ' . str_pad(AdminLog::count(), 8, ' ', STR_PAD_LEFT) . ' │');
        $this->command->info('   │ ⚙️ Настроек               │ ' . str_pad(Setting::count(), 8, ' ', STR_PAD_LEFT) . ' │');
        $this->command->info('   │ 📨 Рассылок               │ ' . str_pad(Broadcast::count(), 8, ' ', STR_PAD_LEFT) . ' │');
        $this->command->info('   │ 👁️ Просмотров анкет      │ ' . str_pad(ProfileView::count(), 8, ' ', STR_PAD_LEFT) . ' │');
        $this->command->info('   └───────────────────────────┴────────────┘');
        
        $this->command->info('');
        $this->command->info('🔑 Данные для входа:');
        $this->command->info('   Админ: admin@admin.com / 12121212');
        $this->command->info('   Юзер:  user1@test.com / password');
    }

    private function seedBaseGeo(): void
    {
        // Если гео-данные уже импортированы (через geo:import), пропускаем
        if (\App\Models\Country::count() > 0) {
            $this->command->info('🌍 Гео-данные уже импортированы (' . \App\Models\City::count() . ' городов), пропускаем базовую географию.');
            $this->command->info('');
            return;
        }

        $this->command->info('🌍 Создание базовых гео-данных (Россия и 4 города)...');
        $russia = \App\Models\Country::create(['name' => 'Россия', 'iso2' => 'RU', 'iso3' => 'RUS', 'phone_code' => '+7']);
        $cities = [
            ['name' => 'Москва', 'timezone' => 'Europe/Moscow'],
            ['name' => 'Санкт-Петербург', 'timezone' => 'Europe/Moscow'],
            ['name' => 'Казань', 'timezone' => 'Europe/Moscow'],
            ['name' => 'Новосибирск', 'timezone' => 'Asia/Novosibirsk'],
        ];
        foreach ($cities as $city) {
            \App\Models\City::create(array_merge($city, ['country_id' => $russia->id]));
        }
        $this->command->info('✅ Гео-данные созданы!');
        $this->command->info('');
    }

    private function cleanDatabase(): void
    {
        DB::statement('TRUNCATE TABLE 
            admin_logs, albums, blog_categories, blog_posts, broadcasts, 
            chat_participants, chats, diary_comment_likes, diary_comments, 
            diary_likes, diary_rubrics, diary_subscriptions, diaries, 
            fraud_alerts, geoip_locations, gifts, media, messages, pages, 
            photo_comments, photos, profile_views, promo_code_usages, 
            promo_codes, reports, stop_words, subscription_plans, 
            support_templates, support_template_categories, swipes, transactions, user_auth_logs, 
            user_balances, user_blocks, user_cards, user_events, user_favorites, 
            user_gifts, user_matches, user_preferences, user_profiles, 
            user_subscriptions, settings, users, interests, interest_categories, user_interests
            RESTART IDENTITY CASCADE'
        );
    }

    private function cleanPhotoDirectories(): void
    {
        $directories = ['photos/pending', 'photos/approved', 'photos/profile', 'media']; 
        foreach ($directories as $dir) {
            if (Storage::disk('public')->exists($dir)) {
                Storage::disk('public')->deleteDirectory($dir);
            }
        }
        foreach ($directories as $dir) {
            if (!Storage::disk('public')->exists($dir)) {
                Storage::disk('public')->makeDirectory($dir);
            }
        }
    }
}