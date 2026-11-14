<?php

namespace App\Http\Controllers\Api\V1\Admin\Main;

use App\Enums\V1\GiftCategory;
use App\Enums\V1\UserEventType;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Main\UserAuthLogsRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserBroadcastsRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserDatingRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserDiariesRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserDiaryCommentsRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserGiftsRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserListRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserPhotosRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserPhotoCommentsRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserFinancesRequest;
use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use App\Models\AdminLog;
use App\Support\V1\AdminLogMeta;
use App\Models\Album;
use App\Models\Broadcast;
use App\Models\ChatParticipant;
use App\Models\Diary;
use App\Models\DiaryComment;
use App\Models\DiaryRubric;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PromoCodeUsage;
use App\Models\Swipe;
use App\Models\Transaction;
use App\Models\UserBalance;
use App\Models\UserSubscription;
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
    // ФОТО АЛЬБОМЫ юзера (без модерации — группировка по альбомам)
    // ============================================

    /**
     * GET /api/admin/users/{user}/photos
     *
     * Composite-ответ — «фото-альбомы» юзера (зеркало того, что хотел блейд):
     * один альбом = одна карточка на фронте. Без модерации: статусы, reject_reason,
     * moderated_at, trashed НЕ отдаём (модерация живёт в глобальной очереди
     * /moderation/photos). withTrashed у альбомов — чтобы видеть удалённые
     * (на случай post-factum отчёта). Фото — только не-soft-deleted (в ленте
     * удалённые фото не показываем).
     *
     * Структура:
     *   - albums[] — список альбомов юзера, каждый с полным набором фото
     *     (is_primary DESC, position ASC — как Album::photos()). Без пагинации
     *     фото внутри альбома: один альбом = одна карточка, листать не нужно.
     *   - counts — {albums, photos_total, photos_primary, photos_intimate}.
     *
     * Фото без album_id (NULL) группируем в виртуальный «Без альбома» (id=null,
     * name='Без альбома') — не теряем их.
     *
     * Default-альбом — всегда первым (is_default=true → sort), затем по created_at.
     */
    public function photos(UserPhotosRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        // Реальные колонки photos (без accessor'ов URL — они в $appends модели).
        // Без модераторских полей (status/reject_reason/moderated_*) — таб без модерации.
        $photoColumns = [
            'id', 'user_id', 'album_id', 'type',
            'path_original', 'path_large', 'path_medium', 'path_thumb',
            'is_primary', 'is_intimate', 'position',
            'title', 'description',
            'created_at', 'updated_at',
        ];

        // Альбомы юзера (с soft-deleted — для post-factum). С жадной загрузкой фото
        // (только не-soft-deleted, primary DESC → position ASC, как Album::photos()).
        $albums = Album::withTrashed()
            ->where('user_id', $userId)
            ->with([
                'photos' => fn($q) => $q
                    ->whereNull('deleted_at')
                    ->select($photoColumns)
                    ->orderByDesc('is_primary')
                    ->orderBy('position')
                    ->orderByDesc('id'),
            ])
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        // Фото без album_id (orphan) — в виртуальный «Без альбома».
        $orphanPhotos = Photo::query()
            ->where('user_id', $userId)
            ->whereNull('album_id')
            ->whereNull('deleted_at')
            ->select($photoColumns)
            ->orderByDesc('is_primary')
            ->orderBy('position')
            ->orderByDesc('id')
            ->get();

        // Собираем composite-список альбомов.
        $albumsPayload = $albums->map(fn(Album $a) => [
            'id'           => $a->id,
            'name'         => $a->name,
            'description'  => $a->description,
            'is_default'   => (bool) $a->is_default,
            'is_private'   => (bool) $a->is_private,
            'photos_count' => (int) $a->photos_count,
            'created_at'   => $a->created_at?->toIso8601String(),
            'deleted_at'   => $a->deleted_at?->toIso8601String(),
            'photos'       => $a->photos->map(fn(Photo $p) => $this->formatAlbumPhoto($p))->values(),
        ])->values();

        // Виртуальный «Без альбома» — последней карточкой, если есть orphan-фото.
        if ($orphanPhotos->isNotEmpty()) {
            $albumsPayload[] = [
                'id'           => null,
                'name'         => 'Без альбома',
                'description'  => null,
                'is_default'   => false,
                'is_private'   => false,
                'photos_count' => $orphanPhotos->count(),
                'created_at'   => null,
                'deleted_at'   => null,
                'photos'       => $orphanPhotos->map(fn(Photo $p) => $this->formatAlbumPhoto($p))->values(),
            ];
        }

        // Общие счётчики (для шапки таба на фронте).
        $allPhotos = $albums->flatMap->photos->merge($orphanPhotos);
        $counts = [
            'albums'         => $albumsPayload->count(),
            'photos_total'   => $allPhotos->count(),
            'photos_primary' => $allPhotos->where('is_primary', true)->count(),
            'photos_intimate'=> $allPhotos->where('is_intimate', true)->count(),
        ];

        return response()->json([
            'albums' => $albumsPayload,
            'counts' => $counts,
        ]);
    }

    /**
     * Формат одной фото-записи внутри альбома (без модераторских полей).
     *
     * URL accessor'ы (thumb_url/medium_url/large_url/original_url) — из Photo::$appends,
     * вычисляются в PHP из path_* колонок. Селектить их в SQL нельзя (не колонки БД).
     */
    private function formatAlbumPhoto(Photo $p): array
    {
        return [
            'id'          => $p->id,
            'type'        => $p->type,
            'is_primary'  => (bool) $p->is_primary,
            'is_intimate' => (bool) $p->is_intimate,
            'position'    => (int) $p->position,
            'title'       => $p->title,
            'description' => $p->description,
            'created_at'  => $p->created_at?->toIso8601String(),
            'thumb_url'   => $p->thumb_url,
            'medium_url'  => $p->medium_url,
            'large_url'   => $p->large_url,
            'original_url'=> $p->original_url,
        ];
    }

    // ============================================
    // МОДЕРАЦИЯ: комментарии к фото (made / received)
    // ============================================

    /**
     * GET /api/admin/users/{user}/photo-comments?made_page=1&received_page=2
     *
     * Две независимые колонки (зеркало reports/gifts):
     *   - made     — комментарии, НАПИСАННЫЕ пользователем (photo_comments.user_id = userId).
     *                Контекст: под каким фото оставлен + владелец этого фото (photo.user).
     *   - received — комментарии под ФОТО пользователя (photo.user_id = userId), автор —
     *                любой другой юзер. Контекст: автор комментария (user) + ссылка на фото.
     *
     * По 5 на колонку (как в старом blade-табе и в reports). Фильтра статусов нет —
     * обе колонки показывают всю историю «как есть» (включая карантин = soft-deleted),
     * статус виден бейджем в каждой строке. Масс-фильтрация живёт в глобальной очереди
     * /moderation/photo-comments.
     *
     * withTrashed() на PhotoComment — чтобы видеть карантин в обеих колонках
     * (админ должен видеть, что пользователь удалял, не уходя в глобальный раздел).
     */
    public function photoComments(UserPhotoCommentsRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $badgeUser = $this->badgeUser();

        // === made: комментарии, написанные самим пользователем ===
        // Контекст фото: превью (thumb/medium) + владелец фото, под которым
        // оставлен комментарий (photo.user) — чтобы видеть, ЧЬЕ фото комментировал юзер.
        $made = PhotoComment::withTrashed()
            ->with([
                'photo' => fn($q) => $q
                    ->withTrashed()
                    ->select(self::PHOTO_CONTEXT_COLUMNS)
                    ->with(['user' => $badgeUser]),
            ])
            ->where('user_id', $userId)
            ->latest('created_at')
            ->latest('id')
            ->paginate(5, ['*'], 'made_page', $request->madePage());

        // === received: комментарии под фото пользователя ===
        // Здесь автор комментария — ДРУГОЙ юзер (модератор хочет видеть, кто спамит
        // под фото этого пользователя). Контекст: автор (user) + ссылка на фото (id).
        // photo селектим минимально (id, user_id) — превью не нужно, колонка идёт
        // под «Автор», а не «Фото» (см. blade-эталон).
        $received = PhotoComment::withTrashed()
            ->with([
                'user'   => $badgeUser,
                'photo'  => fn($q) => $q->withTrashed()->select(['id', 'user_id']),
            ])
            ->whereHas('photo', fn($q) => $q->withTrashed()->where('user_id', $userId))
            ->latest('created_at')
            ->latest('id')
            ->paginate(5, ['*'], 'received_page', $request->receivedPage());

        return response()->json([
            'made'     => $this->paginatorToArray($made, fn(PhotoComment $c) => $this->formatPhotoComment($c)),
            'received' => $this->paginatorToArray($received, fn(PhotoComment $c) => $this->formatPhotoComment($c)),
            'counts'   => [
                'made'     => $made->total(),
                'received' => $received->total(),
            ],
        ]);
    }

    /**
     * Форматтер строки комментария — общий для обеих колонок.
     *
     * Контекстные поля (photo / user) добираются через with() в самом запросе
     * в зависимости от колонки:
     *   - made     → eager-load photo.user (владелец фото, под которым коммент).
     *   - received → eager-load user (автор комментария) + photo (только id).
     *
     * Поля photo/thumb_url/medium_url — accessor'ы Photo::$appends, в SQL мы
     * селектили только path_thumb/path_medium (см. PHOTO_CONTEXT_COLUMNS),
     * поэтому accessor безопасен (колонки загружены).
     */
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
            // Автор комментария. В made-колонке это сам userId (фронт его не рисует),
            // в received — ДРУГОЙ юзер, подавший голос под нашим фото.
            'user' => $c->user ? AdminUserBadgeResource::nullable($c->user) : null,
            // Контекст фото: где оставлен комментарий. В made-колонке у photo есть
            // thumb_url/medium_url + владелец (photo.user); в received — только id/user_id
            // (превью там не рисуется, рисуется автор комментария).
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
    /**
     * GET /api/admin/users/{user}/diaries?rubric=all&search=&page=2
     *
     * Дневники, написанные пользователем (author = user).
     * Всегда withTrashed — карантин виден бейджем строки (как в старом blade-табе),
     * отдельный фильтр по статусу не нужен: статус виден в каждой строке, а
     * масс-фильтрация живёт в глобальном разделе /moderation/diary.
     *
     * В ответе отдельным полем `rubrics` — список рубрик, в которых у юзера реально
     * есть посты (для кнопок фильтра на фронте). Личные рубрики (user_id != null)
     * идут раньше глобальных — PostgreSQL по умолчанию кидает NULL в конец, что
     * некорректно для UX.
     */
    public function diaries(UserDiariesRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $rubric = $request->rubric('all');
        $search = $request->search();
        $perPage = 10;

        $operator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        $query = Diary::withTrashed()
            ->with([
                'user' => $this->badgeUser(),
                'diaryRubric',
            ])
            ->where('user_id', $userId)
            ->when($search, function ($q) use ($operator, $search) {
                // Поиск по названию (ilike на pgsql) + по id, если запрос — число.
                $q->where(function ($q) use ($operator, $search) {
                    $q->where('title', $operator, "%{$search}%");
                    if (is_numeric($search)) {
                        $q->orWhere('id', (int) $search);
                    }
                });
            })
            ->when($rubric !== 'all', function ($q) use ($rubric) {
                if ($rubric === 'none') {
                    $q->whereNull('diary_rubric_id');
                } else {
                    $q->where('diary_rubric_id', $rubric);
                }
            })
            ->latest('created_at');

        $diaries = $query->paginate($perPage, ['*'], 'page', $request->page());

        // Рубрики, в которых у юзера реально есть посты — для кнопок фильтра.
        // Личные (user_id IS NOT NULL) раньше глобальных (NULL).
        $rubrics = DiaryRubric::whereHas('diaries', fn($q) => $q->where('user_id', $userId))
            ->orderByRaw('user_id IS NULL DESC')
            ->orderBy('name')
            ->get(['id', 'user_id', 'name', 'is_active']);

        return response()->json(
            $this->paginatorToArray($diaries, fn(Diary $d) => $this->formatDiary($d)) + [
                'rubrics' => $rubrics->map(fn(DiaryRubric $r) => [
                    'id'          => $r->id,
                    'name'        => $r->name,
                    'is_active'   => (bool) $r->is_active,
                    'is_personal' => $r->user_id !== null,
                    'user_id'     => $r->user_id,
                ])->values()->toArray(),
                'rubric' => $rubric,
                'search' => $search,
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
    /**
     * GET /api/admin/users/{user}/diary-comments?made_page=1&received_page=2
     *
     * Две независимые колонки (зеркало photoComments / reports / gifts):
     *   - made     — комментарии, НАПИСАННЫЕ пользователем (diary_comments.user_id = userId).
     *                Контекст: под какой записью оставлен (diary) + автор этой записи (diary.user).
     *   - received — комментарии под ЗАПИСЯМИ пользователя (diary.user_id = userId),
     *                автор комментария — любой другой юзер. Контекст: автор (user) + запись (diary).
     *
     * По 5 на колонку (как photoComments). withTrashed() — чтобы видеть карантин.
     * Фильтра статусов нет: обе колонки показывают всю историю «как есть»,
     * статус виден бейджем в каждой строке.
     */
    public function diaryComments(UserDiaryCommentsRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $badgeUser = $this->badgeUser();

        // === made: комментарии, написанные самим пользователем ===
        // Контекст: запись (diary) + автор записи (diary.user) — чтобы видеть,
        // под чьим постом оставил комментарий юзер.
        $made = DiaryComment::withTrashed()
            ->with([
                'diary' => fn($q) => $q->withTrashed()
                    ->select(['id', 'user_id', 'title', 'status', 'deleted_at'])
                    ->with(['user' => $badgeUser]),
            ])
            ->where('user_id', $userId)
            ->latest('created_at')
            ->latest('id')
            ->paginate(5, ['*'], 'made_page', $request->madePage());

        // === received: комментарии под записями пользователя ===
        // Автор комментария — ДРУГОЙ юзер. Контекст: автор (user) + запись (diary,
        // только id/title — превью не нужно, колонка про автора).
        $received = DiaryComment::withTrashed()
            ->with([
                'user'  => $badgeUser,
                'diary' => fn($q) => $q->withTrashed()->select(['id', 'user_id', 'title', 'deleted_at']),
            ])
            ->whereHas('diary', fn($q) => $q->withTrashed()->where('user_id', $userId))
            ->latest('created_at')
            ->latest('id')
            ->paginate(5, ['*'], 'received_page', $request->receivedPage());

        return response()->json([
            'made'     => $this->paginatorToArray($made, fn(DiaryComment $c) => $this->formatDiaryComment($c)),
            'received' => $this->paginatorToArray($received, fn(DiaryComment $c) => $this->formatDiaryComment($c)),
            'counts'   => [
                'made'     => $made->total(),
                'received' => $received->total(),
            ],
        ]);
    }

    /**
     * Форматтер строки комментария — общий для обеих колонок.
     *
     * Контекстные поля (diary / user) добираются через with() в самом запросе:
     *   - made     → diary.user (автор записи, под которой коммент).
     *   - received → user (автор комментария) + diary (id/title).
     *
     * `user` (автор комментария) добавлен — в received-колонке это ДРУГОЙ юзер,
     * в made — сам userId (фронт его не рисует, но поле приходит).
     */
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
            // Автор комментария. В made-колонке это сам userId (фронт не рисует),
            // в received — ДРУГОЙ юзер, написавший под записью нашего юзера.
            'user' => $c->user ? AdminUserBadgeResource::nullable($c->user) : null,
            // Контекст: под какой записью оставлен комментарий.
            'diary' => $d ? [
                'id'      => $d->id,
                'title'   => $d->title,
                'status'  => $d->status,
                'trashed' => $d->trashed(),
                'user'    => $d->user ? AdminUserBadgeResource::nullable($d->user) : null,
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
            ->paginate(5, ['*'], 'swipes_page', $request->swipesPage());

        $matches = UserMatch::with(['user1' => $badgeUser, 'user2' => $badgeUser])
            ->where(function ($q) use ($userId) {
                $q->where('user1_id', $userId)->orWhere('user2_id', $userId);
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate(5, ['*'], 'matches_page', $request->matchesPage());

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
     * Private-чаты с участием юзера. Действия (lock/unlock, чтение сообщений) —
     * через глобальный раздел /communication/chats.
     *
     * Формат НЕ совпадает 1:1 с глобальным ChatController::formatChatListItem():
     * в per-user табе нужен «собеседник» (partner ≠ текущий юзер), а не оба
     * участника, плюс participant-поля юзера (unread/archive/mute/block) для
     * бейджей строки. Сортировка по last_read_at — как в blade-эталоне
     * (недавно читанные наверх).
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

        // ChatParticipant как точка входа — чтобы сортировать по last_read_at
        // участника (как в blade: ->latest('last_read_at')) и иметь под рукой
        // его же participant-поля (unread_count, is_hidden, ...).
        $participants = ChatParticipant::where('user_id', $userId)
            ->with([
                'chat' => fn($q) => $q->select('id', 'type', 'last_message_at', 'is_locked')
                    ->with([
                        'participants' => fn($pq) => $pq->select([
                            'id', 'chat_id', 'user_id',
                            'is_blocked', 'is_muted', 'is_hidden', 'unread_count',
                        ])->with([
                            'user' => fn($uq) => $uq->withTrashed()->with(['photos' => $avatarQuery])
                                ->select(['id', 'name', 'email', 'status', 'premium_expires_at', 'vip_expires_at', 'last_seen', 'deleted_at']),
                        ]),
                        'latestMessage' => fn($mq) => $mq->select([
                            'messages.id', 'messages.chat_id', 'messages.type',
                            'messages.body', 'messages.created_at',
                        ]),
                    ]),
            ])
            ->latest('last_read_at')
            ->latest('id')
            ->paginate($request->perPage(10), ['*'], 'page', $request->page());

        return response()->json(
            $this->paginatorToArray($participants, fn(ChatParticipant $p) => $this->formatChatParticipantRow($p, $userId))
        );
    }

    /**
     * Формат строки чата в per-user табе.
     *
     * Берём participant текущего юзера (для его unread/archive/mute/block),
     * chat (id, type, last_message_at, is_locked), собеседника (partner —
     * другой участник ≠ userId) и последнее сообщение.
     */
    private function formatChatParticipantRow(ChatParticipant $participant, int $userId): array
    {
        $chat = $participant->chat;

        // null-чаты не должны случаться ( FK), но защищаемся.
        if (!$chat) {
            return [
                'participant_id'    => $participant->id,
                'chat'              => null,
                'partner'           => null,
                'participant_state' => $this->participantState($participant),
            ];
        }

        // Собеседник — другой участник чата (user_id !== текущий юзер).
        $partnerParticipant = $chat->participants->firstWhere('user_id', '!==', $userId);
        $partner = $partnerParticipant?->user;

        $lastMsg = $chat->latestMessage;

        return [
            'participant_id'    => $participant->id,
            'chat' => [
                'id'              => $chat->id,
                'type'            => $chat->type,
                'is_locked'       => (bool) $chat->is_locked,
                'last_message_at' => $chat->last_message_at?->toIso8601String(),
                'last_message'    => $lastMsg ? [
                    'id'         => $lastMsg->id,
                    'type'       => $lastMsg->type,
                    'body'       => $lastMsg->body,
                    'created_at' => $lastMsg->created_at?->toIso8601String(),
                ] : null,
            ],
            // Собеседник (как AdminUserBadgeResource для совместимости с UserBadge).
            'partner' => $partner ? AdminUserBadgeResource::nullable($partner) : null,
            // Состояние участника-юзера для бейджей строки.
            'participant_state' => $this->participantState($participant),
        ];
    }

    /** Вынести participant-поля в отдельный блок (unread/archive/mute/block). */
    private function participantState(ChatParticipant $p): array
    {
        return [
            'unread_count' => (int) $p->unread_count,
            'is_hidden'    => (bool) $p->is_hidden,
            'is_muted'     => (bool) $p->is_muted,
            'is_blocked'   => (bool) $p->is_blocked,
            'last_read_at' => $p->last_read_at?->toIso8601String(),
        ];
    }

    // ============================================
    // ЛЕНТА СОБЫТИЙ юзера
    // ============================================

    /**
     * GET /api/admin/users/{user}/events?page=2
     *
     * user_events — лента активности (photo_updated, birthday,
     * status_changed, vip_purchased...). properties — jsonb.
     *
     * meta (label/icon/color) берётся из App\Enums\V1\UserEventType —
     * enum один источник истины (зеркало blade-эталона, который вызывал
     * UserEventType::tryFrom($type)->icon()/color()/label()). Для типов,
     * отсутствующих в enum, — fallback: иконка 'activity', нейтральный
     * цвет, заголовок из ucfirst(str_replace('_', ' ', $type)).
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
            // meta из enum — title/icon/color для таймлайна (зеркало blade-эталона).
            'meta'       => $this->eventMeta($e->type),
        ]));
    }

    /**
     * Метаданные события из UserEventType enum.
     *
     * Для неизвестных типов (нет в enum) — fallback, как в blade-эталоне:
     *   - title  → ucfirst(str_replace('_', ' ', $type))
     *   - icon   → 'activity'
     *   - color  → 'bg-primary/10 text-primary'
     */
    private function eventMeta(string $type): array
    {
        $enum = UserEventType::tryFrom($type);

        if ($enum === null) {
            return [
                'title' => ucfirst(str_replace('_', ' ', $type)),
                'icon'  => 'activity',
                'color' => 'bg-primary/10 text-primary',
            ];
        }

        return [
            'title' => $enum->label(),
            'icon'  => $enum->icon(),
            'color' => $enum->color(),
        ];
    }

    // ============================================
    // ФИНАНСЫ юзера (admin only): balance + statuses + transactions + subscriptions
    // ============================================

    /**
     * GET /api/admin/users/{user}/finances?trans_page=1&sub_page=2
     *
     * Composite-ответ — «финансовый дашборд» юзера (зеркало blade-эталона):
     *   - balance        — credits + superlikes_remaining (UserBalance, скаляр).
     *   - statuses        — active Premium/VIP (premium_expires_at, vip_expires_at,
     *                       has_active_premium, has_active_vip).
     *   - transactions    — история транзакций, по 5 на страницу.
     *   - subscriptions    — история подписок, по 5 на страницу.
     *
     * Действия (sync/refund транзакций, manage подписок) — в глобальных разделах
     * /finance/transactions и /finance/subscriptions.
     */
    public function finances(UserFinancesRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        // withTrashed — чтобы видеть баланс/статусы даже у удалённых юзеров
        // (как везде в пер-юзер табах: финансы нужны для отчётов постфактум).
        $user = User::withTrashed()
            ->with('balance')
            ->findOrFail($userId);

        // === Баланс ===
        $balance = $user->balance;
        $balancePayload = [
            'credits'              => (int) ($balance?->credits ?? 0),
            'superlikes_remaining' => (int) ($balance?->superlikes_remaining ?? 0),
            'superlikes_reset_at'  => $balance?->superlikes_reset_at?->toIso8601String(),
        ];

        // === Активные статусы (Premium / VIP) ===
        $statusesPayload = [
            'has_active_premium'  => (bool) $user->has_active_premium,
            'premium_expires_at'  => $user->premium_expires_at?->toIso8601String(),
            'has_active_vip'      => (bool) $user->has_active_vip,
            'vip_expires_at'      => $user->vip_expires_at?->toIso8601String(),
        ];

        // === Транзакции (по 5 на страницу, как в blade) ===
        $transactions = Transaction::where('user_id', $userId)
            ->latest('created_at')
            ->latest('id')
            ->paginate(5, ['*'], 'trans_page', $request->transPage());

        // === Подписки (по 5 на страницу) ===
        $subscriptions = UserSubscription::where('user_id', $userId)
            ->with(['plan:id,tier,name,price,currency,duration_days', 'transaction:id'])
            ->latest('starts_at')
            ->latest('id')
            ->paginate(5, ['*'], 'sub_page', $request->subPage());

        return response()->json([
            'balance'       => $balancePayload,
            'statuses'      => $statusesPayload,
            'transactions'  => $this->paginatorToArray($transactions, fn(Transaction $t) => $this->formatTransaction($t)),
            'subscriptions' => $this->paginatorToArray($subscriptions, fn(UserSubscription $s) => $this->formatSubscription($s)),
        ]);
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

    /**
     * Формат строки подписки (UserSubscription) в пер-юзер табе «Финансы».
     *
     * Зеркало blade-эталона: tier-бейдж (VIP/Premium), название тарифа,
     * срок (starts_at/ends_at), ссылка на чек (transaction_id), статус-бейдж
     * + дата события (created_at для active, updated_at для отменённых/истёкших).
     * status_badge — из модели UserSubscription::getStatusBadgeAttribute().
     */
    private function formatSubscription(UserSubscription $s): array
    {
        $statusBadge = $s->status_badge;
        $plan = $s->plan;

        return [
            'id'              => $s->id,
            'plan_id'         => $s->plan_id,
            'transaction_id'  => $s->transaction_id,
            'tier'            => $s->tier,
            'status'          => $s->status,
            'status_badge'    => $statusBadge,
            'is_auto_renew'   => (bool) $s->is_auto_renew,
            'starts_at'       => $s->starts_at?->toIso8601String(),
            'ends_at'         => $s->ends_at?->toIso8601String(),
            'created_at'      => $s->created_at?->toIso8601String(),
            'updated_at'      => $s->updated_at?->toIso8601String(),
            'plan' => $plan ? [
                'id'            => $plan->id,
                'tier'          => $plan->tier,
                'name'          => $plan->name,
                'price'         => (float) $plan->price,
                'currency'      => $plan->currency,
                'duration_days' => (int) $plan->duration_days,
            ] : null,
        ];
    }

    // ============================================
    // ФИНАНСЫ: подарки юзера — sent/received (admin only)
    // ============================================

    /**
     * GET /api/admin/users/{user}/gifts?filter=all&page=2
     *
     * Единый список подарков юзера с фильтром по направлению (all|sent|received).
     * В каждом ряду добавлено поле `direction` ('sent' | 'received') — фронт
     * по нему рисует стрелку направления и выбирает «counterpart» (получателя
     * для sent, отправителя для received).
     *
     * Формат айтема 1:1 с GiftController::formatUserGift() + direction.
     * Действия (hide/restore) — через глобальный раздел /finance/gifts.
     */
    public function gifts(UserGiftsRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $filter = $request->filter('all');

        $query = UserGift::withTrashed()
            ->with([
                'sender'   => $this->badgeUser(),
                'receiver' => $this->badgeUser(),
                'gift:id,name,image_url,category,type',
            ])
            ->when($filter === 'sent', fn($q) => $q->where('sender_id', $userId))
            ->when($filter === 'received', fn($q) => $q->where('receiver_id', $userId))
            ->when($filter === 'all', function ($q) use ($userId) {
                // «Все» — и отправленные, и полученные. Дедупликация не нужна:
                // UserGift не может иметь sender_id = receiver_id (валидация на запись).
                $q->where(function ($sq) use ($userId) {
                    $sq->where('sender_id', $userId)->orWhere('receiver_id', $userId);
                });
            })
            ->latest('created_at')
            ->latest('id');

        $gifts = $query->paginate(15, ['*'], 'page', $request->page());

        // Счётчики по направлениям — для бейджей на кнопках фильтра.
        $counts = [
            'all'      => UserGift::where(function ($q) use ($userId) {
                $q->where('sender_id', $userId)->orWhere('receiver_id', $userId);
            })->count(),
            'sent'     => UserGift::where('sender_id', $userId)->count(),
            'received' => UserGift::where('receiver_id', $userId)->count(),
        ];

        return response()->json(
            $this->paginatorToArray($gifts, fn(UserGift $ug) => $this->formatUserGift($ug, $userId)) + [
                'counts' => $counts,
                'filter' => $filter,
            ]
        );
    }

    /**
     * Копия GiftController::formatUserGift() + поле `direction`.
     *
     * direction = 'sent' если юзер — отправитель, иначе 'received'.
     * В режиме filter=all в одном списке могут быть оба направления, поэтому
     * фронт читает direction из каждого ряда, а не из query-параметра.
     */
    private function formatUserGift(UserGift $ug, int $userId): array
    {
        $catEnum = $ug->gift ? GiftCategory::tryFrom($ug->gift->category ?? '') : null;
        $direction = $ug->sender_id === $userId ? 'sent' : 'received';

        return [
            'id'                 => $ug->id,
            'direction'          => $direction,
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
            ->paginate(15, ['*'], 'page', $request->page());

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
            ->paginate(15, ['*'], 'page', $request->page());

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
     * GET /api/admin/users/{user}/broadcasts?filter=all&page=2
     *
     * Все рассылки, адресованные юзеру — и персональные (где в JSON
     * target_audience есть user_id = userId), и массовые (target_audience
     * пуст/null → рассылались «всем», юзер тоже получил). Фильтр по каналу:
     * all | in_app | push | email. Управление — в /system/broadcasts.
     *
     * message отдаём целиком (не Str::limit) — фронт делает line-clamp и
     * показывает полный текст в title-атрибуте (зеркало blade-эталона).
     */
    public function broadcasts(UserBroadcastsRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $filter = $request->filter('all');

        $broadcasts = Broadcast::query()
            ->where(function ($query) use ($userId) {
                // 1. Персональные рассылки (target_audience->user_id = userId).
                $query->where('target_audience->user_id', $userId)
                    // 2. Массовые рассылки для всех (target_audience не задан).
                    ->orWhere(function ($q) {
                        $q->whereNull('target_audience')
                            ->orWhere('target_audience', '[]')
                            ->orWhere('target_audience', '{}');
                    });
            })
            ->when($filter !== 'all', fn($q) => $q->where('type', $filter))
            ->with(['admin:id,name'])
            ->latest('created_at')
            ->latest('id')
            ->paginate(15, ['*'], 'page', $request->page());

        // Счётчики по каналам — для бейджей на кнопках фильтра.
        $counts = [
            'all'    => Broadcast::where(function ($query) use ($userId) {
                $query->where('target_audience->user_id', $userId)
                    ->orWhere(function ($q) {
                        $q->whereNull('target_audience')
                            ->orWhere('target_audience', '[]')
                            ->orWhere('target_audience', '{}');
                    });
            })->count(),
            'in_app' => Broadcast::where('type', Broadcast::TYPE_IN_APP)
                ->where(function ($query) use ($userId) {
                    $query->where('target_audience->user_id', $userId)
                        ->orWhere(function ($q) {
                            $q->whereNull('target_audience')
                                ->orWhere('target_audience', '[]')
                                ->orWhere('target_audience', '{}');
                        });
                })->count(),
            'email'  => Broadcast::where('type', Broadcast::TYPE_EMAIL)
                ->where(function ($query) use ($userId) {
                    $query->where('target_audience->user_id', $userId)
                        ->orWhere(function ($q) {
                            $q->whereNull('target_audience')
                                ->orWhere('target_audience', '[]')
                                ->orWhere('target_audience', '{}');
                        });
                })->count(),
            'push'   => Broadcast::where('type', Broadcast::TYPE_PUSH)
                ->where(function ($query) use ($userId) {
                    $query->where('target_audience->user_id', $userId)
                        ->orWhere(function ($q) {
                            $q->whereNull('target_audience')
                                ->orWhere('target_audience', '[]')
                                ->orWhere('target_audience', '{}');
                        });
                })->count(),
        ];

        return response()->json(
            $this->paginatorToArray($broadcasts, fn(Broadcast $b) => $this->formatBroadcast($b)) + [
                'counts' => $counts,
                'filter' => $filter,
            ]
        );
    }

    /**
     * Формат строки рассылки в per-user табе.
     *
     * message — целиком (фронт сам ограничивает через line-clamp + title).
     * progress — accessor Broadcast::getProgressAttribute() (0–100).
     * audience_label — accessor, персональная рассылка → «Юзер: …».
     */
    private function formatBroadcast(Broadcast $b): array
    {
        return [
            'id'                => $b->id,
            'title'             => $b->title,
            'message'           => $b->message,
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
        ];
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
            // Метаданные для рендера таймлайна (зеркало blade-эталона
            // \App\Support\AdminLogMeta::get($action) — title/icon/iconColor/badge).
            'meta'       => AdminLogMeta::get($log->action),
            'admin'      => $log->admin ? [
                'id'   => $log->admin->id,
                'name' => $log->admin->name,
                'email' => $log->admin->email,
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
