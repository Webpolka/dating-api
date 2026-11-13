<?php

namespace App\Http\Controllers\Admin\V1\Moderation;

use App\Actions\Admin\Moderation\ModeratePhotoAction;
use App\Http\Controllers\AdminController;
use App\Http\Requests\Admin\V1\Moderation\RejectPhotoRequest;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Модерация фотографий.
 *
 * Архитектура:
 *  - Controller (HTTP-слой): валидация, форматирование ответа, форматирование данных
 *  - ModeratePhotoAction (бизнес-логика): меняет статусы, диспатчит Job, пишет логи, шлёт уведомления
 *
 * Action переиспользуемый — его же вызывают из Reports/жалоб. Логика в одном месте.
 */
class PhotoController extends AdminController
{
    // ============================================
    // СПИСОК + СЧЁТЧИКИ
    // ============================================

    public function index(Request $request): JsonResponse
    {
        $status = $request->string('status', 'pending')->toString();
        $search = $request->string('q', '')->toString();
        $perPage = $status === 'pending'
            ? min(max((int) $request->integer('per_page', 5), 1), 50)
            : min(max((int) $request->integer('per_page', 24), 1), 100);
        $page = max((int) $request->integer('page', 1), 1);

        if (!in_array($status, ['pending', 'approved', 'rejected', 'quarantine'], true)) {
            $status = 'pending';
        }

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isSearching = !empty(trim($search));
        $isIdSearch = $isSearching && ctype_digit($search);

        // Умный авто-detect статуса при поиске по ID
        if ($isIdSearch) {
            $photo = Photo::withTrashed()->find((int) $search);
            if ($photo) {
                $detectedStatus = $photo->trashed() ? 'quarantine' : $photo->status;
                if ($detectedStatus !== $status) {
                    $status = $detectedStatus;
                }
            }
        }

        // РЕЖИМ ОЧЕРЕДИ: карточки юзеров с pending-фото
        if ($status === 'pending' && !$isSearching) {
            $users = User::withTrashed()
                ->withWhereHas('photos', fn($q) => $q->where('status', 'pending')->whereNull('deleted_at'))
                ->excludeStaff()
                ->withCount(['photos as pending_photos_count' => fn($q) => $q->where('status', 'pending')->whereNull('deleted_at')])
                ->withMax(['photos as latest_pending_photo' => fn($q) => $q->where('status', 'pending')->whereNull('deleted_at')], 'created_at')
                ->with(['photos' => fn($q) => $q->where('status', 'pending')->whereNull('deleted_at')->with('album:id,name')->oldest()])
                ->orderByDesc('latest_pending_photo')
                ->paginate($perPage, ['*'], 'page', $page);

            return response()->json([
                'mode'   => 'queue',
                'data'   => $users->map(fn(User $u) => $this->formatUserCard($u)),
                'meta'   => [
                    'current_page' => $users->currentPage(),
                    'last_page'    => $users->lastPage(),
                    'per_page'     => $users->perPage(),
                    'total'        => $users->total(),
                    'from'         => $users->firstItem(),
                    'to'           => $users->lastItem(),
                ],
                'stats'  => $this->getCounts(),
                'status' => $status,
            ]);
        }

        // РЕЖИМ ИСТОРИИ: сетка фото
        $query = Photo::withTrashed()->with([
            'user' => function ($q) {
                $q->withTrashed()
                    ->select(['id', 'name', 'email', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
                    ->with(['photos' => fn($sq) => $sq->select(['id', 'user_id', 'status', 'is_primary', 'path_thumb', 'path_medium', 'path_large', 'path_original'])
                        ->orderByDesc('is_primary')->limit(1)]);
            },
            'album:id,name',
        ])
            ->whereHas('user', fn($q) => $q->withTrashed()->excludeStaff());

        if ($status === 'quarantine') {
            $query->whereNotNull('deleted_at');
        } else {
            $query->where('status', $status)->whereNull('deleted_at');
        }

        if ($isSearching) {
            $query->where(function ($q) use ($search, $searchOperator, $isIdSearch) {
                if ($isIdSearch) {
                    $q->where('id', (int) $search)->orWhere('user_id', (int) $search);
                } else {
                    $q->whereHas('user', fn($sub) => $sub->withTrashed()->where('name', $searchOperator, "%{$search}%"));
                }
            });
        }

        if ($isIdSearch) {
            $query->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
        }
        $query->latest()->latest('id');

        $photos = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'mode'   => 'grid',
            'data'   => $photos->map(fn(Photo $p) => $this->formatPhoto($p, withUser: true))->values(),
            'meta'   => [
                'current_page' => $photos->currentPage(),
                'last_page'    => $photos->lastPage(),
                'per_page'     => $photos->perPage(),
                'total'        => $photos->total(),
                'from'         => $photos->firstItem(),
                'to'           => $photos->lastItem(),
            ],
            'stats'  => $this->getCounts(),
            'status' => $status,
        ]);
    }

    public function counts(): JsonResponse
    {
        return response()->json(['stats' => $this->getCounts()]);
    }

    // ============================================
    // ДЕЙСТВИЯ МОДЕРАЦИИ — делегируют ModeratePhotoAction
    // ============================================

    public function approve(Request $request, int $id, ModeratePhotoAction $action): JsonResponse
    {
        $photo = Photo::find($id);
        if (!$photo) {
            return response()->json(['message' => 'Фото не найдено'], 404);
        }

        $action->approve($photo, $request->user());

        return response()->json(['message' => 'Фото одобрено. Запущена обработка...']);
    }

