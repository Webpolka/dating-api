<?php

namespace Database\Seeders;

use App\Enums\CommentRejectReason;
use App\Enums\DiaryStatus;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryCommentLike;
use App\Models\DiaryLike;
use App\Models\DiaryRubric;
use App\Models\DiarySubscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeder модуля Дневников.
 *
 * Генерирует: рубрики, посты, комментарии (root + replies), подписки, лайки.
 *
 * ============================================
 * СТРАТЕГИЯ МОДЕРАЦИИ (Вариант A — симметрично с PhotoCommentSeeder)
 * ============================================
 *   - 3 статуса: pending / approved / rejected. Спам — это ПРИЧИНА, не статус.
 *   - Виртуальный «karantine» — soft-deleted (deleted_at != null).
 *   - Причины rejected: profanity / insult / off_topic / spam / other / parent_rejected.
 *
 * ============================================
 * АРХИТЕКТУРА (ДВУХПРОХОДНАЯ)
 * ============================================
 *
 * ПРОХОД 1: Создаём ВСЕ root-комментарии (parent_id=NULL).
 *           Используем bulk insert для скорости.
 *
 * ПРОХОД 2: SELECT'ом читаем из БД РЕАЛЬНЫЕ статусы созданных root'ов.
 *           Для каждого root с подходящим статусом создаём reply.
 *
 * Почему именно так (а не через маппинг по позиции массива)?
 * PostgreSQL НЕ ГАРАНТИРУЕТ порядок назначения SERIAL ID при bulk insert.
 * Если порядок нарушается — маппинг ломается, и reply привязывается к ЧУЖОМУ root'у.
 *
 * ============================================
 * ЖЕЛЕЗНАЯ ЛОГИКА СТАТУСОВ
 * ============================================
 *
 * ROOT-комментарии — 5 категорий (распределение по вероятности):
 *   35% Approved  — одобренные (на них можно отвечать)
 *   20% Pending   — на модерации
 *   15% Rejected  — отклонённые модератором (случайная причина из userValues)
 *   15% Rejected+spam — отклонённые со причиной 'spam' (спам = причина, не статус!)
 *   15% Karantine — soft-deleted (deleted_at != null), статус внутри Approved
 *
 * REPLY-комментарии — строгая иерархия (проверяется по РЕАЛЬНОМУ статусу root):
 *
 *   root=Approved  → reply существует. Статус reply: случайный из 3
 *                    (pending/approved/rejected — реалистичный микс).
 *
 *   root=Karantine → reply soft-deleted (каскад карантина).
 *
 *   root=Pending   → reply НЕ существует (юзер не видит, не отвечает).
 *   root=Rejected  → reply НЕ существует (юзер не видит, не отвечает).
 *
 * СПЕЦ-ВЕТКА: rejected-родитель + 2 каскадных ребёнка с parent_rejected
 *             (для теста UI — видно, как каскад работает в админке).
 *
 * ============================================
 * РАСПРЕДЕЛЕНИЕ ПОСТОВ
 * ============================================
 *  - 20% Draft     (черновики)
 *  - 60% Published (опубликованные)
 *  - 20% Pending   (на модерации)
 */
class DiaryModuleSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('📖 Запускаем генерацию модуля Дневников...');

        $users = User::where('role', 'user')->get();
        $admin = User::where('role', 'admin')->first();
        $now = now();

        if ($users->isEmpty()) {
            $this->command->warn('⚠️ Нет пользователей!');
            return;
        }

        // 1. Очистка
        DB::statement('TRUNCATE TABLE diary_comment_likes RESTART IDENTITY CASCADE');
        DB::statement('TRUNCATE TABLE diary_likes RESTART IDENTITY CASCADE');
        DB::statement('TRUNCATE TABLE diary_subscriptions RESTART IDENTITY CASCADE');
        DB::statement('TRUNCATE TABLE diary_comments RESTART IDENTITY CASCADE');
        DB::statement('TRUNCATE TABLE diaries RESTART IDENTITY CASCADE');
        DB::statement('TRUNCATE TABLE diary_rubrics RESTART IDENTITY CASCADE');

        // ============================================
        // ЭТАП 1: РУБРИКИ
        // ============================================
        $rubricsData = [];
        $systemRubrics = [
            ['name' => 'Мысли', 'slug' => 'thoughts', 'sort_order' => 1],
            ['name' => 'О любви', 'slug' => 'about-love', 'sort_order' => 2],
            ['name' => 'Поэзия', 'slug' => 'poetry', 'sort_order' => 3],
            ['name' => 'Путешествия', 'slug' => 'travel', 'sort_order' => 4],
            ['name' => 'Юмор', 'slug' => 'humor', 'sort_order' => 5],
            ['name' => 'Кулинария', 'slug' => 'cooking', 'sort_order' => 6],
            ['name' => 'Музыка', 'slug' => 'music', 'sort_order' => 7],
            ['name' => 'Дневник', 'slug' => 'diary', 'sort_order' => 8],
        ];

        foreach ($systemRubrics as $r) {
            $rubricsData[] = array_merge($r, [
                'user_id' => null,
                'description' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $personalRubricNames = ['Личное', 'Хобби', 'Философия', 'Сны', 'Новости дня'];
        foreach ($users->take(30) as $user) {
            $namesForUser = (array) array_rand(array_flip($personalRubricNames), rand(1, 2));
            $sortOrder = 0;
            foreach ($namesForUser as $name) {
                $rubricsData[] = [
                    'user_id' => $user->id,
                    'name' => $name,
                    'slug' => Str::slug($name) . '-' . $user->id . '-' . Str::random(4),
                    'description' => 'Личная рубрика',
                    'is_active' => true,
                    'sort_order' => $sortOrder++,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DiaryRubric::insert($rubricsData);
        $rubricIds = DiaryRubric::where('is_active', true)->pluck('id')->toArray();
        $this->command->info("   ✅ Рубрик: " . count($rubricsData));

        // ============================================
        // ЭТАП 2: ПОСТЫ (40 шт)
        // ============================================
        $diariesData = [];
        $titleTemplates = ['Мысли вслух', 'Одинокий вечер', 'Мой идеальный выходной', 'Почему мы боимся чувств?', 'Случай на работе', 'Новая глава жизни', 'Мой любимый город', 'Как я справляюсь с грустью', 'Письмо будущему мне'];

        for ($i = 0; $i < 40; $i++) {
            $roll = rand(0, 100);
            if ($roll >= 80) {
                $status = DiaryStatus::Pending;
                $publishedAt = null;
            } elseif ($roll >= 20) {
                $status = DiaryStatus::Published;
                $publishedAt = $now->copy()->subDays(rand(1, 60));
            } else {
                $status = DiaryStatus::Draft;
                $publishedAt = null;
            }

            $diariesData[] = [
                'user_id' => $users->random()->id,
                'diary_rubric_id' => $rubricIds[array_rand($rubricIds)],
                'title' => $titleTemplates[array_rand($titleTemplates)],
                'body' => fake()->realTextBetween(300, 1500),
                'status' => $status->value,
                'published_at' => $publishedAt,
                'is_comments_enabled' => (bool) rand(0, 10) >= 1,
                'is_quote_enabled' => (bool) rand(0, 1),
                'views_count' => rand(5, 500),
                'comments_count' => 0,
                'likes_count' => 0,
                'reject_reason' => null,
                'created_at' => $now->copy()->subDays(rand(1, 60)),
                'updated_at' => $now,
            ];
        }
        Diary::insert($diariesData);
        $diaryIds = Diary::orderBy('id', 'desc')->limit(40)->pluck('id')->toArray();
        $this->command->info("   ✅ Постов: " . count($diariesData));

        // ============================================
        // ЭТАП 3: КОММЕНТАРИИ — ДВУХПРОХОДНАЯ АРХИТЕКТУРА
        // ============================================
        $rootTexts = ["Очень интересно!", "Согласен!", "Подробнее?", "Красивые мысли.", "Скучно...", "Красиво пишешь.", "Жду новых постов!", "А что дальше?", "Трогательно."];
        $replyTexts = ["Спасибо!", "Полностью согласен.", "Тоже так думаю.", "Интересная мысль.", "Спасибо за отклик!", "Рад, что откликнуло."];

        // ───────────────────────────────────────────
        // ПРОХОД 1: Создаём ВСЕ root-комментарии (parent_id=NULL).
        // ───────────────────────────────────────────
        $this->command->info("   → Проход 1: создание root-комментариев...");

        $commentsData = [];
        $commentCounts = [];

        foreach ($diaryIds as $diaryId) {
            $commentCount = rand(0, 5);
            $commentCounts[$diaryId] = $commentCount;

            for ($c = 0; $c < $commentCount; $c++) {
                [$status, $reason, $isKarantine] = $this->randomRootStatus();

                // Для карантина в БД пишем status=Approved (он был одобрен ДО
                // отправки в карантин), но deleted_at != null.
                $dbStatus = $isKarantine ? DiaryComment::STATUS_APPROVED : $status;
                $isMod = !$isKarantine && $status !== DiaryComment::STATUS_PENDING;

                $commentsData[] = [
                    'diary_id' => $diaryId,
                    'user_id' => $users->random()->id,
                    'content' => $rootTexts[array_rand($rootTexts)],
                    'parent_id' => null,
                    'status' => $dbStatus,
                    'likes_count' => 0,
                    'replies_count' => 0,
                    'moderated_by' => $isMod && $admin ? $admin->id : null,
                    'moderated_at' => $isMod ? $now->copy()->subDays(rand(1, 10)) : null,
                    'reject_reason' => $reason,
                    'deleted_at' => $isKarantine ? $now->copy()->subDays(rand(1, 5)) : null,
                    'created_at' => $now->copy()->subDays(rand(0, 10)),
                    'updated_at' => $now,
                ];
            }
        }
        DiaryComment::insert($commentsData);
        $this->command->info("      ✅ Создано root: " . count($commentsData));

        // ───────────────────────────────────────────
        // ПРОХОД 2: SELECT'ом читаем РЕАЛЬНЫЕ данные root'ов из БД.
        // ───────────────────────────────────────────
        $this->command->info("   → Проход 2: создание replies на основе РЕАЛЬНЫХ статусов root'ов...");

        $rootComments = DiaryComment::whereNull('parent_id')
            ->orderBy('id')
            ->get(['id', 'diary_id', 'status', 'deleted_at']);

        $repliesData = [];
        $stats = ['approved' => 0, 'karantine' => 0, 'skipped' => 0];

        foreach ($rootComments as $root) {
            // 40% шанс на reply — но только для подходящих root'ов
            if (rand(0, 100) >= 40) {
                continue;
            }

            $isKarantine = $root->deleted_at !== null;
            $parentStatus = $root->status;

            if ($isKarantine) {
                // Karantine root → reply тоже soft-deleted (каскад карантина)
                $stats['karantine']++;
                $repliesData[] = [
                    'diary_id' => $root->diary_id,
                    'user_id' => $users->random()->id,
                    'content' => $replyTexts[array_rand($replyTexts)],
                    'parent_id' => $root->id,
                    'status' => DiaryComment::STATUS_APPROVED,
                    'likes_count' => 0,
                    'replies_count' => 0,
                    'moderated_by' => $admin?->id,
                    'moderated_at' => $now->copy()->subDays(rand(2, 8)),
                    'reject_reason' => null,
                    'deleted_at' => $now->copy()->subDays(rand(1, 5)),
                    'created_at' => $now->copy()->subDays(rand(2, 8)),
                    'updated_at' => $now,
                ];
            } elseif ($parentStatus === DiaryComment::STATUS_APPROVED) {
                // Approved root → reply со случайным статусом из 3 (реалистичный микс)
                $stats['approved']++;
                $replyStatus = $this->randomReplyStatus();
                $isRepMod = $replyStatus !== DiaryComment::STATUS_PENDING;
                $replyReason = $this->rejectReasonForStatus($replyStatus);

                $repliesData[] = [
                    'diary_id' => $root->diary_id,
                    'user_id' => $users->random()->id,
                    'content' => $replyTexts[array_rand($replyTexts)],
                    'parent_id' => $root->id,
                    'status' => $replyStatus,
                    'likes_count' => 0,
                    'replies_count' => 0,
                    'moderated_by' => $isRepMod && $admin ? $admin->id : null,
                    'moderated_at' => $isRepMod ? $now->copy()->subDays(rand(0, 5)) : null,
                    'reject_reason' => $replyReason,
                    'deleted_at' => null,
                    'created_at' => $now->copy()->subDays(rand(0, 5)),
                    'updated_at' => $now,
                ];
            } else {
                // Pending / Rejected → reply НЕ создаём (юзер не видит, не отвечает)
                $stats['skipped']++;
            }
        }

        if (!empty($repliesData)) {
            DiaryComment::insert($repliesData);
        }

        $this->command->info("      ✅ Создано replies: " . count($repliesData));
        $this->command->info("         → для Approved root: {$stats['approved']}");
        $this->command->info("         → для Karantine root: {$stats['karantine']}");
        $this->command->info("         → пропущено (Pending/Rejected): {$stats['skipped']}");

        // ───────────────────────────────────────────
        // ПРОХОД 3: СПЕЦ-ВЕТКА для теста каскада в UI.
        // Создаём rejected-родителя с 2 каскадными детьми (parent_rejected).
        // Это позволяет модератору увидеть в админке, как выглядит каскад.
        // ───────────────────────────────────────────
        $this->command->info("   → Проход 3: спец-ветка для теста каскада...");

        $cascadeDiaryId = $diaryIds[array_rand($diaryIds)];
        $cascadeRoot = [
            'diary_id' => $cascadeDiaryId,
            'user_id' => $users->random()->id,
            'content' => 'Каскадный rejected-родитель (для теста дерева)',
            'parent_id' => null,
            'status' => DiaryComment::STATUS_REJECTED,
            'likes_count' => 0,
            'replies_count' => 2,
            'moderated_by' => $admin?->id,
            'moderated_at' => $now->copy()->subDays(rand(0, 2)),
            'reject_reason' => CommentRejectReason::Spam->value, // родитель отклонён за спам
            'deleted_at' => null,
            'created_at' => $now->copy()->subDays(rand(0, 5)),
            'updated_at' => $now->copy()->subDays(rand(0, 2)),
        ];
        DiaryComment::insert([$cascadeRoot]);
        $cascadeRootId = DiaryComment::orderBy('id', 'desc')->value('id');

        // Дети rejected-родителя — все rejected с parent_rejected (как сделал бы контроллер).
        for ($k = 0; $k < 2; $k++) {
            $repliesData[] = [
                'diary_id' => $cascadeDiaryId,
                'user_id' => $users->random()->id,
                'content' => 'Каскадный ответ #' . ($k + 1) . ' под rejected-родителем',
                'parent_id' => $cascadeRootId,
                'status' => DiaryComment::STATUS_REJECTED,
                'likes_count' => 0,
                'replies_count' => 0,
                'moderated_by' => $admin?->id,
                'moderated_at' => $now->copy()->subDays(rand(0, 2)),
                'reject_reason' => CommentRejectReason::ParentRejected->value,
                'deleted_at' => null,
                'created_at' => $now->copy()->subDays(rand(0, 3)),
                'updated_at' => $now->copy()->subDays(rand(0, 2)),
            ];
        }
        if (!empty($repliesData)) {
            DiaryComment::insert(array_slice($repliesData, -2));
        }
        $this->command->info("      ✅ Спец-ветка: 1 rejected-родитель + 2 каскадных ребёнка");

        // ============================================
        // ЭТАП 4: ПОДПИСКИ
        // ============================================
        $subsData = [];
        $authorIds = Diary::where('status', DiaryStatus::Published)->distinct()->pluck('user_id')->toArray();
        for ($i = 0; $i < 30; $i++) {
            $authorId = $authorIds[array_rand($authorIds)];
            $subscriberId = $users->where('id', '!=', $authorId)->random()->id;
            $key = $subscriberId . '-' . $authorId;
            if (!isset($subsData[$key])) {
                $subsData[$key] = [
                    'subscriber_id' => $subscriberId,
                    'author_id' => $authorId,
                    'created_at' => $now->copy()->subDays(rand(1, 30)),
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('diary_subscriptions')->insert(array_values($subsData));
        $this->command->info("   ✅ Подписок: " . count($subsData));

        // ============================================
        // ЭТАП 5: ЛАЙКИ
        // ============================================
        $diaryLikesData = [];
        $diaryLikeCounts = [];
        foreach ($diaryIds as $dId) {
            $likeCount = rand(0, 15);
            $diaryLikeCounts[$dId] = $likeCount;
            $likedUsers = $users->random(min($likeCount, $users->count()));
            foreach ($likedUsers as $u) {
                $key = $dId . '-' . $u->id;
                $diaryLikesData[$key] = ['diary_id' => $dId, 'user_id' => $u->id, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        DB::table('diary_likes')->insert(array_values($diaryLikesData));

        // Лайки только на approved комментарии (на отклонённые/карантин лайков не бывает)
        $likableCommentIds = DiaryComment::where('status', DiaryComment::STATUS_APPROVED)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->toArray();

        $commentLikesData = [];
        foreach ($likableCommentIds as $cId) {
            $likeCount = rand(0, 5);
            $likedUsers = $users->random(min($likeCount, $users->count()));
            foreach ($likedUsers as $u) {
                $key = $cId . '-' . $u->id;
                $commentLikesData[$key] = ['diary_comment_id' => $cId, 'user_id' => $u->id, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        DB::table('diary_comment_likes')->insert(array_values($commentLikesData));

        // Обновляем счетчики: считаем ВСЕ комментарии (корневые + ответы) для каждого поста.
        // Раньше считали только корневые, что приводило к расхождению с реальным числом.
        $finalCommentCounts = DiaryComment::select('diary_id', DB::raw('COUNT(*) as total'))
            ->groupBy('diary_id')
            ->pluck('total', 'diary_id');

        foreach ($finalCommentCounts as $dId => $cnt) {
            Diary::where('id', $dId)->update(['comments_count' => $cnt]);
        }
        foreach ($diaryLikeCounts as $dId => $cnt) {
            Diary::where('id', $dId)->update(['likes_count' => $cnt]);
        }
        $commentLikeCounts = array_count_values(
            array_column(array_values($commentLikesData), 'diary_comment_id')
        );
        foreach ($commentLikeCounts as $cId => $cnt) {
            DiaryComment::where('id', $cId)->update(['likes_count' => $cnt]);
        }

        // ============================================
        // СБРОС КЭША (важно!)
        // ============================================
        Cache::forget('admin_diary_counts');
        Cache::forget('admin_diary_rubrics_list');
        Cache::forget('admin_sidebar_stats');
        Cache::forget('admin_dashboard_live_v2');

        $this->command->info("   ✅ Лайков постов: " . count($diaryLikesData) . ", лайков комментов: " . count($commentLikesData));
        $this->command->info('✅ Модуль дневников сгенерирован!');
        $this->command->warn('   ⚠️  Кэш счётчиков сброшен — админ увидит свежие данные сразу.');
    }

    // ============================================
    // ПРИВАТНЫЕ ХЕЛПЕРЫ
    // ============================================

    /**
     * Случайный статус для ROOT-комментария.
     *
     * Возвращает [status, reason, isKarantine] — 5 категорий:
     *   35% Approved  — одобренные (на них можно отвечать)
     *   20% Pending   — на модерации
     *   15% Rejected  — отклонённые (случайная причина из userValues, кроме spam)
     *   15% Rejected+spam — отклонённые со причиной 'spam' (спам = причина, не статус!)
     *   15% Karantine — soft-deleted, статус внутри Approved
     *
     * @return array{0: string, 1: ?string, 2: bool} [status, reason, isKarantine]
     */
    private function randomRootStatus(): array
    {
        $roll = rand(1, 100);

        if ($roll <= 35) {
            // Approved
            return [DiaryComment::STATUS_APPROVED, null, false];
        } elseif ($roll <= 55) {
            // Pending
            return [DiaryComment::STATUS_PENDING, null, false];
        } elseif ($roll <= 70) {
            // Rejected с случайной причиной (кроме spam — он отдельной категорией)
            $userReasons = array_filter(CommentRejectReason::userValues(), fn($r) => $r !== 'spam');
            return [DiaryComment::STATUS_REJECTED, $userReasons[array_rand($userReasons)], false];
        } elseif ($roll <= 85) {
            // Rejected + spam (спам = причина, не статус!)
            return [DiaryComment::STATUS_REJECTED, CommentRejectReason::Spam->value, false];
        } else {
            // Karantine — soft-deleted, статус внутри Approved
            return [DiaryComment::STATUS_APPROVED, null, true];
        }
    }

    /**
     * Случайный статус для REPLY-комментария.
     *
     * Вызывается ТОЛЬКО когда parent=Approved. Reply проходит обычную
     * премодерацию, поэтому может быть в любом из 3 статусов:
     *   - Pending  (только что написан, ждёт модерации)
     *   - Approved (модератор уже одобрил)
     *   - Rejected (модератор отклонил)
     *
     * @return string
     */
    private function randomReplyStatus(): string
    {
        $statuses = [
            DiaryComment::STATUS_APPROVED,
            DiaryComment::STATUS_PENDING,
            DiaryComment::STATUS_REJECTED,
        ];
        return $statuses[array_rand($statuses)];
    }

    /**
     * Возвращает reject_reason для данного статуса комментария.
     *
     * Логика:
     *  - Rejected → случайная причина из userValues (включая spam)
     *  - Approved / Pending → null (причины нет)
     *
     * @param string $status
     */
    private function rejectReasonForStatus(string $status): ?string
    {
        if ($status !== DiaryComment::STATUS_REJECTED) {
            return null;
        }

        $userReasons = CommentRejectReason::userValues();
        return $userReasons[array_rand($userReasons)];
    }
}
