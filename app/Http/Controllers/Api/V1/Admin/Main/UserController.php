<?php

namespace App\Http\Controllers\Admin\V1\Users;

use App\Actions\Admin\Users\DeleteUserAction;
use App\Actions\Admin\Users\ToggleUserBanAction;
use App\Actions\Admin\Users\ManageUserProfileAction;
use App\Actions\Admin\Users\ManageUserSessionsAction;
use App\Actions\Admin\Users\ManageUserBlocksAction;

use App\Http\Resources\Admin\V1\Users\UserDetailResource;
use App\Http\Resources\Admin\V1\Users\UserBadgeResource;
use App\Http\Resources\Admin\V1\Users\PhotoResource;

use App\Enums\BanReason;
use App\Enums\DeletionReason;
use App\Http\Controllers\AdminController;
use App\Http\Requests\Admin\V1\Users\BanUserRequest;
use App\Http\Requests\Admin\V1\Users\ClearProfileFieldRequest;
use App\Http\Requests\Admin\V1\Users\DeleteUserRequest;
use App\Http\Requests\Admin\V1\Users\KillUserSessionRequest;
use App\Http\Requests\Admin\V1\Users\UnblockUserBlockRequest;
use App\Http\Requests\Admin\V1\Users\UserBlocksRequest;
use App\Http\Requests\Admin\V1\Users\UserIndexRequest;
use App\Http\Requests\Admin\V1\Users\UserListRequest;
use App\Http\Requests\Admin\V1\Users\UserReportsRequest;
use App\Http\Requests\Admin\V1\Users\UserPhotosRequest;
use App\Models\User;
use App\Models\Report;
use App\Models\UserBlock;
use App\Models\Photo;
use App\Models\AdminLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Колонки таблицы `photos`, которые реально нужны для построения avatar_url
 * через accessor User::getAvatarUrlAttribute (читает $photo->thumb_url,
 * который сам вычисляется из path_thumb → path_medium → path_original).
 *
 * ВАЖНО: `thumb_url` — это accessor из $appends в Photo.php, НЕ колонка БД.
 * Раньше в нескольких местах пытались `->select(['...', 'thumb_url'])` —
 * на PostgreSQL это падало с `column "thumb_url" does not exist`.
 * Селектим только реальные колонки и оставляем accessor'у делать свою работу.
 *
 * Вынесено в константу, чтобы не дублировать список в 3 местах (index, reports, blocks).
 */


/**
 * Реальные колонки таблицы `users`, необходимые для UserBadgeResource
 * и для вычисления accessor'ов из $appends (avatar_url, is_online,
 * has_active_premium, has_active_vip).
 *
 * ВАЖНО (баг, найденный в ЧАСТИ 2.1): в селекте related-юзера в reports()
 * и blocks() раньше стояли `avatar_url` и `is_online` — это accessor'ы
 * из User::$appends, НЕ колонки БД. PostgreSQL падал с
 * `column "avatar_url" does not exist` (а за ним был бы `is_online`).
 * Тот же класс бага, что и с Photo::thumb_url в ЧАСТИ 2 — но я тогда
 * починил только photo-селект, а user-селект прозевал.
 *
 * Что осталось из селекта и почему:
 *   - id, name, email, phone, status, deleted_at, is_verified,
 *     premium_expires_at, vip_expires_at, last_seen, created_at
 *     → нужны напрямую в UserBadgeResource.
 *   - last_seen → нужен для accessor'а is_online
 *     (getIsOnlineAttribute считает «онлайн» если last_seen в пределах X минут).
 *   - premium_expires_at / vip_expires_at → нужны для has_active_premium/vip.
 *
 * Что УБРАНО и почему:
 *   - avatar_url → accessor (читает из загруженной relation 'photos').
 *   - is_online → accessor (вычисляется из last_seen).
 *   - has_active_premium / has_active_vip → accessor'ы (из *_expires_at).
 *
 * phone оставлен: сам UserBadgeResource его не выводит, но в админке
 * он бывает нужен для быстрых карточек (например, бан-диалог показывает
 * телефон). Если совсем не нужен — можно выкинуть без последствий.
 */

class UserController extends AdminController
{

    private const PHOTO_AVATAR_COLUMNS = ['id', 'user_id', 'is_primary', 'status', 'path_thumb', 'type'];

    private const USER_BADGE_COLUMNS = [
        'id',
        'name',
        'email',
        'phone',
        'status',
        'deleted_at',
        'is_verified',
        'premium_expires_at',
        'vip_expires_at',
        'last_seen',
        'created_at',
    ];


