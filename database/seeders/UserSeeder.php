<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\UserProfile;
use App\Models\UserPreference;
use App\Models\UserBalance;
use App\Models\Album;
use App\Models\Photo;
use App\Enums\DatingGoal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('👤 Создаем обычных пользователей (массовая вставка)...');

        $maleNames = ['Александр', 'Михаил', 'Иван', 'Артём', 'Дмитрий', 'Максим', 'Сергей', 'Андрей', 'Алексей', 'Роман', 'Егор', 'Денис', 'Владимир', 'Павел', 'Кирилл', 'Никита', 'Илья', 'Тимур', 'Глеб', 'Виктор', 'Антон', 'Игорь', 'Олег'];
        $femaleNames = ['Анна', 'Мария', 'Елена', 'Ольга', 'Наталья', 'Татьяна', 'Юлия', 'Ирина', 'Светлана', 'Екатерина', 'Анастасия', 'Дарья', 'Виктория', 'Полина', 'Алина', 'Ксения', 'София', 'Валерия', 'Маргарита', 'Кристина', 'Алиса', 'Вера', 'Надежда'];

        $genders = ['male', 'female'];
        $goals = array_column(DatingGoal::cases(), 'value');

        $bios = [
            "Люблю путешествия и активный отдых. Ищу единомышленников.",
            "Ищу серьезные отношения. Ценю честность и юмор.",
            "Музыка, кино, долгие прогулки по городу. Скучно не будет!",
            "Верю в настоящую любовь. Готов к новым знакомствам.",
            "Работаю в IT, в свободное время хожу в зал и играю на гитаре."
        ];
        $lookingFors = [
            "Хочу найти доброго, заботливого человека для серьезных отношений.",
            "Ищу партнера для совместных путешествий и спорта.",
            "Мечтаю о семье и уюте. Хочу детей в будущем.",
            "Просто хочу найти классных людей для общения."
        ];

        $shortPortraits = [
            "Скромный, люблю пиццу и котиков.",
            "Ищу приключений и хорошего настроения.",
            "Работаю, отдыхаю, живу на полную.",
            "Ценю честность и хороший юмор."
        ];

        $longPortraits = [
            [
                "Я человек разносторонний. Обожаю путешествия, особенно спонтанные. Был в 15 странах, но всегда возвращаюсь домой.",
                "В людях ценю искренность и умение держать слово. Не люблю драму, предпочитаю решать проблемы по мере поступления.",
                "В свободное время хожу в горы, читаю фантастику и учу испанский. Ищу человека, с которым можно обсудить всё на свете за чашкой кофе."
            ],
            [
                "С детства мечтал стать архитектором, и вот я здесь. Проектирую дома, а в свободное время рисую комиксы.",
                "Считаю, что главное в жизни — это баланс. Поэтому стараюсь уделять время и работе, и хобби, и близким.",
                "Ищу девушку, которая разделяет мои интересы или готова разделить свои. Обещаю не скучать и кормить вкусными завтраками!"
            ],
            [
                "Привет! Я считаю, что идеальных людей нет, но есть те, кто идеально подходят друг другу.",
                "Я люблю долгие прогулки по вечернему городу, вкусную еду и хорошие фильмы. Не терплю ложь и предательство.",
                "Если ты ищешь стабильность, заботу и море внимания — напиши мне. Обещаю, будет интересно!"
            ]
        ];

        $institutions = ["МГУ", "СПбГУ", "Кембридж", "МГТУ им. Баумана", "МГИМО", "ВШЭ"];
        $activities = ["IT", "Медицина", "Маркетинг", "Финансы", "Дизайн", "Образование", "Продажи"];
        $positions = ["Разработчик", "Менеджер проекта", "Врач-терапевт", "Учитель", "Дизайнер интерфейсов", "Бухгалтер", "Маркетолог"];

        $options = config('profile_fields.options', []);

        $getRandomIds = function (array $options, int $min = 1, int $max = 3): array {
            $keys = array_keys($options);
            shuffle($keys);
            $count = rand($min, min($max, count($keys)));
            return array_slice($keys, 0, $count);
        };

        $russia = DB::table('countries')->where('iso2', 'RU')->first();
        $cityIds = DB::table('cities')->where('country_id', $russia->id ?? 0)->pluck('id')->toArray();

        $totalUsers = 500;
        $this->command->getOutput()->progressStart($totalUsers);

        // Массивы для массовой вставки
        $usersData = $profilesData = $prefsData = $balancesData = $albumsData = [];
        $passwordHash = Hash::make('password');
        $now = now();

        // 1. ФОРМИРУЕМ ЮЗЕРОВ В ПАМЯТИ
        for ($i = 1; $i <= $totalUsers; $i++) {
            $gender = $genders[array_rand($genders)];
            $name = $gender === 'male' ? $maleNames[array_rand($maleNames)] : $femaleNames[array_rand($femaleNames)];

            $isPremium = rand(1, 10) <= 3;
            $premiumExpires = $isPremium ? $now->copy()->addDays(rand(10, 365)) : null;
            $ip = rand(100, 220) . '.' . rand(10, 250) . '.' . rand(1, 255) . '.' . rand(1, 255);

            $usersData[] = [
                'name' => $name,
                'email' => 'user' . $i . '@test.com',
                'phone' => fake()->unique()->e164PhoneNumber(),
                'password' => $passwordHash, // Хэшируем 1 раз!
                'slug' => Str::slug($name) . '-' . Str::lower(Str::random(8)),
                'email_verified_at' => $now,
                'role' => User::ROLE_USER,
                'status' => User::STATUS_ACTIVE,
                'premium_expires_at' => $premiumExpires,
                'is_verified' => (bool) rand(0, 1),
                'has_completed_onboarding' => true,
                'last_login_at' => $now->copy()->subDays(rand(0, 30)),
                'last_login_ip' => $ip,
                'last_seen' => $now->copy()->subMinutes(rand(1, 4320)),
                'created_at' => $now->copy()->subDays(rand(1, 365)),
                'updated_at' => $now,
            ];
            $this->command->getOutput()->progressAdvance();
        }

        // 2. ВСТАВЛЯЕМ ЮЗЕРОВ И ДОСТАЕМ ИХ ID
        User::insert($usersData);
        $userIds = User::orderBy('id', 'desc')->limit($totalUsers)->pluck('id')->toArray();

        // 3. ФОРМИРУЕМ СВЯЗАННЫЕ ДАННЫЕ В ПАМЯТИ
        $this->command->getOutput()->progressStart($totalUsers);
        foreach ($userIds as $userId) {
            $year = rand(1984, 2006);
            $month = rand(1, 12);
            $day = rand(1, 28);
            $birthDate = "{$year}-{$month}-{$day}";
            $gender = $genders[array_rand($genders)];
            $lat = 55.5 + (rand(0, 100) / 100);
            $lng = 37.3 + (rand(0, 100) / 100);
            $isPremium = rand(1, 10) <= 3;
        
         
            $profilesData[] = [
                'user_id' => $userId,
                'gender' => $gender,
                'birth_date' => $birthDate,
                'dating_goals' => json_encode([$goals[array_rand($goals)]]),
                'city_id' => !empty($cityIds) ? $cityIds[array_rand($cityIds)] : null,
                'country_id' => $russia->id ?? null,
                'headline' => $bios[array_rand($bios)],
                'bio' => $bios[array_rand($bios)],
                'looking_for' => $lookingFors[array_rand($lookingFors)],              
                'body_type' => array_rand($options['body_type']),
                'eye_color' => array_rand($options['eye_color']),
                'hair_color' => array_rand($options['hair_color']),
                'height' => rand(155, 200),
                'weight' => rand(45, 110),
                'relationship_status' => array_rand($options['relationship_status']),
                'children_status' => array_rand($options['children_status']),
                'pets' => array_rand($options['pets']),
                'housing' => array_rand($options['housing']),
                'has_car' => array_rand($options['has_car']),
                'smoking' => array_rand($options['smoking']),
                'alcohol' => array_rand($options['alcohol']),
                'zodiac_sign' => $this->getZodiacSign($month, $day),
                'body_decorations' => json_encode($getRandomIds($options['body_decorations'], 0, 2)),
                'languages' => json_encode($getRandomIds($options['languages'], 1, 3)),
                'sports' => json_encode($getRandomIds($options['sports'], 0, 4)),
                'education_level' => array_rand($options['education_level']),
                'income' => array_rand($options['income']),
                'institution' => $institutions[array_rand($institutions)],
                'institution_year' => rand(2005, (int) date('Y') - 1),
                'activity' => $activities[array_rand($activities)],
                'position' => $positions[array_rand($positions)],
                'location' => DB::raw("ST_SetSRID(ST_MakePoint({$lng}, {$lat}), 4326)::geography"),
                'self_portrait' => json_encode((rand(0, 1) === 1) ? [$shortPortraits[array_rand($shortPortraits)]] : $longPortraits[array_rand($longPortraits)]),
                'created_at' => $now, 'updated_at' => $now,
            ];

            $emailSettings = json_encode([
                'on_message' => (bool) rand(0, 1), 'on_like' => (bool) rand(0, 1),
                'on_view' => (bool) rand(0, 1), 'on_gift' => (bool) rand(0, 1),
                'on_event' => (bool) rand(0, 1), 'on_broadcast' => (bool) rand(0, 1),
                'sub_new_faces' => (bool) rand(0, 1), 'sub_popular' => (bool) rand(0, 1),
            ]);

            $prefsData[] = [
                'user_id' => $userId, 'locale' => 'ru', 'theme' => 'light',
                'preferred_age_min' => rand(18, 25), 'preferred_age_max' => rand(30, 45),
                'preferred_gender' => $genders[array_rand($genders)], 'preferred_distance_km' => rand(10, 100),
                'chat_filter_enabled' => $isPremium ? (bool) rand(0, 1) : false,
                'is_invisible' => $isPremium ? (bool) rand(0, 1) : false,
                'hide_intimate' => (bool) rand(0, 1), 'disable_photo_comments' => (bool) rand(0, 1),
                'hide_from_search' => false, 'show_quick_replies' => true,
                'push_enabled' => (bool) rand(0, 1), 'email_enabled' => true,
                'visibility_gender' => 'any', 'visibility_age_min' => 18, 'visibility_age_max' => 99,
                'push_auto_recommendations' => (bool) rand(0, 1), 'email_auto_recommendations' => (bool) rand(0, 1),
                'allow_auto_messages' => (bool) rand(0, 1), 'chat_widget_enabled' => true,
                'chat_sound_enabled' => true, 'email_settings' => $emailSettings,
                'created_at' => $now, 'updated_at' => $now,
            ];

            $balancesData[] = [
                'user_id' => $userId, 'credits' => rand(0, 500),
                'superlikes_remaining' => rand(0, 5), 'superlikes_reset_at' => $now->copy()->addHours(rand(1, 24)),
                'created_at' => $now, 'updated_at' => $now,
            ];

            $albumsData[] = [
                'user_id' => $userId, 'name' => 'Общие', 'description' => 'Основные фотографии',
                'is_default' => true, 'is_private' => false, 'photos_count' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ];
            $this->command->getOutput()->progressAdvance();
        }

        // 4. ВСТАВЛЯЕМ СВЯЗАННЫЕ ДАННЫЕ ПАЧКАМИ
        UserProfile::insert($profilesData);
        UserPreference::insert($prefsData);
        UserBalance::insert($balancesData);
        Album::insert($albumsData);

        // 5. ФОРМИРУЕМ И ВСТАВЛЯЕМ ФОТО (Нужны album_id)
        $albumIds = Album::orderBy('id', 'desc')->limit($totalUsers)->pluck('id')->toArray();
        $photosData = [];

        foreach ($userIds as $index => $userId) {
            $gender = $profilesData[$index]['gender'];
            $genderPath = $gender === 'male' ? 'men' : 'women';
            $photoId = rand(0, 99);
            $imgUrl = "https://randomuser.me/api/portraits/{$genderPath}/{$photoId}.jpg";

            $photosData[] = [
                'user_id' => $userId, 'album_id' => $albumIds[$index],
                'type' => Photo::TYPE_PROFILE,
                'path_original' => $imgUrl, 'path_large' => $imgUrl, 'path_medium' => $imgUrl, 'path_thumb' => $imgUrl,
                'status' => Photo::STATUS_APPROVED, 'is_primary' => true, 'moderated_at' => $now,
                'position' => 0, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        Photo::insert($photosData);

        $this->command->getOutput()->progressFinish();
        $this->command->info('   ✅ Создано пользователей: ' . count($usersData));
    }

    private function getZodiacSign(int $month, int $day): int
    {
        $zodiacs = [
            1 => ['start' => '03-21', 'end' => '04-19'], 2 => ['start' => '04-20', 'end' => '05-20'],
            3 => ['start' => '05-21', 'end' => '06-20'], 4 => ['start' => '06-21', 'end' => '07-22'],
            5 => ['start' => '07-23', 'end' => '08-22'], 6 => ['start' => '08-23', 'end' => '09-22'],
            7 => ['start' => '09-23', 'end' => '10-22'], 8 => ['start' => '10-23', 'end' => '11-21'],
            9 => ['start' => '11-22', 'end' => '12-21'], 10 => ['start' => '12-22', 'end' => '01-19'],
            11 => ['start' => '01-20', 'end' => '02-18'], 12 => ['start' => '02-19', 'end' => '03-20'],
        ];

        $date = sprintf('%02d-%02d', $month, $day);
        foreach ($zodiacs as $signId => $dates) {
            if ($signId === 10) {
                if ($date >= $dates['start'] || $date <= $dates['end']) return $signId;
            } else {
                if ($date >= $dates['start'] && $date <= $dates['end']) return $signId;
            }
        }
        return 1;
    }
}