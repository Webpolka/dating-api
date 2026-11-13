<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Actions\V1\Admin\Finance\ManageGiftAction;
use App\Enums\V1\GiftCategory;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Finance\StoreGiftRequest;
use App\Http\Requests\Api\V1\Admin\Finance\UpdateGiftRequest;
use App\Models\Gift;
use App\Models\UserGift;
use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Подарки и монетизация.
 *
 * Две сущности:
 *  - Gift — каталог подарков (CRUD + toggle is_active)
 *  - UserGift — история дарений (просмотр + скрыть/вернуть в профиль юзера)
 *
 * Thin-controller: вся запись делегируется ManageGiftAction (S5-6);
 * контроллер резолвит модели (404), строит JSON и форматирует чтение.
 *
 * Особенность удаления подарка: если подарок уже дарили — нельзя удалить физически
 * (иначе сломается история), вместо этого он деактивируется (is_active = false).
 * Защита от TOCTOU (DB::transaction + lockForUpdate) — внутри ManageGiftAction::destroy().
 */
class GiftController extends AdminController
{
    // ============================================
    // КАТАЛОГ ПОДАРКОВ
    // ============================================

    public function index(Request $request): JsonResponse
    {
        $search          = (string) $request->string('q', '');
        $categoryFilter  = (string) $request->string('category', 'all');
        $typeFilter      = (string) $request->string('type', 'all');
        $perPage         = min(max((int) $request->integer('per_page', 15), 10), 100);
        $page            = max((int) $request->integer('page', 1), 1);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isId           = $search !== '' && ctype_digit($search);

        $query = Gift::query()
            ->when($search !== '', function ($q) use ($search, $searchOperator, $isId) {
                $q->where(function ($q) use ($search, $searchOperator, $isId) {
                    $q->where('name', $searchOperator, "%{$search}%")
                        ->orWhere('slug', $searchOperator, "%{$search}%");
                    if ($isId) {
                        $q->orWhere('id', (int) $search);
                    }
                });
            })
            ->when($categoryFilter !== 'all', fn($q) => $q->where('category', $categoryFilter))
            ->when($typeFilter !== 'all', fn($q) => $q->where('type', $typeFilter))
            ->when($isId, function ($q) use ($search) {
                $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
            })
            ->latest('created_at')
            ->latest('id');

        $gifts = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'  => $gifts->map(fn(Gift $g) => $this->formatGift($g)),
            'meta'  => [
                'current_page' => $gifts->currentPage(),
                'last_page'    => $gifts->lastPage(),
                'per_page'     => $gifts->perPage(),
                'total'        => $gifts->total(),
                'from'         => $gifts->firstItem(),
                'to'           => $gifts->lastItem(),
            ],
            'counts' => $this->getCounts(),
        ]);
    }

    public function store(StoreGiftRequest $request, ManageGiftAction $action): JsonResponse
    {
        $data = $request->validated();

        try {
            $gift = $action->store($request->user(), $data);
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                return response()->json([
                    'message' => 'Подарок с таким slug уже существует. Выберите другой.',
                    'errors'   => ['slug' => ['Slug «' . $data['slug'] . '» уже занят.']],
                ], 422);
            }
            throw $e;
        }

        return response()->json(['message' => 'Подарок добавлен в каталог!', 'data' => $this->formatGift($gift)]);
    }

    public function update(UpdateGiftRequest $request, $id, ManageGiftAction $action): JsonResponse
    {
        $gift = Gift::find((int) $id);
        if (!$gift) {
            return response()->json(['message' => 'Подарок не найден'], 404);
        }

        $gift = $action->update($gift, $request->user(), $request->validated());

        return response()->json(['message' => 'Подарок обновлен!', 'data' => $this->formatGift($gift)]);
    }

    /**
     * Удалить подарок (или скрыть, если уже дарили).
     * Возвращает { deleted: bool, sent_count: int }
     *
     * Резолвит подарок внутри транзакции (lockForUpdate, защита от TOCTOU) —
     * см. ManageGiftAction::destroy().
     */
    public function destroy(Request $request, $id, ManageGiftAction $action): JsonResponse
    {
        $result = $action->destroy((int) $id, $request->user());

        if ($result === null) {
            return response()->json(['message' => 'Подарок не найден'], 404);
        }

        if ($result['deleted']) {
            return response()->json([
                'message'    => 'Подарок удален из каталога',
                'deleted'    => true,
                'sent_count' => 0,
            ]);
        }

        return response()->json([
            'message'    => "Подарок был отправлен {$result['sent_count']} раз. Он скрыт из продажи, но сохранен в БД.",
            'deleted'    => false,
            'sent_count' => $result['sent_count'],
        ]);
    }

    /**
     * Скрыть/Показать подарок в каталоге (toggle is_active).
     */
    public function toggleStatus(Request $request, $id, ManageGiftAction $action): JsonResponse
    {
        $gift = Gift::find((int) $id);
        if (!$gift) {
            return response()->json(['message' => 'Подарок не найден'], 404);
        }

        $gift = $action->toggleStatus($gift, $request->user());

        return response()->json([
            'message'   => $gift->is_active ? 'Подарок в продаже' : 'Подарок скрыт',
            'is_active' => $gift->is_active,
        ]);
    }

    // ============================================
    // ИСТОРИЯ ДАРЕНИЙ (UserGift)
    // ============================================

    public function history(Request $request): JsonResponse
    {
        $search         = (string) $request->string('q', '');
        $privacyFilter  = (string) $request->string('privacy', 'all');
        $perPage        = min(max((int) $request->integer('per_page', 15), 10), 100);
        $page           = max((int) $request->integer('page', 1), 1);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isId           = $search !== '' && ctype_digit($search);

        $userQuery = fn($q) => $q->withTrashed()->select([
            'id',
            'name',
            'email',
            'status',
            'premium_expires_at',
            'vip_expires_at',
            'last_seen',
            'deleted_at',
            'is_verified',
        ])->with(['photos' => fn($sq) => $sq->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb'])
            ->orderByDesc('is_primary')->limit(1)]);

        $query = UserGift::query()
            ->withTrashed()
            ->with([
                'sender'   => $userQuery,
                'receiver' => $userQuery,
                'gift:id,name,image_url,category,type',
            ])
            ->when($search !== '', function ($q) use ($search, $searchOperator, $isId) {
                $q->where(function ($q) use ($search, $searchOperator, $isId) {
                    $q->whereHas('sender', fn($sq) => $sq->withTrashed()->where('name', $searchOperator, "%{$search}%"))
                        ->orWhereHas('receiver', fn($rq) => $rq->withTrashed()->where('name', $searchOperator, "%{$search}%"));
                    if ($isId) {
                        $q->orWhere('id', (int) $search);
                    }
                });
            })
            ->when($privacyFilter === 'public',  fn($q) => $q->where('is_private', false))
            ->when($privacyFilter === 'private', fn($q) => $q->where('is_private', true))
            ->when($isId, function ($q) use ($search) {
                $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
            })
            ->latest('created_at')
            ->latest('id');

        $userGifts = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'  => $userGifts->map(fn(UserGift $ug) => $this->formatUserGift($ug)),
            'meta'  => [
                'current_page' => $userGifts->currentPage(),
                'last_page'    => $userGifts->lastPage(),
                'per_page'     => $userGifts->perPage(),
                'total'        => $userGifts->total(),
                'from'         => $userGifts->firstItem(),
                'to'           => $userGifts->lastItem(),
            ],
            'counts' => $this->getCounts(),
        ]);
    }

    /**
     * Скрыть подарок из профиля юзера (soft delete).
     */
    public function hideUserGift(Request $request, $id, ManageGiftAction $action): JsonResponse
    {
        $userGift = UserGift::find((int) $id);
        if (!$userGift) {
            return response()->json(['message' => 'Подарок не найден'], 404);
        }

        $action->hideUserGift($userGift, $request->user());

        return response()->json(['message' => 'Подарок отозван и скрыт из профиля юзера']);
    }

    /**
     * Вернуть скрытый подарок в профиль юзера (restore).
     */
    public function restoreUserGift(Request $request, $id, ManageGiftAction $action): JsonResponse
    {
        $userGift = UserGift::withTrashed()->find((int) $id);
        if (!$userGift) {
            return response()->json(['message' => 'Подарок не найден'], 404);
        }

        $action->restoreUserGift($userGift, $request->user());

        return response()->json(['message' => 'Подарок возвращен в профиль юзера']);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getCounts(): array
    {
        return Cache::remember('admin_gifts_counts', 60, function () {
            $catalogCount = Gift::count();

            $historyStats = UserGift::withTrashed()
                ->selectRaw("COUNT(*) as total")
                ->selectRaw("SUM(CASE WHEN is_private = false THEN 1 ELSE 0 END) as public")
                ->selectRaw("SUM(CASE WHEN is_private = true THEN 1 ELSE 0 END) as private")
                ->first();

            return [
                'catalog' => $catalogCount,
                'history' => (int) ($historyStats->total ?? 0),
                'all'      => (int) ($historyStats->total ?? 0),
                'public'   => (int) ($historyStats->public ?? 0),
                'private'  => (int) ($historyStats->private ?? 0),
            ];
        });
    }

    private function formatGift(Gift $g): array
    {
        $catEnum = GiftCategory::tryFrom($g->category ?? '');
        return [
            'id'          => $g->id,
            'name'        => $g->name,
            'slug'        => $g->slug,
            'image_url'   => $g->image_url, // accessor отдаёт полный URL через Storage::url()
            'raw_image'   => $g->getRawOriginal('image_url'), // путь в storage (для редактирования)
            'price'       => (int) $g->price,
            'category'    => $g->category,
            'category_label' => $catEnum?->label(),
            'category_color' => $catEnum?->color(),
            'type'        => $g->type,
            'is_active'   => (bool) $g->is_active,
            'created_at'  => $g->created_at?->toIso8601String(),
            // B3: добавлен updated_at для UI "последнее обновление"
            'updated_at'  => $g->updated_at?->toIso8601String(),
        ];
    }

    private function formatUserGift(UserGift $ug): array
    {
        $catEnum = $ug->gift ? GiftCategory::tryFrom($ug->gift->category ?? '') : null;
        return [
            'id'                 => $ug->id,
            'snapshot_name'      => $ug->snapshot_name,
            'snapshot_image_url' => $ug->image_url, // accessor UserGift
            'snapshot_price'     => (int) $ug->snapshot_price,
            'message'            => $ug->message,
            'is_private'         => (bool) $ug->is_private,
            'is_read'            => (bool) $ug->is_read,
            'is_hidden'          => $ug->trashed(), // true если soft-deleted
            'created_at'         => $ug->created_at?->toIso8601String(),
            'sender'   => $ug->sender ? AdminUserBadgeResource::nullable($ug->sender) : null,
            'receiver' => $ug->receiver ? AdminUserBadgeResource::nullable($ug->receiver) : null,
            'gift' => $ug->gift ? [
                'id'              => $ug->gift->id,
                'name'            => $ug->gift->name,
                'image_url'       => $ug->gift->image_url,
                'type'            => $ug->gift->type,
                'category'        => $ug->gift->category,
                'category_label'  => $catEnum?->label(),
                'category_color'  => $catEnum?->color(),
            ] : null,
        ];
    }

}