    public function index(UserIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $perPage = $validated['per_page'] ?? 20;
        $operator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $search = trim($validated['q'] ?? '');
        $sort = $validated['sort'] ?? 'desc';
        $sub = $validated['sub'] ?? null;

        $users = User::query()
            ->excludeStaff()
            ->withTrashed()
            ->with([
                'profile.city.state',
                'profile.country',
                // Используем вынесенную константу — раньше тут был `path_thumb`
                // + (в других методах) `thumb_url`, который не существует в БД.
                'photos' => fn($q) => $q
                    ->select(self::PHOTO_AVATAR_COLUMNS)
                    ->where('status', 'approved')
                    ->where('type', 'profile')
                    ->orderByDesc('is_primary')
                    ->orderBy('position')
                    ->limit(1),
            ])
            ->when($search, function ($query) use ($search, $operator) {
                $query->where(function ($q) use ($search, $operator) {
                    $q->where('name', $operator, "%{$search}%")
                        ->orWhere('email', $operator, "%{$search}%")
                        ->orWhere('phone', $operator, "%{$search}%");

                    if (ctype_digit($search)) {
                        $q->orWhere('id', (int) $search);
                    }

                    $q->orWhereHas('profile.city', fn($s) => $s->where('name', $operator, "%{$search}%"))
                        ->orWhereHas('profile.country', fn($s) => $s->where('name', $operator, "%{$search}%"));
                });
            })
            ->when($validated['status'] ?? null, fn($q, $v) => $q->where('status', $v))
            ->when($validated['gender'] ?? null, fn($q, $v) => $q->whereHas('profile', fn($s) => $s->where('gender', $v)))
            ->when(($validated['verified'] ?? '') === 'yes', fn($q) => $q->where('is_verified', true))
            ->when(($validated['verified'] ?? '') === 'no', fn($q) => $q->where('is_verified', false))
            ->when($sub === 'vip_only', fn($q) => $q->vip()
                ->where(fn($s) => $s->whereNull('premium_expires_at')->orWhere('premium_expires_at', '<=', now())))
            ->when($sub === 'premium_only', fn($q) => $q->premium()
                ->where(fn($s) => $s->whereNull('vip_expires_at')->orWhere('vip_expires_at', '<=', now())))
            ->when($sub === 'both', fn($q) => $q->vip()->premium())
            ->when($sub === 'none', fn($q) => $q
                ->where(fn($s) => $s->whereNull('vip_expires_at')->orWhere('vip_expires_at', '<=', now()))
                ->where(fn($s) => $s->whereNull('premium_expires_at')->orWhere('premium_expires_at', '<=', now())))
            ->when(ctype_digit($search), fn($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]))
            ->orderBy('created_at', $sort)
            ->orderBy('id', $sort)
            ->paginate($perPage)
            ->withQueryString();

        $users->getCollection()->transform(fn(User $user) => [
            'id'                 => $user->id,
            'name'               => $user->name,
            'email'              => $user->email,
            'phone'              => $user->phone,
            'avatar_url'         => $user->avatar_url,
            'is_online'          => $user->is_online,
            'status'             => $user->status,
            'deleted_at'         => $user->deleted_at?->toIso8601String(),
            'has_active_premium' => $user->has_active_premium,
            'has_active_vip'     => $user->has_active_vip,
            'is_verified'        => $user->is_verified,
            'created_at'         => $user->created_at?->toIso8601String(),
            'banned_until'       => $user->banned_until?->toIso8601String(),
            'ban_reason'         => $user->ban_reason,
            'gender'             => $user->profile?->gender,
            'city'               => $user->profile?->city?->name,
            'state'              => $user->profile?->city?->state?->name,
            'country'            => $user->profile?->country?->name,
            'last_seen'          => $user->last_seen?->toIso8601String(),
        ]);


