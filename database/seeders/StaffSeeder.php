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

class StaffSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('👷 Создаем сотрудников (Модератор и Саппорт)...');

        $russia = DB::table('countries')->where('iso2', 'RU')->first();
        $spb = DB::table('cities')
            ->where('country_id', $russia->id ?? 0)
            ->whereIn('name', ['Saint Petersburg', 'Saint Petersburg', 'Санкт-Петербург', 'Leningrad'])
            ->first();

        $staffMembers = [
            [
                'name' => 'Модератор Василий',
                'email' => 'moderator@moderator.com',
                'role' => User::ROLE_MODERATOR,
                'gender' => 'male',
                'headline' => 'Служба безопасности',
                'bio' => 'Слежу за порядком на сайте. Нарушители будут забанены!',               
            ],
            [
                'name' => 'Поддержка Анна',
                'email' => 'support@support.com',
                'role' => User::ROLE_SUPPORT,
                'gender' => 'female',
                'headline' => 'Служба заботы о пользователях',
                'bio' => 'Всегда рада помочь! Обращайтесь по любым вопросам.',     
            ],
        ];

        $password = '12121212';

        foreach ($staffMembers as $member) {
            $user = User::withoutEvents(function () use ($member, $password) {
                return User::updateOrCreate(
                    ['email' => $member['email']],
                    [
                        'name' => $member['name'],
                        'slug' => Str::slug($member['name']) . '-' . Str::lower(Str::random(8)), 
                        'password' => Hash::make($password),
                        'role' => $member['role'],
                        'status' => User::STATUS_ACTIVE,
                        'premium_expires_at' => now()->addYears(5),
                        'vip_expires_at' => now()->addYears(5),
                        'is_verified' => true,
                        'has_completed_onboarding' => true,
                        'last_login_at' => now(),
                        'last_login_ip' => '127.0.0.1',
                        'last_seen' => now(),
                        'email_verified_at' => now(),
                    ]
                );
            });

            DB::transaction(function () use ($user, $member, $spb, $russia) {
                UserProfile::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'gender' => $member['gender'],
                        'birth_date' => '1995-05-15',
                        'dating_goals' => ['friends'],
                        'city_id' => $spb->id ?? null, 
                        'country_id' => $russia->id ?? null, 
                        'headline' => $member['headline'],
                        'bio' => $member['bio'],
                        'looking_for' => 'Ищу нарушителей порядка (и хороших людей)!',                        
                        'height' => $member['gender'] === 'male' ? 180 : 165,
                        'weight' => $member['gender'] === 'male' ? 80 : 55,
                        'zodiac_sign' => 5,
                        // ДОБАВЛЕНО: education_level и income для полноты профиля
                        'education_level' => 6, // Высшее
                        'income' => 3, // Хватает на основное и отдых
                    ]
                );

                UserPreference::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'locale' => 'ru',
                        'theme' => 'light',
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
                    ['user_id' => $user->id],
                    [
                        'credits' => 5000,
                        'superlikes_remaining' => 100,
                        'superlikes_reset_at' => now()->addMonth(),
                    ]
                );

                Album::updateOrCreate(
                    ['user_id' => $user->id, 'is_default' => true],
                    [
                        'name' => 'Общие',
                        'is_private' => false,
                        'photos_count' => 0,
                    ]
                );
            });

            $this->command->info("   ✅ Создан {$member['role']}: {$member['email']} (Пароль: {$password})");
        }
    }
}