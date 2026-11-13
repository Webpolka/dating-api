<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserPreference;
use App\Models\UserBalance;
use App\Models\Album;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('👑 Создаем Владельцев проекта (Суперадминов)...');

        $russia = DB::table('countries')->where('iso2', 'RU')->first();
        $moscow = DB::table('cities')
            ->where('country_id', $russia->id ?? 0)
            ->whereIn('name', ['Moscow', 'Moskva', 'Москва'])
            ->first();

        $founders = [
            [
                'email' => 'admin@admin.com',
                'name' => 'Главный Владелец',
                'password' => '12121212',
            ],
            [
                'email' => 'admin2@admin.com',
                'name' => 'Второй Владелец',
                'password' => '12121212',
            ],
        ];

        $founderIds = [];

        foreach ($founders as $founderData) {

            // Используем withoutEvents, чтобы избежать вызова User::booted() 
            $admin = User::withoutEvents(function () use ($founderData) {
                return User::updateOrCreate(
                    ['email' => $founderData['email']],
                    [
                        'name' => $founderData['name'],
                        'slug' => Str::slug($founderData['name']) . '-' . Str::lower(Str::random(8)),
                        'password' => Hash::make($founderData['password']),
                        'role' => User::ROLE_ADMIN,
                        'status' => User::STATUS_ACTIVE,
                        'premium_expires_at' => now()->addYears(10),
                        'vip_expires_at' => now()->addYears(10),
                        'is_verified' => true,
                        'has_completed_onboarding' => true,
                        'last_login_at' => now(),
                        'last_login_ip' => '127.0.0.1',
                        'last_seen' => now(),
                        'email_verified_at' => now(),
                    ]
                );
            });

            DB::transaction(function () use ($admin, $founderData, $russia, $moscow) {

                UserProfile::updateOrCreate(
                    ['user_id' => $admin->id],
                    [
                        'gender' => 'male',
                        'birth_date' => '1990-01-01',
                        'dating_goals' => ['friends'],
                        'city_id' => $moscow->id ?? null,
                        'country_id' => $russia->id ?? null,
                        'headline' => $founderData['name'] . ' сайта',
                        'bio' => 'Я тут главный! Если есть вопросы - пишите в поддержку. 😎',
                        'looking_for' => 'Помогаем пользователям находить любовь ❤️',                        
                        'body_type' => 2,
                        'eye_color' => 1,
                        'hair_color' => 1,
                        'height' => 180,
                        'weight' => 80,
                        'relationship_status' => 1,
                        'children_status' => 1,
                        'pets' => 1,
                        'housing' => 1,
                        'has_car' => 1,
                        'smoking' => 1,
                        'alcohol' => 1,
                        'zodiac_sign' => 10,
                        'languages' => [1, 2],
                        'sports' => [1, 2],
                        // ИЗМЕНЕНО: education -> education_level (6 = Высшее)
                        'education_level' => 6,
                        // ДОБАВЛЕНО: income (4 = На всё хватает и остаётся)
                        'income' => 4,
                        'institution' => 'МГУ',
                        'institution_year' => 2012,
                        'activity' => 'IT',
                        'position' => 'CEO',
                    ]
                );

                UserPreference::updateOrCreate(
                    ['user_id' => $admin->id],
                    [
                        'locale' => 'ru',
                        'theme' => 'dark',
                        'preferred_age_min' => 18,
                        'preferred_age_max' => 99,
                        'preferred_gender' => 'any',
                        'preferred_distance_km' => 10000,
                        'chat_filter_enabled' => false,
                        'is_invisible' => false,
                        'hide_intimate' => false,
                        'disable_photo_comments' => false,
                        'hide_from_search' => true,
                        'push_enabled' => true,
                        'email_enabled' => true,
                        'visibility_gender' => 'any',
                        'visibility_age_min' => 18,
                        'visibility_age_max' => 99,
                        'push_auto_recommendations' => false,
                        'email_auto_recommendations' => false,
                        'allow_auto_messages' => false,
                        'chat_widget_enabled' => true,
                        'chat_sound_enabled' => true,
                    ]
                );

                UserBalance::updateOrCreate(
                    ['user_id' => $admin->id],
                    [
                        'credits' => 999999,
                        'superlikes_remaining' => 999,
                        'superlikes_reset_at' => now()->addDays(365),
                    ]
                );

                Album::updateOrCreate(
                    [
                        'user_id' => $admin->id,
                        'is_default' => true,
                    ],
                    [
                        'name' => 'Фото владельца',
                        'description' => 'Скрытые фотографии',
                        'is_private' => false,
                        'photos_count' => 0,
                    ]
                );
            });

            $founderIds[] = $admin->id;

            $this->command->info("   ✅ Владелец создан:");
            $this->command->info("      📧 Email: {$founderData['email']}");
            $this->command->info("      🔑 Пароль: {$founderData['password']}");
            $this->command->info("      🆔 ID: {$admin->id}");
        }

        $foundersString = implode(',', $founderIds);
        $this->command->warn("\n⚠️  ВАЖНО! Добавьте эту строку в ваш .env файл:");
        $this->command->line("APP_FOUNDERS={$foundersString}");
        $this->command->info("Это защитит аккаунты Владельцев от случайного удаления в админке.\n");
    }
}
