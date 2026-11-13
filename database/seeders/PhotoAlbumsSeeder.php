<?php

namespace Database\Seeders;

use App\Models\Album;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PhotoAlbumsSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('📸 Добавляем доп. альбомы и фото (массовая вставка)...');

        $users = User::with('profile', 'defaultAlbum')->where('role', 'user')->get();

        if ($users->isEmpty()) {
            $this->command->warn('⚠️ Нет пользователей.');
            return;
        }

        $otherAlbumNames = ['Путешествия', 'Друзья', 'Семья', 'Хобби', 'Спорт', 'Отдых', 'Природа'];
        $photosData = [];
        $albumsData = [];
        $now = now();

        $bar = $this->command->getOutput()->createProgressBar($users->count());

        // 1. ФОРМИРУЕМ ФОТО ДЛЯ ДЕФОЛТНЫХ АЛЬБОМОВ И НОВЫЕ АЛЬБОМЫ В ПАМЯТИ
        foreach ($users as $user) {
            $gender = $user->profile->gender ?? 'male';
            $genderPath = $gender === 'male' ? 'men' : 'women';
            $defaultAlbum = $user->defaultAlbum;

            if ($defaultAlbum) {
                $additionalPhotos = rand(1, 3);
                // Быстрый запрос максимальной позиции
                $currentPosition = DB::table('photos')->where('album_id', $defaultAlbum->id)->max('position') ?? 0;

                for ($p = 0; $p < $additionalPhotos; $p++) {
                    $currentPosition++;
                    $photoId = rand(0, 99);
                    $imgUrl = "https://randomuser.me/api/portraits/{$genderPath}/{$photoId}.jpg";
                    
                    $photosData[] = [
                        'user_id' => $user->id,
                        'album_id' => $defaultAlbum->id,
                        'type' => Photo::TYPE_PROFILE,
                        'path_original' => $imgUrl, 'path_large' => $imgUrl, 'path_medium' => $imgUrl, 'path_thumb' => $imgUrl,
                        'status' => Photo::STATUS_APPROVED,
                        'moderated_at' => $now,
                        'is_primary' => false,
                        'is_intimate' => false,
                        'position' => $currentPosition,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }

            // Создаем доп. альбомы
            $extraCount = rand(1, 2);
            shuffle($otherAlbumNames);
            $selectedNames = array_slice($otherAlbumNames, 0, $extraCount);

            foreach ($selectedNames as $name) {
                $albumsData[] = [
                    'user_id' => $user->id,
                    'name' => $name,
                    'description' => "Альбом «{$name}» пользователя {$user->name}",
                    'is_default' => false,
                    'is_private' => (bool) rand(0, 1),
                    'photos_count' => 0, // Обновим позже
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
            $bar->advance();
        }

        // 2. ВСТАВЛЯЕМ НОВЫЕ АЛЬБОМЫ
        if (!empty($albumsData)) {
            Album::insert($albumsData);
        }

        // 3. ПОЛУЧАЕМ ID НОВЫХ АЛЬБОМОВ И ФОРМИРУЕМ ДЛЯ НИХ ФОТО
        $newAlbums = DB::table('albums')->where('created_at', $now)->get(['id', 'user_id', 'is_private']);
        
        // Мапа user_id => gender для быстрого поиска
        $userGenders = $users->pluck('profile.gender', 'id')->toArray();

        foreach ($newAlbums as $album) {
            $gender = $userGenders[$album->user_id] ?? 'male';
            $genderPath = $gender === 'male' ? 'men' : 'women';

            $photoCount = rand(1, 3);
            for ($p = 0; $p < $photoCount; $p++) {
                $photoId = rand(0, 99);
                $imgUrl = "https://randomuser.me/api/portraits/{$genderPath}/{$photoId}.jpg";
                
                $photosData[] = [
                    'user_id' => $album->user_id,
                    'album_id' => $album->id,
                    'type' => Photo::TYPE_PROFILE,
                    'path_original' => $imgUrl, 'path_large' => $imgUrl, 'path_medium' => $imgUrl, 'path_thumb' => $imgUrl,
                    'status' => Photo::STATUS_APPROVED,
                    'moderated_at' => $now,
                    'is_primary' => false,
                    'is_intimate' => $album->is_private ? (bool) rand(0, 1) : false,
                    'position' => $p,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        // 4. ВСТАВЛЯЕМ ВСЕ ФОТО РАЗОМ
        if (!empty($photosData)) {
            Photo::insert($photosData);
        }

        // 5. ОБНОВЛЯЕМ СЧЕТЧИКИ ФОТО ДЛЯ ВСЕХ АЛЬБОМОВ ОДНИМ ЗАПРОСОМ
        DB::statement("UPDATE albums SET photos_count = (SELECT COUNT(*) FROM photos WHERE photos.album_id = albums.id AND photos.deleted_at IS NULL)");

        $bar->finish();
        $this->command->newLine(2);
        $this->command->info('   ✅ Добавлено новых альбомов: ' . count($albumsData));
        $this->command->info('   ✅ Добавлено фото: ' . count($photosData));
    }
}