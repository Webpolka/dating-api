<?php

namespace App\Http\Controllers\Api\V1\Admin\Moderation;

use App\Actions\V1\Admin\Moderation\ModeratePhotoAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Moderation\RejectPhotoRequest;
use App\Http\Resources\Api\V1\Admin\Moderation\PhotoModerationCardResource;
use App\Http\Resources\Api\V1\Admin\Moderation\PhotoResource;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Модерация фотографий.
 *
 * Архитектура:
 *  - Controller: валидация, resolution модели + 404, форматирование ответа
 *    через PhotoResource / PhotoModerationCardResource.
 *  - ModeratePhotoAction: меняет статусы, диспатчит Job, пишет логи, шлёт уведомления.
 *
 * Action переиспользуемый — его же вызывают из Reports/жалоб.
 *
 * Два режима index():
 *  - queue: статус=pending без поиска → карточки юзеров с pending-фото.
 *    Группировка по юзеру (withWhereHas photos pending + withCount).
 *  - grid:  история или поиск → плоская сетка фото с фильтром по status.
 */
class PhotoController extends AdminController
{
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

        // === РЕЖИМ ОЧЕРЕДИ: карточки юзеров с pending-фото ===
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
                'data'   => PhotoModerationCardResource::collection($users->getCollection())->resolve($request),
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

        // === РЕЖИМ ИСТОРИИ: сетка фото ===
        $query = Photo::withTrashed()
            ->with([
                'user' => fn($q) => $q->withTrashed()
                    ->select(['id', 'name', 'email', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
                    ->withAvatar(),
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

        $query->when($isIdSearch, fn($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]))
            ->latest()->latest('id');

        $photos = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'mode'   => 'grid',
            'data'   => PhotoResource::collection($photos->getCollection())->resolve($request),
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
        if (!$photo) return $this->notFound('Фото');

        $action->approve($photo, $request->user());

        return $this->message('Фото одобрено. Запущена обработка...');
    }

    public function reject(RejectPhotoRequest $request, int $id, ModeratePhotoAction $action): JsonResponse
    {
        $photo = Photo::find($id);
        if (!$photo) return $this->notFound('Фото');

        $action->reject($photo, $request->user(), $request->validated()['reason']);

        return $this->message('Фото отклонено');
    }

    public function setPrimary(Request $request, int $id, ModeratePhotoAction $action): JsonResponse
    {
        $photo = Photo::find($id);
        if (!$photo) return $this->notFound('Фото');

        try {
            $result = $action->setPrimary($photo, $request->user());
        } catch (\DomainException $e) {
            return $this->message($e->getMessage(), 422);
        }

        return response()->json($result);
    }

    public function softDelete(Request $request, int $id, ModeratePhotoAction $action): JsonResponse
    {
        $photo = Photo::find($id);
        if (!$photo) return $this->notFound('Фото');

        $action->softDelete($photo, $request->user());

        return $this->message('Фото перемещено в карантин');
    }

    public function restore(Request $request, int $id, ModeratePhotoAction $action): JsonResponse
    {
        $photo = Photo::withTrashed()->find($id);
        if (!$photo) return $this->notFound('Фото');

        $action->restore($photo, $request->user());

        return $this->message('Фото восстановлено в очередь');
    }

    public function destroy(Request $request, int $id, ModeratePhotoAction $action): JsonResponse
    {
        $photo = Photo::withTrashed()->find($id);
        if (!$photo) return $this->notFound('Фото');

        $action->destroy($photo, $request->user());

        return $this->message('Фото навсегда удалено');
    }

    public function approveAllForUser(Request $request, int $userId, ModeratePhotoAction $action): JsonResponse
    {
        $user = User::find($userId);
        if (!$user) return $this->notFound('Пользователь');

        $count = $action->approveAllForUser($user, $request->user());

        return $this->message(
            $count > 0 ? "Одобрено {$count} фото" : 'Нет фото для одобрения',
            200,
            ['count' => $count],
        );
    }

    public function rejectAllForUser(Request $request, int $userId, ModeratePhotoAction $action): JsonResponse
    {
        $user = User::find($userId);
        if (!$user) return $this->notFound('Пользователь');

        $count = $action->rejectAllForUser($user, $request->user());

        return $this->message(
            $count > 0 ? "Отклонено {$count} фото" : 'Нет фото для отклонения',
            200,
            ['count' => $count],
        );
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
} 