        return response()->json($users);
    }

    public function stats(): JsonResponse
    {
        return response()->json(Cache::remember('admin_users_stats', 60, function () {
            $base = fn() => User::excludeStaff()->withTrashed();

            $statusCounts = $base()
                ->selectRaw('status, count(*) as aggregate')
                ->groupBy('status')
                ->pluck('aggregate', 'status');

            return [
                'total'        => $base()->count(),
                'banned'       => $statusCounts['banned'] ?? 0,
                'shadowbanned' => $statusCounts['shadowbanned'] ?? 0,
                'vip'          => $base()->vip()->count(),
                'premium'      => $base()->premium()->count(),
                'verified'     => $base()->where('is_verified', true)->count(),
            ];
        }));
    }

    public function ban(BanUserRequest $request, int $userId, ToggleUserBanAction $action): JsonResponse
    {
        // Валидация вынесена в BanUserRequest: type, reason (enum), days.
        $payload = $request->banPayload();

        $user = User::withTrashed()->lockForUpdate()->find($userId);
        if (! $user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        // Идемпотентность: если уже забанен с этим типом — ничего не делаем.
        $currentBanType = match ($user->status) {
            'banned' => $user->banned_until ? 'temp' : 'permanent',
            'shadowbanned' => 'shadow',
            default => null,
        };

        if ($currentBanType === $payload['type']) {
            return response()->json(['message' => 'Пользователь уже заблокирован этим типом бана']);
        }

        // Передаём читабельный label из енума — Action запишет его в ban_reason и в уведомление.
        $banReasonLabel = BanReason::from($payload['reason'])->label();

        $result = $action->execute(
            $user,
            $request->user(),
            $banReasonLabel,
            $payload['type'],
            forceBan: true,
            days: $payload['days'],
        );

        Cache::forget('admin_users_stats');

        return response()->json(['message' => $result['message']], $result['success'] ? 200 : 422);
    }

    public function unban(Request $request, int $userId, ToggleUserBanAction $action): JsonResponse
    {
        $user = User::withTrashed()->lockForUpdate()->find($userId);
        if (! $user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        if (! in_array($user->status, ['banned', 'shadowbanned'], true)) {
            return response()->json(['message' => 'Пользователь не заблокирован'], 422);
        }

        $result = $action->execute($user, $request->user(), 'Снят бан модератором');

        Cache::forget('admin_users_stats');

        return response()->json(['message' => $result['message']], $result['success'] ? 200 : 422);
    }

    public function destroy(DeleteUserRequest $request, int $userId, DeleteUserAction $action): JsonResponse
    {
        // Валидация вынесена в DeleteUserRequest: только reason (enum DeletionReason).

        // withTrashed() — иначе soft-deleted юзер получал 404 и проверка trashed()
        // ниже была мёртвым кодом.
        $user = User::withTrashed()->lockForUpdate()->find($userId);
        if (! $user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        if ($user->trashed()) {
            return response()->json(['message' => 'Пользователь уже деактивирован'], 422);
        }

        $deletionReasonLabel = DeletionReason::from($request->deletionReason())->label();

        $result = $action->execute($user, $request->user(), $deletionReasonLabel);

        Cache::forget('admin_users_stats');

        // Раньше контроллер игнорировал результат action и всегда отвечал 200
        // «деактивирован» (ложный успех при попытке удалить staff).
        return response()->json(['message' => $result['message']], $result['success'] ? 200 : 422);
    }

    public function restore(Request $request, int $userId, DeleteUserAction $action): JsonResponse
    {
        $user = User::withTrashed()->lockForUpdate()->find($userId);
        if (! $user || ! $user->trashed()) {
            return response()->json(['message' => 'Пользователь не найден или не удалён'], 404);
        }

        $action->restore($user, $request->user());

        Cache::forget('admin_users_stats');

        return response()->json(['message' => "Пользователь {$user->name} восстановлен"]);
    }


    public function show(int $userId): JsonResponse
    {
        $user = User::withTrashed()
            ->with([
                'profile.city.state',
                'profile.country',
                'preferences',
                'profile.interests' => fn($q) => $q->select(['interests.id', 'interests.name', 'interests.slug']),
            ])
            ->find($userId);

        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        return response()->json(new UserDetailResource($user));
    }

    public function clearProfileField(ClearProfileFieldRequest $request, int $userId, ManageUserProfileAction $action): JsonResponse
    {
        $validated = $request->validated();

        $user = User::withTrashed()->find($userId);
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        $success = $action->clearField($user, $validated['field'], $request->user());

        if (!$success) {
            return response()->json(['message' => 'Поле уже пустое или недоступно для очистки'], 422);
        }

        return response()->json(['message' => 'Поле очищено. Юзеру отправлено уведомление.']);
    }

    public function banHistory(UserListRequest $request, int $userId): JsonResponse
    {
        $user = User::withTrashed()->find($userId);
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        // ФИКС: раньше был жёсткий limit(20) без пагинации — историю длиннее
        // 20 событий посмотреть было невозможно. Теперь стандартная пагинация;
        // форма ответа {data: [...]} сохранена для обратной совместимости фронта,
        // сверху добавлены мета-поля пагинатора.
        $validated = $request->validated();

        $perPage = $validated['per_page'] ?? 20;

        $actions = ['user.ban', 'user.unban', 'user.shadowban', 'user.delete', 'user.restore', 'user.mass_ban', 'user.mass_delete'];

        // ВАЖНО: whereIn должен быть с OR participants — иначе логи по participants
        // от других сущностей (если юзер в participants, но loggable_id != userId) не попадут.        
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
            ->whereIn('action', $actions)
            ->with('admin:id,name,email')
            ->latest()
            ->paginate($perPage, ['*'], 'page', $validated['page'] ?? 1);

        return response()->json([
            'data' => $logs->getCollection()->map(fn(AdminLog $log) => [
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
            ])->values(),
            'total'        => $logs->total(),
            'current_page' => $logs->currentPage(),
            'last_page'    => $logs->lastPage(),
            'per_page'     => $logs->perPage(),
            'from'         => $logs->firstItem(),
            'to'           => $logs->lastItem(),
        ]);
    }

    public function sessions(int $userId, ManageUserSessionsAction $action): JsonResponse
    {
        $user = User::withTrashed()->find($userId);
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        return response()->json([
            'sessions' => $action->getUserSessions($user),
        ]);
    }

    public function killSession(KillUserSessionRequest $request, int $userId, ManageUserSessionsAction $action): JsonResponse
    {
        $validated = $request->validated();

        $user = User::withTrashed()->find($userId);
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        $success = $action->killSession($user, $validated['session_id'], $request->user());

        if (!$success) {
            return response()->json(['message' => 'Сессия не найдена или уже завершена'], 422);
        }

        return response()->json(['message' => 'Сессия завершена. Устройство отключено.']);
    }

    public function killAllSessions(int $userId, ManageUserSessionsAction $action, Request $request): JsonResponse
    {
        $user = User::withTrashed()->find($userId);
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        $count = $action->killAllSessions($user, $request->user());

        if ($count === 0) {
            return response()->json(['message' => 'Активных сессий не найдено.']);
        }

        return response()->json([
            'message' => "Все устройства ({$count} шт.) отключены от аккаунта.",
            'count'   => $count,
        ]);
    }


    public function reports(UserReportsRequest $request, int $userId): JsonResponse
    {
        $user = User::withTrashed()->find($userId);
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        $madePage     = $request->madePage();
        $receivedPage = $request->receivedPage();

        // Eager-load: related user с photos (для avatar_url) + admin.
        //
        // ВАЖНО (баг ЧАСТИ 2 + ЧАСТИ 2.1): раньше в селекте related-юзера
        // стояли НЕСКОЛЬКО accessor'ов из User::$appends как будто это колонки:
        //   - `avatar_url` → падало на PostgreSQL с `column "avatar_url" does not exist`
        //   - `is_online` → упало бы следующим (PostgreSQL сообщает о первой ошибке)
        // Тот же класс бага, что и с Photo::thumb_url в ЧАСТИ 2 — там я вынес
        // константу PHOTO_AVATAR_COLUMNS, а для user-селекта забыл. ЧАСТЬ 2.1
        // закрывает этот пробел константой USER_BADGE_COLUMNS.
        //
        // accessor'ы (avatar_url, is_online, has_active_premium/vip) берутся
        // из модели автоматически через $appends — селектить их в SQL НЕЛЬЗЯ.
        $userWithAvatar = fn($q) => $q
            ->withTrashed()
            ->with([
                'photos' => fn($sq) => $sq
                    ->select(self::PHOTO_AVATAR_COLUMNS)
                    ->orderByDesc('is_primary')
                    ->limit(1),
            ])
            ->select(self::USER_BADGE_COLUMNS);

        $madePaginator = Report::where('reporter_id', $userId)
            ->with(['reported' => $userWithAvatar, 'admin:id,name'])
            ->latest()
            ->paginate(5, ['*'], 'made_page', $madePage)
            ->withQueryString();

        $receivedPaginator = Report::where('reported_id', $userId)
            ->with(['reporter' => $userWithAvatar, 'admin:id,name'])
            ->latest()
            ->paginate(5, ['*'], 'received_page', $receivedPage)
            ->withQueryString();

        return response()->json([
            'made' => $this->reportPaginatorToArray($madePaginator, 'reported'),
            'received' => $this->reportPaginatorToArray($receivedPaginator, 'reporter'),
        ]);
    }

    /**
     * Универсальный конвертер Report paginator → JSON.
     *
     * @param string $userRelation 'reported' или 'reporter'
     */
    private function reportPaginatorToArray($paginator, string $userRelation): array
    {
        return [
            'data' => $paginator->getCollection()->map(fn(Report $r) => [
                'id'              => $r->id,
                'reason'          => $r->reason,
                'description'     => $r->description,
                'status'          => $r->status,
                'resolution'      => $r->resolution,
                'resolution_note' => $r->resolution_note,
                'reportable_type' => $r->reportable_type ? class_basename($r->reportable_type) : null,
                'reportable_id'   => $r->reportable_id,
                'admin'           => $r->admin ? ['id' => $r->admin->id, 'name' => $r->admin->name] : null,
                'user'            => $r->getRelation($userRelation) ? new UserBadgeResource($r->getRelation($userRelation)) : null,
                'created_at'      => $r->created_at?->toIso8601String(),
                'resolved_at'     => $r->resolved_at?->toIso8601String(),
            ])->values(),
            'total'        => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'from'         => $paginator->firstItem(),
            'to'           => $paginator->lastItem(),
        ];
    }

    public function blocks(UserBlocksRequest $request, int $userId, ManageUserBlocksAction $action): JsonResponse
    {
        $user = User::withTrashed()->find($userId);
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        $blockedPage  = $request->blockedPage();
        $blockersPage = $request->blockersPage();

        // Тот же фикс accessor'ов в user-селекте. См. подробный комментарий в reports().
        $userWithAvatar = fn($q) => $q
            ->withTrashed()
            ->with([
                'photos' => fn($sq) => $sq
                    ->select(self::PHOTO_AVATAR_COLUMNS)
                    ->orderByDesc('is_primary')
                    ->limit(1),
            ])
            ->select(self::USER_BADGE_COLUMNS);

        $blockedPaginator = UserBlock::where('blocker_id', $userId)
            ->with(['blocked' => $userWithAvatar])
            ->latest()
            ->paginate(10, ['*'], 'blocked_page', $blockedPage)
            ->withQueryString();

        $blockersPaginator = UserBlock::where('blocked_id', $userId)
            ->with(['blocker' => $userWithAvatar])
            ->latest()
            ->paginate(10, ['*'], 'blockers_page', $blockersPage)
            ->withQueryString();

        return response()->json([
            'blocked'  => $this->blockPaginatorToArray($blockedPaginator, 'blocked'),
            'blockers' => $this->blockPaginatorToArray($blockersPaginator, 'blocker'),
        ]);
    }

    public function unblockBlock(UnblockUserBlockRequest $request, int $userId, ManageUserBlocksAction $action): JsonResponse
    {
        $validated = $request->validated();

        $user = User::withTrashed()->find($userId);
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        $block = UserBlock::find($validated['block_id']);
        if (!$block) {
            return response()->json(['message' => 'Блокировка не найдена'], 404);
        }

        $isRelated = $block->blocker_id === $userId || $block->blocked_id === $userId;
        if (!$isRelated) {
            return response()->json(['message' => 'Блокировка не связана с этим пользователем'], 422);
        }

        $action->unblock($block, $request->user());

        return response()->json(['message' => 'Блокировка снята администратором.']);
    }

    /**
     * Таб «Фото» пользователя: список фото с фильтром по статусу.
     *
     * GET /api/admin/users/{user}/photos?status=pending&page=2
     *
     * Архитектура полностью зеркалит reports()/blocks():
     *   - UserPhotosRequest extends UserListRequest (валидация page + status)
     *   - 404-гард на юзера через withTrashed
     *   - Пагинация по URL-параметру 'page'
     *   - Приватный *PaginatorToArray() формирует {data, total, current_page, ...}
     *
     * Сама модерация (approve/reject/softDelete/restore/destroy/setPrimary/
     * approveAll/rejectAll) уже реализована в PhotoController + ModeratePhotoAction.
     * Этот метод ТОЛЬКО отдаёт список per-user. Действия идут через
     * POST /admin/photos/{id}/* — те же роуты, что в глобальной модерации.
     *
     * ВАЖНО про accessor'ы URL:
     *   - thumb_url / medium_url / large_url / original_url — accessor'ы из
     *     Photo::$appends (вычисляются из path_* колонок).
     *   - Их НЕЛЬЗЯ селектить в SQL — это не колонки БД (см. комментарий
     *     в PhotoResource). Селектим только реальные path_* колонки.
     *
     * ВАЖНО про 'trashed' фильтр:
     *   - status='quarantine' НЕ значит status='rejected'. Карантин = soft-deleted
     *     (deleted_at IS NOT NULL), а status при этом может быть любым
     *     (pending/approved/rejected). Зеркалит логику PhotoController::getCounts().
     *   - withTrashed() обязателен — иначе карантинные фото просто не попадут в выдачу.
     */
    public function photos(UserPhotosRequest $request, int $userId): JsonResponse
    {
        $user = User::withTrashed()->find($userId);
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        $status = $request->status();
        $page = $request->page();
        $perPage = $request->perPage(20);

        // Реальные колонки БД photos (без accessor'ов thumb_url/medium_url/...).
        // Зеркалит PHOTO_AVATAR_COLUMNS по духу, но шире — для таба нужны все
        // поля, что выводит PhotoResource.
        $photoColumns = [
            'id',
            'user_id',
            'album_id',
            'type',
            'path_original',
            'path_large',
            'path_medium',
            'path_thumb',
            'status',
            'reject_reason',
            'moderated_by',
            'moderated_at',
            'is_primary',
            'is_intimate',
            'position',
            'created_at',
            'updated_at',
            'deleted_at',
        ];

        $query = Photo::withTrashed()
            ->with(['album:id,name'])
            ->where('user_id', $userId)
            ->select($photoColumns);

        // Фильтр по статусу. Карантин — отдельный кейс (по deleted_at, не по status).
        if ($status === 'quarantine') {
            $query->whereNotNull('deleted_at');
        } else {
            $query->where('status', $status)->whereNull('deleted_at');
        }

        // is_primary DESC — аватар всегда первый в списке (важно для pending/approved).
        // created_at DESC — свежие сверху.
        $paginator = $query
            ->orderByDesc('is_primary')
            ->latest('created_at')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();

        // Счётчики по всем 4 статусам для статус-табов на фронте.
        // Один запрос COUNT(*) ... GROUP BY не подходит: нам нужны и trashed,
        // и не-trashed в одном наборе. Группируем через conditional SUM.
        // Зеркалит PhotoController::getCounts(), но scoped по user_id.
        $counts = Photo::withTrashed()
            ->where('user_id', $userId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' AND deleted_at IS NULL THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' AND deleted_at IS NULL THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' AND deleted_at IS NULL THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) as quarantine
            ")
            ->first();

        return response()->json([
            'data'   => $this->photoPaginatorToArray($paginator),
            'counts' => [
                'total'      => (int) ($counts->total ?? 0),
                'pending'    => (int) ($counts->pending ?? 0),
                'approved'   => (int) ($counts->approved ?? 0),
                'rejected'   => (int) ($counts->rejected ?? 0),
                'quarantine' => (int) ($counts->quarantine ?? 0),
            ],
            'status' => $status,
        ]);
    }

    /**
     * Универсальный конвертер Photo paginator → JSON.
     * Структура пагинатора идентична reportPaginatorToArray / blockPaginatorToArray.
     */
    private function photoPaginatorToArray($paginator): array
    {
        return [
            'data' => $paginator->getCollection()
                ->map(fn(Photo $p) => (new PhotoResource($p))->resolve())
                ->values(),
            'total'        => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'from'         => $paginator->firstItem(),
            'to'           => $paginator->lastItem(),
        ];
    }

    /**
     * Универсальный конвертер UserBlock paginator → JSON.
     *
     * @param string $userRelation 'blocked' или 'blocker'
     */
    private function blockPaginatorToArray($paginator, string $userRelation): array
    {
        return [
            'data' => $paginator->getCollection()->map(fn(UserBlock $b) => [
                'id'         => $b->id,
                'reason'     => $b->reason,
                'user'       => $b->getRelation($userRelation) ? new UserBadgeResource($b->getRelation($userRelation)) : null,
                'created_at' => $b->created_at?->toIso8601String(),
            ])->values(),
            'total'        => $paginator->total(),
            'current_page' => $paginator->currentPage(),
            'last_page'    => $paginator->lastPage(),
            'per_page'     => $paginator->perPage(),
            'from'         => $paginator->firstItem(),
            'to'           => $paginator->lastItem(),
        ];
    }
}
