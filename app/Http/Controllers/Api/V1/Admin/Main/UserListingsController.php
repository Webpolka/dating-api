<?php

namespace App\Http\Controllers\Api\V1\Admin\Main;

use App\Enums\V1\GiftCategory;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Main\UserAuthLogsRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserDatingRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserDiariesRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserDiaryCommentsRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserGiftsRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserListRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserPhotoCommentsRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserTransactionsRequest;
use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use App\Models\AdminLog;
use App\Models\Broadcast;
use App\Models\Chat;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\PhotoComment;
use App\Models\PromoCodeUsage;
use App\Models\Swipe;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserAuthLog;
use App\Models\UserCard;
use App\Models\UserEvent;
use App\Models\UserGift;
use App\Models\UserMatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Пер-юзер листинги для табов страницы пользователя (/users/{id}/*).
 *
 * Почему отдельный контроллер, а не ещё 13 методов в UserController:
 * UserController уже ~750 строк и отвечает за ЯДРО (профиль, баны,
 * сессии). Табы-листинги — однородная читательская группа: все методы
 * выглядят одинаково (404-гард → фильтры → paginate → formatter),
 * и им нужен общий приватный инструментарий (guardUser, paginatorToArray,
 * badgeUser). Держать их вместе = читать одну страницу вместо десяти.
 *
 * ОБЩИЕ ПРАВИЛА (зеркаляют UserController):
 *
 * 1. 404-гард: юзер ищется с withTrashed() — страница деактивированного
 *    юзера должна работать (восстановление, просмотр истории).
 *
 * 2. Форма ответа — «плоский» Laravel-пагинатор:
 *    {data: [...], total, current_page, last_page, per_page, from, to}
 *    + опциональные counts (для статус-табов) и status (эхо фильтра).
 *    Идентично ban-history / reports / blocks / photos.
 *
 * 3. ACCESSOR-БЕЗОПАСНОСТЬ (главный класс багов этого проекта на
 *    PostgreSQL): в ->select() ЗАПРЕЩЕНО указывать accessor'ы из
 *    $appends моделей — только реальные колонки БД:
 *      - User: avatar_url, is_online, has_active_premium/vip — accessor'ы
 *      - Photo: thumb_url, medium_url, large_url, original_url — accessor'ы
 *    Иначе PostgreSQL падает с `column "thumb_url" does not exist`.
 *
 * 4. Мутации НЕ живут здесь: табы только читают. Действия идут через
 *    глобальные разделы модерации (POST /admin/photos/{id}/* и т.д.).
 *
 * 5. Комментарии по-русски, с объяснением «почему» — стандарт проекта.
 */
class UserListingsController extends AdminController
{
    /**
     * Реальные колонки photos для avatar-контекста (без accessor'ов!).
     * Уже есть в UserController::PHOTO_AVATAR_COLUMNS — здесь своя копия
     * с path_medium (для превью фото в списках комментариев).
     */
    private const PHOTO_CONTEXT_COLUMNS = [
        'id',
        'user_id',
        'is_primary',
        'status',
        'path_thumb',
        'path_medium',
        'deleted_at',
    ];

    /**
     * Реальные колонки users для AdminUserBadgeResource (+ role для withRole).
     * avatar_url / is_online / has_active_* — accessor'ы, НЕ селектим.
     */
    private const USER_BADGE_COLUMNS = [
        'id',
        'name',
        'email',
        'role',
        'status',
        'deleted_at',
        'is_verified',
        'premium_expires_at',
        'vip_expires_at',
        'last_seen',
    ];

    // ============================================
    // ИНСТРУМЕНТЫ
    // ============================================

    /**
     * 404-гард юзера. null = юзер найден, можно работать.
     * JsonResponse = вернуть его сразу из метода.
     */
    private function userNotFoundResponse(int $userId): ?JsonResponse
    {
        if (User::withTrashed()->find($userId)) {
            return null;
        }
        return response()->json(['message' => 'Пользователь не найден'], 404);
    }

    /**
     * Eager-load юзера с аватаркой для AdminUserBadgeResource.
     * Селект — только реальные колонки (см. USER_BADGE_COLUMNS).
     */
    private function badgeUser(): \Closure
    {
        return fn($q) => $q
            ->withTrashed()
            ->select(self::USER_BADGE_COLUMNS)
            ->with([
                'photos' => fn($sq) => $sq
                    ->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb'])
                    ->orderByDesc('is_primary')
                    ->limit(1),
            ]);
    }

    /**
     * Универсальный конвертер paginator → плоский JSON.
     * Структура идентична UserController::reportPaginatorToArray().
     *
     * @param \Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator
     * @param callable $formatter fn($model) => array
     */
    private function paginatorToArray($paginator, callable $formatter): array
    {
        return [
            'data'         => $paginator->getCollection()->map($formatter)->values(),
            'total'        => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'from'         => $paginator->firstItem(),
            'to'           => $paginator->lastItem(),
        ];
    }

    // ============================================
    // МОДЕРАЦИЯ: комментарии к фото (автор = юзер)
    // ============================================

    /**
     * GET /api/admin/users/{user}/photo-comments?status=pending&page=2
     *
     * Комментарии, написанные пользователем, на любых фото.
     * Для каждого — контекст фото (превью + владелец), чтобы модератор
     * видел, ГДЕ оставлен комментарий, не переходя в глобальную очередь.
     */
    public function photoComments(UserPhotoCommentsRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $status = $request->status('all');
        $perPage = $request->perPage(15);

        $query = PhotoComment::withTrashed()
            ->with([
                'photo' => fn($q) => $q
                    ->withTrashed()
                    ->select(self::PHOTO_CONTEXT_COLUMNS)
                    ->with(['user' => $this->badgeUser()]),
            ])
            ->where('user_id', $userId);

        if ($status === 'karantine') {
            $query->whereNotNull('deleted_at');
        } elseif ($status !== 'all') {
            $query->where('status', $status)->whereNull('deleted_at');
        }

        $comments = $query
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $request->page());

        $counts = PhotoComment::withTrashed()
            ->where('user_id', $userId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' AND deleted_at IS NULL THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' AND deleted_at IS NULL THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' AND deleted_at IS NULL THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'spam' AND deleted_at IS NULL THEN 1 ELSE 0 END) as spam,
                SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) as karantine
            ")
            ->first();

        return response()->json(
            $this->paginatorToArray($comments, fn(PhotoComment $c) => $this->formatPhotoComment($c)) + [
                'counts' => [
                    'total'     => (int) ($counts->total ?? 0),
                    'pending'   => (int) ($counts->pending ?? 0),
                    'approved'  => (int) ($counts->approved ?? 0),
                    'rejected'  => (int) ($counts->rejected ?? 0),
                    'spam'      => (int) ($counts->spam ?? 0),
                    'karantine' => (int) ($counts->karantine ?? 0),
                ],
                'status' => $status,
            ]
        );
    }

    private function formatPhotoComment(PhotoComment $c): array
    {
        $p = $c->photo;

        return [
            'id'            => $c->id,
            'photo_id'      => $c->photo_id,
            'parent_id'     => $c->parent_id,
            'content'       => $c->content,
            'status'        => $c->status,
            'reject_reason' => $c->reject_reason,
            'moderated_by'  => $c->moderated_by,
            'moderated_at'  => $c->moderated_at?->toIso8601String(),
            'created_at'    => $c->created_at?->toIso8601String(),
            'updated_at'    => $c->updated_at?->toIso8601String(),
            'trashed'       => $c->trashed(),
            'is_pinned'     => (bool) $c->is_pinned,
            'likes_count'   => (int) $c->likes_count,
            'replies_count' => (int) $c->replies_count,
            // Контекст: где оставлен комментарий.
            // thumb_url / medium_url — accessor'ы Photo::$appends,
            // в SQL мы селектили только path_thumb / path_medium.
            'photo' => $p ? [
                'id'         => $p->id,
                'user_id'    => $p->user_id,
                'status'     => $p->status,
                'is_primary' => (bool) $p->is_primary,
                'trashed'    => $p->trashed(),
                'thumb_url'  => $p->thumb_url,
                'medium_url' => $p->medium_url,
                'user'       => $p->user ? AdminUserBadgeResource::nullable($p->user) : null,
            ] : null,
        ];
    }

    // ============================================
    // МОДЕРАЦИЯ: дневники (автор = юзер)
    // ============================================

    /**
     * GET /api/admin/users/{user}/diaries?status=all&trashed=without&page=2
     *
     * Формат 1:1 с DiaryController::formatDiary() — фронт переиспользует
     * тот же DiaryRow, что и в глобальном разделе модерации.
     */
    public function diaries(UserDiariesRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $status = $request->status('all');
        $trashed = $request->trashed('without');
        $perPage = $request->perPage(15);

        $query = Diary::query()
            ->with([
                'user' => $this->badgeUser(),
                'diaryRubric',
            ])
            ->where('user_id', $userId);

        if ($trashed === 'with') {
            $query->withTrashed();
        } elseif ($trashed === 'only') {
            $query->onlyTrashed();
        }

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $diaries = $query
            ->latest('published_at')
            ->latest('created_at')
            ->paginate($perPage, ['*'], 'page', $request->page());

        $counts = Diary::withTrashed()
            ->where('user_id', $userId)
            ->selectRaw("
                COUNT(*) as all_count,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
                SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) as trashed
            ")
            ->first();

        return response()->json(
            $this->paginatorToArray($diaries, fn(Diary $d) => $this->formatDiary($d)) + [
                'counts' => [
                    'all'       => (int) ($counts->all_count ?? 0),
                    'published' => (int) ($counts->published ?? 0),
                    'pending'   => (int) ($counts->pending ?? 0),
                    'rejected'  => (int) ($counts->rejected ?? 0),
                    'draft'     => (int) ($counts->draft ?? 0),
                    'trashed'   => (int) ($counts->trashed ?? 0),
                ],
                'status'  => $status,
                'trashed' => $trashed,
            ]
        );
    }

    /**
     * Копия DiaryController::formatDiary() (не detailed — body не нужен
     * в списке; полный текст грузится отдельным эндпоинтом /diary/{id}).
     */
    private function formatDiary(Diary $d): array
    {
        return [
            'id'                 => $d->id,
            'title'              => $d->title,
            'status'             => $d->status,
            'reject_reason'      => $d->reject_reason,
            'moderated_by'       => $d->moderated_by,
            'moderated_at'       => $d->moderated_at?->toIso8601String(),
            'published_at'       => $d->published_at?->toIso8601String(),
            'created_at'         => $d->created_at?->toIso8601String(),
            'updated_at'         => $d->updated_at?->toIso8601String(),
            'trashed'            => $d->trashed(),
            'is_comments_enabled' => (bool) $d->is_comments_enabled,
            'views_count'        => (int) $d->views_count,
            'comments_count'     => (int) $d->comments_count,
            'likes_count'        => (int) $d->likes_count,
            'user'               => $d->user ? AdminUserBadgeResource::nullable($d->user, withRole: true) : null,
            'diary_rubric'       => $d->diaryRubric ? [
                'id'          => $d->diaryRubric->id,
                'name'        => $d->diaryRubric->name,
                'is_active'   => (bool) $d->diaryRubric->is_active,
                'is_personal' => $d->diaryRubric->user_id !== null,
                'user_id'     => $d->diaryRubric->user_id,
            ] : null,
        ];
    }

    // ============================================
    // МОДЕРАЦИЯ: комментарии к дневникам (автор = юзер)
    // ============================================

    /**
     * GET /api/admin/users/{user}/diary-comments?status=pending&page=2
     */
    public function diaryComments(UserDiaryCommentsRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $status = $request->status('all');
        $perPage = $request->perPage(15);

        $query = DiaryComment::withTrashed()
            ->with([
                'diary' => fn($q) => $q->withTrashed()->select(['id', 'user_id', 'title', 'status', 'deleted_at'])
                    ->with(['user' => $this->badgeUser()]),
            ])
            ->where('user_id', $userId);

        if ($status === 'karantine') {
            $query->whereNotNull('deleted_at');
        } elseif ($status !== 'all') {
            $query->where('status', $status)->whereNull('deleted_at');
        }

        $comments = $query
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $request->page());

        $counts = DiaryComment::withTrashed()
            ->where('user_id', $userId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' AND deleted_at IS NULL THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' AND deleted_at IS NULL THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' AND deleted_at IS NULL THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN status = 'spam' AND deleted_at IS NULL THEN 1 ELSE 0 END) as spam,
                SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) as karantine
            ")
            ->first();

        return response()->json(
            $this->paginatorToArray($comments, fn(DiaryComment $c) => $this->formatDiaryComment($c)) + [
                'counts' => [
                    'total'     => (int) ($counts->total ?? 0),
                    'pending'   => (int) ($counts->pending ?? 0),
                    'approved'  => (int) ($counts->approved ?? 0),
                    'rejected'  => (int) ($counts->rejected ?? 0),
                    'spam'      => (int) ($counts->spam ?? 0),
                    'karantine' => (int) ($counts->karantine ?? 0),
                ],
                'status' => $status,
            ]
        );
    }

    private function formatDiaryComment(DiaryComment $c): array
    {
        $d = $c->diary;

        return [
            'id'            => $c->id,
            'diary_id'      => $c->diary_id,
            'parent_id'     => $c->parent_id,
            'content'       => $c->content,
            'status'        => $c->status,
            'reject_reason' => $c->reject_reason,
            'moderated_by'  => $c->moderated_by,
            'moderated_at'  => $c->moderated_at?->toIso8601String(),
            'created_at'    => $c->created_at?->toIso8601String(),
            'updated_at'    => $c->updated_at?->toIso8601String(),
            'trashed'       => $c->trashed(),
            'likes_count'   => (int) $c->likes_count,
            'replies_count' => (int) $c->replies_count,
            // Контекст: под каким постом оставлен комментарий.
            'diary' => $d ? [
                'id'     => $d->id,
                'title'  => $d->title,
                'status' => $d->status,
                'trashed' => $d->trashed(),
                'user'   => $d->user ? AdminUserBadgeResource::nullable($d->user) : null,
            ] : null,
        ];
    }

    // ============================================
    // МОДЕРАЦИЯ: знакомства (свайпы + мэтчи юзера)
    // ============================================

    /**
     * GET /api/admin/users/{user}/dating?swipes_page=1&matches_page=2
     *
     * Две независимые пагинации (паттерн reports made/received):
     *  - swipes:  свайпы, сделанные юзером (+ counts по типу)
     *  - matches: мэтчи с участием юзера (обе стороны)
     */
    public function dating(UserDatingRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $badgeUser = $this->badgeUser();

        $swipes = Swipe::with(['targetUser' => $badgeUser])
            ->where('user_id', $userId)
            ->latest('created_at')
            ->latest('id')
            ->paginate(10, ['*'], 'swipes_page', $request->swipesPage());

        $matches = UserMatch::with(['user1' => $badgeUser, 'user2' => $badgeUser])
            ->where(function ($q) use ($userId) {
                $q->where('user1_id', $userId)->orWhere('user2_id', $userId);
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate(10, ['*'], 'matches_page', $request->matchesPage());

        $swipeCounts = Swipe::where('user_id', $userId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN type = 'like' THEN 1 ELSE 0 END) as likes,
                SUM(CASE WHEN type = 'dislike' THEN 1 ELSE 0 END) as dislikes,
                SUM(CASE WHEN type = 'superlike' THEN 1 ELSE 0 END) as superlikes
            ")
            ->first();

        return response()->json([
            'swipes'  => $this->paginatorToArray($swipes, fn(Swipe $s) => $this->formatSwipe($s)),
            'matches' => $this->paginatorToArray($matches, fn(UserMatch $m) => $this->formatMatch($m)),
            'counts'  => [
                'swipes'      => (int) ($swipeCounts->total ?? 0),
                'likes'       => (int) ($swipeCounts->likes ?? 0),
                'dislikes'    => (int) ($swipeCounts->dislikes ?? 0),
                'superlikes'  => (int) ($swipeCounts->superlikes ?? 0),
                'matches'     => UserMatch::where(function ($q) use ($userId) {
                    $q->where('user1_id', $userId)->orWhere('user2_id', $userId);
                })->count(),
            ],
        ]);
    }

    /** Формат 1:1 с DatingController::formatSwipe(), но без автора — автор это сам юзер страницы. */
    private function formatSwipe(Swipe $s): array
    {
        return [
            'id'         => $s->id,
            'type'       => $s->type,
            'rewinded'   => (bool) $s->rewinded_at,
            'created_at' => $s->created_at?->toIso8601String(),
            'targetUser' => $s->targetUser ? AdminUserBadgeResource::nullable($s->targetUser) : null,
        ];
    }

    /** Формат 1:1 с DatingController::formatMatch(). */
    private function formatMatch(UserMatch $m): array
    {
        return [
            'id'           => $m->id,
            'status'       => $m->status,
            'unmatched_by' => $m->unmatched_by,
            'unmatched_at' => $m->unmatched_at?->toIso8601String(),
            'created_at'   => $m->created_at?->toIso8601String(),
            'user1'        => $m->user1 ? AdminUserBadgeResource::nullable($m->user1) : null,
            'user2'        => $m->user2 ? AdminUserBadgeResource::nullable($m->user2) : null,
        ];
    }

    // ============================================
    // ЧАТЫ юзера
    // ============================================

    /**
     * GET /api/admin/users/{user}/chats?page=2
     *
     * Private-чаты с участием юзера. Формат 1:1 с
     * ChatController::formatChatListItem() — фронт переиспользует
     * рендер строки чата. Действия (lock/unlock) — через глобальный
     * раздел /communication/chats.
     */
    public function chats(UserListRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $avatarQuery = fn($q) => $q->select([
            'id',
            'user_id',
            'is_primary',
            'status',
            'path_thumb',
            'path_medium',
        ])
            ->orderByDesc('is_primary')
            ->limit(1);

        $chats = Chat::where('type', Chat::TYPE_PRIVATE)
            ->whereHas('participants', fn($q) => $q->where('user_id', $userId))
            ->with([
                'participants' => fn($q) => $q->with([
                    'user' => fn($uq) => $uq->withTrashed()->with(['photos' => $avatarQuery]),
                ]),
                'latestMessage' => fn($q) => $q->select([
                    'messages.id',
                    'messages.chat_id',
                    'messages.type',
                    'messages.body',
                    'messages.created_at',
                ]),
            ])
            ->latest('id')
            ->paginate($request->perPage(15), ['*'], 'page', $request->page());

        return response()->json($this->paginatorToArray($chats, fn(Chat $c) => $this->formatChatListItem($c)));
    }

    /** Копия ChatController::formatChatListItem(). */
    private function formatChatListItem(Chat $chat): array
    {
        $u1 = $chat->participants->get(0)?->user;
        $u2 = $chat->participants->get(1)?->user;
        $lastMsg = $chat->latestMessage;

        return [
            'id'              => $chat->id,
            'is_locked'       => (bool) $chat->is_locked,
            'last_message_at' => $chat->last_message_at?->toIso8601String(),
            'participants'    => [
                AdminUserBadgeResource::nullable($u1),
                AdminUserBadgeResource::nullable($u2),
            ],
            'last_message' => $lastMsg ? [
                'id'         => $lastMsg->id,
                'type'       => $lastMsg->type,
                'body'       => $lastMsg->body,
                'created_at' => $lastMsg->created_at?->toIso8601String(),
            ] : null,
        ];
    }

    // ============================================
    // ЛЕНТА СОБЫТИЙ юзера
    // ============================================

    /**
     * GET /api/admin/users/{user}/events?page=2
     *
     * user_events — лента активности (avatar_changed, birthday,
     * status_updated, photos_uploaded...). properties — jsonb.
     */
    public function events(UserListRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $events = UserEvent::query()
            ->where('user_id', $userId)
            ->latest('created_at')
            ->latest('id')
            ->paginate($request->perPage(20), ['*'], 'page', $request->page());

        return response()->json($this->paginatorToArray($events, fn(UserEvent $e) => [
            'id'         => $e->id,
            'type'       => $e->type,
            'properties' => $e->properties,
            'created_at' => $e->created_at?->toIso8601String(),
        ]));
    }

    // ============================================
    // ФИНАНСЫ: транзакции юзера (admin only)
    // ============================================

    /**
     * GET /api/admin/users/{user}/transactions?status=all&page=2
     *
     * Формат 1:1 с TransactionController::formatTransaction() (без поля
     * user — автор транзакции всегда юзер страницы, гонять его в каждом
     * ряду бессмысленно). Действия (sync/refund) — через глобальный
     * раздел /finance/transactions.
     */
    public function transactions(UserTransactionsRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $status = $request->status('all');

        $query = Transaction::query()
            ->where('user_id', $userId)
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->latest('created_at')
            ->latest('id');

        $transactions = $query->paginate($request->perPage(15), ['*'], 'page', $request->page());

        $counts = Transaction::where('user_id', $userId)
            ->selectRaw("
                COUNT(*) as all_count,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) as refunded
            ")
            ->first();

        return response()->json(
            $this->paginatorToArray($transactions, fn(Transaction $t) => $this->formatTransaction($t)) + [
                'counts' => [
                    'all'      => (int) ($counts->all_count ?? 0),
                    'success'  => (int) ($counts->success ?? 0),
                    'pending'  => (int) ($counts->pending ?? 0),
                    'failed'   => (int) ($counts->failed ?? 0),
                    'refunded' => (int) ($counts->refunded ?? 0),
                ],
                'status' => $status,
            ]
        );
    }

    /** Копия TransactionController::formatTransaction() минус поле user. */
    private function formatTransaction(Transaction $t): array
    {
        $statusBadge = $t->status_badge;

        $tier = $t->meta['tier'] ?? null;
        $typeBadge = match ($t->type) {
            'subscription' => [
                'variant'   => 'outline',
                'label'     => $tier === 'vip' ? 'VIP' : 'Premium',
                'className' => $tier === 'vip'
                    ? 'bg-sky-500/15 text-sky-600 border-sky-500/30 hover:bg-sky-500/20 dark:text-sky-400 dark:border-sky-500/40'
                    : 'bg-orange-500/15 text-orange-600 border-orange-500/30 hover:bg-orange-500/20 dark:text-orange-400 dark:border-orange-500/40',
            ],
            'credits' => [
                'variant'   => 'secondary',
                'label'     => 'Кредиты',
                'className' => '',
            ],
            'refund' => [
                'variant'   => 'destructive',
                'label'     => 'Возврат',
                'className' => '',
            ],
            default => [
                'variant'   => 'secondary',
                'label'     => $t->type,
                'className' => '',
            ],
        };

        $amountClass = match ($t->status) {
            'refunded' => 'text-destructive font-medium',
            'failed'   => 'text-muted-foreground/50',
            'pending'  => 'text-muted-foreground',
            default    => 'text-green-500 font-medium',
        };

        return [
            'id'                      => $t->id,
            'user_id'                 => $t->user_id,
            'amount'                  => (float) $t->amount,
            'formatted_amount'        => $t->formatted_amount,
            'amount_class'            => $amountClass,
            'currency'                => $t->currency,
            'type'                    => $t->type,
            'type_badge'              => $typeBadge,
            'status'                  => $t->status,
            'status_badge'            => $statusBadge,
            'provider'                => $t->provider,
            'provider_transaction_id' => $t->provider_transaction_id,
            'credits_amount'          => $t->credits_amount,
            'created_at'              => $t->created_at?->toIso8601String(),
            'updated_at'              => $t->updated_at?->toIso8601String(),
        ];
    }

    // ============================================
    // ФИНАНСЫ: подарки юзера — sent/received (admin only)
    // ============================================

    /**
     * GET /api/admin/users/{user}/gifts?sent_page=1&received_page=2
     *
     * Формат 1:1 с GiftController::formatUserGift(). Действия
     * (hide/restore) — через глобальный раздел /finance/gifts.
     */
    public function gifts(UserGiftsRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $badgeUser = $this->badgeUser();

        $with = [
            'sender'   => $badgeUser,
            'receiver' => $badgeUser,
            'gift:id,name,image_url,category,type',
        ];

        $sent = UserGift::withTrashed()
            ->with($with)
            ->where('sender_id', $userId)
            ->latest('created_at')
            ->latest('id')
            ->paginate(10, ['*'], 'sent_page', $request->sentPage());

        $received = UserGift::withTrashed()
            ->with($with)
            ->where('receiver_id', $userId)
            ->latest('created_at')
            ->latest('id')
            ->paginate(10, ['*'], 'received_page', $request->receivedPage());

        return response()->json([
            'sent'     => $this->paginatorToArray($sent, fn(UserGift $ug) => $this->formatUserGift($ug)),
            'received' => $this->paginatorToArray($received, fn(UserGift $ug) => $this->formatUserGift($ug)),
            'counts'   => [
                'sent'     => UserGift::where('sender_id', $userId)->count(),
                'received' => UserGift::where('receiver_id', $userId)->count(),
            ],
        ]);
    }

    /** Копия GiftController::formatUserGift(). */
    private function formatUserGift(UserGift $ug): array
    {
        $catEnum = $ug->gift ? GiftCategory::tryFrom($ug->gift->category ?? '') : null;

        return [
            'id'                 => $ug->id,
            'snapshot_name'      => $ug->snapshot_name,
            'snapshot_image_url' => $ug->image_url,
            'snapshot_price'     => (int) $ug->snapshot_price,
            'message'            => $ug->message,
            'is_private'         => (bool) $ug->is_private,
            'is_read'            => (bool) $ug->is_read,
            'is_hidden'          => $ug->trashed(),
            'created_at'         => $ug->created_at?->toIso8601String(),
            'sender'   => $ug->sender ? AdminUserBadgeResource::nullable($ug->sender) : null,
            'receiver' => $ug->receiver ? AdminUserBadgeResource::nullable($ug->receiver) : null,
            'gift' => $ug->gift ? [
                'id'             => $ug->gift->id,
                'name'           => $ug->gift->name,
                'image_url'      => $ug->gift->image_url,
                'type'           => $ug->gift->type,
                'category'       => $ug->gift->category,
                'category_label' => $catEnum?->label(),
                'category_color' => $catEnum?->color(),
            ] : null,
        ];
    }

    // ============================================
    // ФИНАНСЫ: платёжные карты юзера (admin only)
    // ============================================

    /**
     * GET /api/admin/users/{user}/cards?page=1
     *
     * user_cards с softDeletes (критично для фин. отчётов — удалённая
     * карта остаётся в истории). Read-only: токены карт не возвращаем —
     * только маскированные данные (last4, brand, срок).
     */
    public function cards(UserListRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $cards = UserCard::withTrashed()
            ->where('user_id', $userId)
            ->orderByDesc('is_default')
            ->latest('created_at')
            ->paginate($request->perPage(20), ['*'], 'page', $request->page());

        return response()->json($this->paginatorToArray($cards, fn(UserCard $c) => [
            'id'            => $c->id,
            'gateway'       => $c->gateway,
            'card_type'     => $c->card_type,
            'last4'         => $c->last4,
            'expiry_month'  => $c->expiry_month,
            'expiry_year'   => $c->expiry_year,
            'is_active'     => (bool) $c->is_active,
            'is_default'    => (bool) $c->is_default,
            'is_expired'    => $c->isExpired(),
            'trashed'       => $c->trashed(),
            'created_at'    => $c->created_at?->toIso8601String(),
            'deleted_at'    => $c->deleted_at?->toIso8601String(),
        ]));
    }

    // ============================================
    // ФИНАНСЫ: промокоды юзера (admin only)
    // ============================================

    /**
     * GET /api/admin/users/{user}/promocodes?page=1
     *
     * История применений промокодов (promo_code_usages).
     * applied_discount — снапшот скидки в рублях на момент применения.
     */
    public function promocodes(UserListRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $usages = PromoCodeUsage::query()
            ->where('user_id', $userId)
            ->with([
                'promoCode:id,code,discount_type,discount_value,duration_days,max_uses,used_count,expires_at,is_active',
                'transaction:id,amount,status,type,created_at',
            ])
            ->latest('created_at')
            ->latest('id')
            ->paginate($request->perPage(20), ['*'], 'page', $request->page());

        return response()->json($this->paginatorToArray($usages, fn(PromoCodeUsage $u) => [
            'id'              => $u->id,
            'applied_discount' => (float) $u->applied_discount,
            'created_at'      => $u->created_at?->toIso8601String(),
            'promo_code' => $u->promoCode ? [
                'id'             => $u->promoCode->id,
                'code'           => $u->promoCode->code,
                'discount_type'  => $u->promoCode->discount_type,
                'discount_value' => (float) $u->promoCode->discount_value,
                'duration_days'  => $u->promoCode->duration_days,
                'expires_at'     => $u->promoCode->expires_at?->toIso8601String(),
                'is_active'      => (bool) $u->promoCode->is_active,
            ] : null,
            'transaction' => $u->transaction ? [
                'id'         => $u->transaction->id,
                'amount'     => (float) $u->transaction->amount,
                'status'     => $u->transaction->status,
                'type'       => $u->transaction->type,
                'created_at' => $u->transaction->created_at?->toIso8601String(),
            ] : null,
        ]));
    }

    // ============================================
    // РАССЫЛКИ, адресованные юзеру (admin only)
    // ============================================

    /**
     * GET /api/admin/users/{user}/broadcasts?page=1
     *
     * Персональные рассылки, адресованные юзеру
     * (target_audience->user_id = userId). Массовые рассылки сюда
     * НЕ входят — их аудитория не конкретный юзер.
     */
    public function broadcasts(UserListRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $broadcasts = Broadcast::query()
            // jsonb-path запрос: target_audience = {"user_id": 42, ...}
            ->where('target_audience->user_id', $userId)
            ->with(['admin:id,name'])
            ->latest('created_at')
            ->latest('id')
            ->paginate($request->perPage(15), ['*'], 'page', $request->page());

        return response()->json($this->paginatorToArray($broadcasts, fn(Broadcast $b) => [
            'id'                => $b->id,
            'title'             => $b->title,
            'message'           => Str::limit($b->message, 60),
            'type'              => $b->type,
            'status'            => $b->status,
            'progress'          => $b->progress,
            'sent_count'        => $b->sent_count,
            'failed_count'      => $b->failed_count,
            'total_recipients'  => $b->total_recipients,
            'scheduled_at'      => $b->scheduled_at?->toIso8601String(),
            'sent_at'           => $b->sent_at?->toIso8601String(),
            'started_at'        => $b->started_at?->toIso8601String(),
            'created_at'        => $b->created_at?->toIso8601String(),
            'audience_label'    => $b->audience_label,
            'admin'             => $b->admin ? ['id' => $b->admin->id, 'name' => $b->admin->name] : null,
        ]));
    }

    // ============================================
    // ЛОГИ АДМИНОВ по юзеру (admin only)
    // ============================================

    /**
     * GET /api/admin/users/{user}/admin-logs?page=2
     *
     * ВСЕ действия админов над юзером — без фильтра по action
     * (в отличие от ban-history, где только ban-семейство).
     * Источник: AdminLog, где:
     *   - loggable_type = User AND loggable_id = userId, ИЛИ
     *   - participants (jsonb) содержит userId (например, refund
     *     транзакции юзера пишет participants: [userId]).
     */
    public function adminLogs(UserListRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $logs = AdminLog::where(function ($query) use ($userId) {
            $query->where(function ($q) use ($userId) {
                $q->where('loggable_type', User::class)
                    ->where('loggable_id', $userId);
            })
                ->orWhere(function ($q) use ($userId) {
                    $q->whereNotNull('participants')
                        ->whereRaw("participants::jsonb @> ?", [json_encode([$userId])]);
                });
        })
            ->with('admin:id,name,email')
            ->latest()
            ->paginate($request->perPage(20), ['*'], 'page', $request->page());

        return response()->json($this->paginatorToArray($logs, fn(AdminLog $log) => [
            'id'         => $log->id,
            'action'     => $log->action,
            'before'     => $log->before,
            'after'      => $log->after,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at?->toIso8601String(),
            'admin'      => $log->admin ? [
                'id'   => $log->admin->id,
                'name' => $log->admin->name,
            ] : null,
        ]));
    }

    // ============================================
    // ИСТОРИЯ ВХОДОВ юзера (admin only)
    // ============================================

    /**
     * GET /api/admin/users/{user}/auth-logs?outcome=all&page=2
     *
     * user_auth_logs юзера, включая НЕУДАЧНЫЕ попытки входа
     * (антифрод-контекст). user_agent обрезаем до 200 символов —
     * боты шлют огромные UA, в таблице они нечитаемы.
     */
    public function authLogs(UserAuthLogsRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $outcome = $request->outcome('all');

        $logs = UserAuthLog::query()
            ->where('user_id', $userId)
            ->when($outcome === 'success', fn($q) => $q->where('is_successful', true))
            ->when($outcome === 'failed', fn($q) => $q->where('is_successful', false))
            ->latest('created_at')
            ->latest('id')
            ->paginate($request->perPage(20), ['*'], 'page', $request->page());

        $counts = UserAuthLog::where('user_id', $userId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN is_successful = true THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN is_successful = false THEN 1 ELSE 0 END) as failed
            ")
            ->first();

        return response()->json(
            $this->paginatorToArray($logs, fn(UserAuthLog $l) => [
                'id'           => $l->id,
                'ip_address'   => $l->ip_address,
                'user_agent'   => Str::limit($l->user_agent, 200),
                'device_os'    => $l->device_os,
                'device_type'  => $l->device_type,
                'is_successful' => (bool) $l->is_successful,
                'created_at'   => $l->created_at?->toIso8601String(),
            ]) + [
                'counts' => [
                    'total'   => (int) ($counts->total ?? 0),
                    'success' => (int) ($counts->success ?? 0),
                    'failed'  => (int) ($counts->failed ?? 0),
                ],
                'outcome' => $outcome,
            ]
        );
    }
}
