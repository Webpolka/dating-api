<?php

namespace Database\Seeders;

use App\Enums\CommentRejectReason;
use App\Models\Photo;
use App\Models\User;
use App\Models\PhotoComment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Сидер комментариев к фото.
 *
 * Стратегия (Вариант A):
 *   - Статусы: pending / approved / rejected. Спам — это причина отклонения (reject_reason='spam').
 *   - При reject всегда указывается причина из CommentRejectReason::userValues() —
 *     для корневых и для ручных ответов. Каскадные ответы получают parent_rejected.
 *
 * Что генерирует сидер:
 *   1. ~25 корневых комментариев со случайными статусами и причинами.
 *   2. 10 approved-родителей с 1-2 ответами каждый.
 *   3. Часть ответов — rejected с parent_rejected (имитация каскада от rejected-родителя).
 *
 * Это покрывает все UI-кейсы модератора: очереди pending, лента approved,
 * rejected с разными причинами, дерево ответов под approved-родителем.
 */
class PhotoCommentSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::where('role', 'user')->get();
        $moderators = User::whereIn('role', ['admin', 'moderator'])->get();
        $photos = Photo::where('status', 'approved')->get();

        if ($users->isEmpty() || $photos->isEmpty()) {
            $this->command->warn('⚠️ Нет пользователей или фото для комментариев!');
            return;
        }

        // Очищаем с каскадом (важно для повторных запусков).
        PhotoComment::query()->delete();
        $this->command->info('💬 Создаем комментарии к фото (массовая вставка)...');

        // Статусы для случайного распределения (без 'spam' — его больше нет).
        $statuses = ['pending', 'approved', 'rejected'];
        // Пользовательские причины (для rejected).
        $userReasons = CommentRejectReason::userValues();

        $comments = [
            'Красивое фото! 😍',
            'Отличный снимок!',
            'Где сделано?',
            'Очень атмосферно!',
            'Прекрасный кадр!',
            'Выглядит профессионально!',
            'Мне нравится! 👍',
            'Класс!',
            'Супер! 🔥',
            'Просто вау! 🤩',
            'Подпишись на меня',
            'Купить виагру со скидкой',
            'Иди в баню',
        ];

        $getModerationFields = function (string $status) use ($moderators, $userReasons) {
            $moderatedBy = $moderators->isNotEmpty() ? $moderators->random()->id : null;
            $moderatedAt = now()->subDays(rand(0, 5));

            if ($status === 'pending') {
                return ['moderated_by' => null, 'moderated_at' => null, 'reject_reason' => null];
            }

            if ($status === 'approved') {
                return ['moderated_by' => $moderatedBy, 'moderated_at' => $moderatedAt, 'reject_reason' => null];
            }

            // rejected — случайная пользовательская причина (включая 'spam').
            return [
                'moderated_by'  => $moderatedBy,
                'moderated_at'  => $moderatedAt,
                'reject_reason' => $userReasons[array_rand($userReasons)],
            ];
        };

        $now = now();
        $rootCommentsData = [];
        $parentCommentsData = [];

        // 1. Одиночные корневые комментарии (25 шт) — случайные статусы.
        for ($i = 0; $i < 25; $i++) {
            $photo = $photos->random();
            $user = $users->random();
            $status = $statuses[array_rand($statuses)];

            $rootCommentsData[] = array_merge([
                'photo_id'      => $photo->id,
                'user_id'       => $user->id,
                'content'       => $comments[array_rand($comments)],
                'status'        => $status,
                'parent_id'     => null,
                'likes_count'   => rand(0, 10),
                'reports_count' => rand(0, 3),
                'replies_count' => 0,
                'is_pinned'     => (bool) rand(0, 1),
                'edited_at'     => rand(0, 1) ? $now->copy()->subDays(rand(0, 2)) : null,
                'created_at'    => $now->copy()->subDays(rand(0, 10)),
                'updated_at'    => $now->copy()->subDays(rand(0, 5)),
            ], $getModerationFields($status));
        }

        // 2. Родительские комментарии (10 шт) — все approved, чтобы были ветки для ответов.
        for ($i = 0; $i < 10; $i++) {
            $photo = $photos->random();
            $user = $users->random();

            $parentCommentsData[] = array_merge([
                'photo_id'      => $photo->id,
                'user_id'       => $user->id,
                'content'       => $comments[array_rand($comments)] . ' (родитель)',
                'status'        => 'approved',
                'parent_id'     => null,
                'likes_count'   => rand(0, 10),
                'reports_count' => rand(0, 2),
                'replies_count' => 0,
                'is_pinned'     => (bool) rand(0, 1),
                'edited_at'     => null,
                'created_at'    => $now->copy()->subDays(rand(0, 10)),
                'updated_at'    => $now->copy()->subDays(rand(0, 5)),
            ], $getModerationFields('approved'));
        }

        // Вставляем корневые и родительские разом.
        $allRootData = array_merge($rootCommentsData, $parentCommentsData);
        PhotoComment::insert($allRootData);

        // 3. Получаем ID только что созданных родительских комментариев.
        $parentIds = PhotoComment::orderBy('id', 'desc')->limit(10)->pluck('id')->toArray();
        $parentIds = array_reverse($parentIds);
        // 3. Получаем ID и photo_id только что созданных родительских комментариев.
        //    ВАЖНО: photo_id ответов должен совпадать с photo_id родителя — это инвариант
        //    данных (ответ принадлежит тому же фото, что и корневой коммент).
        //    Раньше тут был random photo_id, что ломало bulk-операции по фото.
        $parents = PhotoComment::orderBy('id', 'desc')->limit(10)->get(['id', 'photo_id']);
        $parents = $parents->reverse()->values();

        // 4. Формируем ответы. Под approved-родителем:
        //    - 70% approved (обычный диалог)
        //    - 20% pending (новый ответ ждёт модерации)
        //    - 10% rejected с parent_rejected (имитация каскада — НО parent должен быть rejected!)
        //    Для чистоты схемы: parent_rejected ставим только если смоделируем rejected-родителя.
        //    Поэтому в ответах под approved-родителем используем только approved/pending.
        //    - 25% pending (новый ответ ждёт модерации)
        //    Кейс rejected-родителя с каскадом смоделируем отдельно (п. 5).
        $repliesData = [];
        $replyCounts = [];

        foreach ($parentIds as $parentId) {
            foreach ($parents as $parent) {
                $parentId = $parent->id;
                $parentPhotoId = $parent->photo_id;
                $replyCount = rand(1, 2);
                $replyCounts[$parentId] = $replyCount;

                for ($p = 0; $p < $replyCount; $p++) {
                    $photo = $photos->random();
                    $replyUser = $users->random();
                    $replyStatus = ['approved', 'approved', 'approved', 'pending'][array_rand([0, 1, 2, 3])];

                    $repliesData[] = array_merge([
                        'photo_id'      => $photo->id,
                        'photo_id'      => $parentPhotoId, // наследуем от родителя!
                        'user_id'       => $replyUser->id,
                        'content'       => 'Ответ: ' . $comments[array_rand($comments)],
                        'status'        => $replyStatus,
                        'parent_id'     => $parentId,
                        'likes_count'   => rand(0, 5),
                        'reports_count' => rand(0, 2),
                        'replies_count' => 0,
                        'is_pinned'     => false,
                        'edited_at'     => rand(0, 1) ? $now->copy()->subDays(rand(0, 2)) : null,
                        'created_at'    => $now->copy()->subDays(rand(0, 5)),
                        'updated_at'    => $now->copy()->subDays(rand(0, 3)),
                    ], $getModerationFields($replyStatus));
                }
            }

            // 5. Создаём ОДНУ ветку с rejected-родителем и каскадными детьми (для теста UI).
            $rejectedPhoto = $photos->random();
            $rejectedParent = [
                'photo_id'      => $rejectedPhoto->id,
                'user_id'       => $users->random()->id,
                'content'       => 'Каскадный rejected-родитель (для теста дерева)',
                'status'        => 'rejected',
                'parent_id'     => null,
                'likes_count'   => 0,
                'reports_count' => rand(1, 5),
                'replies_count' => 2,
                'is_pinned'     => false,
                'edited_at'     => null,
                'created_at'    => $now->copy()->subDays(rand(0, 5)),
                'updated_at'    => $now->copy()->subDays(rand(0, 3)),
                'moderated_by'  => $moderators->isNotEmpty() ? $moderators->random()->id : null,
                'moderated_at'  => now()->subDays(rand(0, 2)),
                'reject_reason' => 'spam', // родитель отклонён за спам
            ];
            PhotoComment::insert([$rejectedParent]);
            $rejectedParentId = PhotoComment::orderBy('id', 'desc')->value('id');

            // Дети rejected-родителя — все rejected с parent_rejected (как сделал бы контроллер).
            for ($k = 0; $k < 2; $k++) {
                $repliesData[] = [
                    'photo_id'      => $rejectedPhoto->id,
                    'user_id'       => $users->random()->id,
                    'content'       => 'Каскадный ответ #' . ($k + 1) . ' под rejected-родителем',
                    'status'        => 'rejected',
                    'parent_id'     => $rejectedParentId,
                    'likes_count'   => 0,
                    'reports_count' => 0,
                    'replies_count' => 0,
                    'is_pinned'     => false,
                    'edited_at'     => null,
                    'created_at'    => $now->copy()->subDays(rand(0, 3)),
                    'updated_at'    => $now->copy()->subDays(rand(0, 2)),
                    'moderated_by'  => $moderators->isNotEmpty() ? $moderators->random()->id : null,
                    'moderated_at'  => now()->subDays(rand(0, 2)),
                    'reject_reason' => CommentRejectReason::ParentRejected->value,
                ];
            }

            // 6. Вставляем все ответы разом.
            if (!empty($repliesData)) {
                PhotoComment::insert($repliesData);
            }

            // 7. Обновляем счётчики ответов для родительских комментариев.
            foreach ($replyCounts as $parentId => $count) {
                PhotoComment::where('id', $parentId)->update(['replies_count' => $count]);
            }

            $this->command->newLine(2);
            $total = count($rootCommentsData) + count($parentCommentsData) + count($repliesData) + 1; // +1 за rejectedParent
            $this->command->info('✅ Создано комментариев: ' . $total);
            $this->command->line('   • Корневых: ' . count($rootCommentsData));
            $this->command->line('   • Approved-родителей с ответами: ' . count($parentCommentsData));
            $this->command->line('   • Ответов (вкл. каскадных): ' . count($repliesData));
            $this->command->line('   • Rejected-родитель для теста каскада: 1 (+2 ответа)');
        }
    }
}