    public function reject(RejectPhotoRequest $request, int $id, ModeratePhotoAction $action): JsonResponse
    {
        // Валидируем: причина должна быть ПОЛЬЗОВАТЕЛЬСКОЙ (не системной).
        // file_missing/too_large/processing_error сюда не пройдут — их ставит только job'а.
        $validated = $request->validated();

        $photo = Photo::find($id);
        if (!$photo) {
            return response()->json(['message' => 'Фото не найдено'], 404);
        }

        $action->reject($photo, $request->user(), $validated['reason']);

        return response()->json(['message' => 'Фото отклонено']);
    }

    public function setPrimary(Request $request, int $id, ModeratePhotoAction $action): JsonResponse
    {
        $photo = Photo::find($id);
        if (!$photo) {
            return response()->json(['message' => 'Фото не найдено'], 404);
        }

        try {
            $result = $action->setPrimary($photo, $request->user());
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function softDelete(Request $request, int $id, ModeratePhotoAction $action): JsonResponse
    {
        $photo = Photo::find($id);
        if (!$photo) {
            return response()->json(['message' => 'Фото не найдено'], 404);
        }

        $action->softDelete($photo, $request->user());

        return response()->json(['message' => 'Фото перемещено в карантин']);
    }

    public function restore(Request $request, int $id, ModeratePhotoAction $action): JsonResponse
    {
        $photo = Photo::withTrashed()->find($id);
        if (!$photo) {
            return response()->json(['message' => 'Фото не найдено'], 404);
        }

        $action->restore($photo, $request->user());

        return response()->json(['message' => 'Фото восстановлено в очередь']);
    }

    public function destroy(Request $request, int $id, ModeratePhotoAction $action): JsonResponse
    {
        $photo = Photo::withTrashed()->find($id);
        if (!$photo) {
            return response()->json(['message' => 'Фото не найдено'], 404);
        }

        $action->destroy($photo, $request->user());

        return response()->json(['message' => 'Фото навсегда удалено']);
    }

    public function approveAllForUser(Request $request, int $userId, ModeratePhotoAction $action): JsonResponse
    {
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        $count = $action->approveAllForUser($user, $request->user());

        return response()->json([
            'count'   => $count,
            'message' => $count > 0 ? "Одобрено {$count} фото" : 'Нет фото для одобрения',
        ]);
    }

    public function rejectAllForUser(Request $request, int $userId, ModeratePhotoAction $action): JsonResponse
    {
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        $count = $action->rejectAllForUser($user, $request->user());

        return response()->json([
            'count'   => $count,
            'message' => $count > 0 ? "Отклонено {$count} фото" : 'Нет фото для отклонения',
        ]);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getCounts(): array
    {
        $stats = Photo::withTrashed()
            ->whereHas('user', fn($q) => $q->withTrashed()->excludeStaff())
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' AND deleted_at IS NULL THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' AND deleted_at IS NULL THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' AND deleted_at IS NULL THEN 1 ELSE 0 END) as rejected,
                SUM(CASE WHEN deleted_at IS NOT NULL THEN 1 ELSE 0 END) as quarantine
            ")->first();

        return [
            'total'      => (int) ($stats->total ?? 0),
            'pending'    => (int) ($stats->pending ?? 0),
            'approved'   => (int) ($stats->approved ?? 0),
            'rejected'   => (int) ($stats->rejected ?? 0),
            'quarantine' => (int) ($stats->quarantine ?? 0),
        ];
    }

    private function formatUserCard(User $u): array
    {
        return [
            'id'                   => $u->id,
            'name'                 => $u->name,
            'email'                => $u->email,
            'avatar_url'           => $u->avatar_url,
            'is_online'            => $u->is_online,
            'status'               => $u->status,
            'deleted_at'           => $u->deleted_at?->toIso8601String(),
            'has_active_premium'   => $u->has_active_premium,
            'has_active_vip'       => $u->has_active_vip,
            'is_verified'          => $u->is_verified,
            'pending_photos_count' => (int) $u->pending_photos_count,
            'created_at'           => $u->created_at?->toIso8601String(),
            'photos'               => $u->photos
                ->map(fn(Photo $p) => $this->formatPhoto($p, withUser: false))
                ->values()
                ->all(),
        ];
    }

    private function formatPhoto(Photo $p, bool $withUser = true): array
    {
        $data = [
            'id'            => $p->id,
            'user_id'       => $p->user_id,
            'status'        => $p->status,
            'is_primary'    => (bool) $p->is_primary,
            'is_intimate'   => (bool) $p->is_intimate,
            'type'          => $p->type,
            'reject_reason' => $p->reject_reason,
            'trashed'       => $p->trashed(),
            'moderated_at'  => $p->moderated_at?->toIso8601String(),
            'created_at'    => $p->created_at?->toIso8601String(),
            'updated_at'    => $p->updated_at?->toIso8601String(),
            'thumb_url'     => $p->thumb_url,
            'medium_url'    => $p->medium_url,
            'large_url'     => $p->large_url,
            'original_url'  => $p->original_url,
            'album'         => $p->album ? ['id' => $p->album->id, 'name' => $p->album->name] : null,
        ];

        if ($withUser) {
            if ($p->user) {
                $u = $p->user;
                $data['user'] = [
                    'id'                  => $u->id,
                    'name'                => $u->name,
                    'email'               => $u->email,
                    'avatar_url'          => $u->avatar_url,
                    'is_online'           => $u->is_online,
                    'status'              => $u->status,
                    'deleted_at'          => $u->deleted_at?->toIso8601String(),
                    'has_active_premium'  => $u->has_active_premium,
                    'has_active_vip'      => $u->has_active_vip,
                    'is_verified'         => $u->is_verified,
                ];
            } else {
                $data['user'] = null;
            }
        }

        return $data;
    }
}
