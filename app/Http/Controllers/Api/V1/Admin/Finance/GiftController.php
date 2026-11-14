<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Actions\V1\Admin\Finance\ManageGiftAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Finance\StoreGiftRequest;
use App\Http\Requests\Api\V1\Admin\Finance\UpdateGiftRequest;
use App\Http\Resources\Api\V1\Admin\Finance\GiftResource;
use App\Http\Resources\Api\V1\Admin\Finance\UserGiftResource;
use App\Models\Gift;
use App\Models\UserGift;
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
 * Thin-controller: вся запись делегируется ManageGiftAction; контроллер
 * резолвит модели (404), строит JSON и форматирует чтение через Resources.
 *
 * Особенность удаления подарка: если подарок уже дарили — нельзя удалить
 * физически (иначе сломается история), вместо этого деактивируется.
 * Защита от TOCTOU (DB::transaction + lockForUpdate) — внутри
 * ManageGiftAction::destroy().
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

        $gifts = Gift::query()
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
            ->when($isId, fn($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]))
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return $this->paginated($gifts, GiftResource::class, [
            'counts' => $this->getCounts(),
        ]);
    }

    public function store(StoreGiftRequest $request, ManageGiftAction $action): JsonResponse
    {
        $data = $request->validated();

        try {
            $gift = $action->store($request->user(), $data);
        } catch (QueryException $e) {
            // 23505 — PostgreSQL unique_violation (slug UNIQUE constraint).
            if ($e->getCode() === '23505') {
                return $this->message(
                    'Подарок с таким slug уже существует. Выберите другой.',
                    422,
                    ['errors' => ['slug' => ['Slug «' . $data['slug'] . '» уже занят.']]],
                );
            }
            throw $e;
        }

        return $this->message('Подарок добавлен в каталог!', 200, [
            'data' => new GiftResource($gift),
        ]);
    }

    public function update(UpdateGiftRequest $request, $id, ManageGiftAction $action): JsonResponse
    {
        $gift = Gift::find((int) $id);
        if (!$gift) return $this->notFound('Подарок');

        $gift = $action->update($gift, $request->user(), $request->validated());

        return $this->message('Подарок обновлен!', 200, [
            'data' => new GiftResource($gift),
        ]);
    }

    /**
     * Удалить подарок (или скрыть, если уже дарили).
     * Возвращает { deleted: bool, sent_count: int }.
     *
     * Резолвит подарок внутри транзакции (lockForUpdate, защита от TOCTOU) —
     * см. ManageGiftAction::destroy(). Action возвращает null если подарок
     * не найден, иначе массив ['deleted' => bool, 'sent_count' => int].
     */
    public function destroy(Request $request, $id, ManageGiftAction $action): JsonResponse
    {
        $result = $action->destroy((int) $id, $request->user());

        if ($result === null) {
            return $this->notFound('Подарок');
        }

        if ($result['deleted']) {
            return $this->message('Подарок удален из каталога', 200, [
                'deleted'    => true,
                'sent_count' => 0,
            ]);
        }

        return $this->message(
            "Подарок был отправлен {$result['sent_count']} раз. Он скрыт из продажи, но сохранен в БД.",
            200,
            [
                'deleted'    => false,
                'sent_count' => $result['sent_count'],
            ],
        );
    }

    /**
     * Скрыть/Показать подарок в каталоге (toggle is_active).
     */
    public function toggleStatus(Request $request, $id, ManageGiftAction $action): JsonResponse
    {
        $gift = Gift::find((int) $id);
        if (!$gift) return $this->notFound('Подарок');

        $gift = $action->toggleStatus($gift, $request->user());

        return $this->message(
            $gift->is_active ? 'Подарок в продаже' : 'Подарок скрыт',
            200,
            ['is_active' => $gift->is_active],
        );
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

        $userGifts = UserGift::query()
            ->withTrashed()
            ->with([
                'sender'   => fn($q) => $q->withTrashed()->withAvatar(),
                'receiver' => fn($q) => $q->withTrashed()->withAvatar(),
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
            ->when($isId, fn($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]))
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return $this->paginated($userGifts, UserGiftResource::class, [
            'counts' => $this->getCounts(),
        ]);
    }

    /**
     * Скрыть подарок из профиля юзера (soft delete).
     */
    public function hideUserGift(Request $request, $id, ManageGiftAction $action): JsonResponse
    {
        $userGift = UserGift::find((int) $id);
        if (!$userGift) return $this->notFound('Подарок');

        $action->hideUserGift($userGift, $request->user());

        return $this->message('Подарок отозван и скрыт из профиля юзера');
    }

    /**
     * Вернуть скрытый подарок в профиль юзера (restore).
     */
    public function restoreUserGift(Request $request, $id, ManageGiftAction $action): JsonResponse
    {
        $userGift = UserGift::withTrashed()->find((int) $id);
        if (!$userGift) return $this->notFound('Подарок');

        $action->restoreUserGift($userGift, $request->user());

        return $this->message('Подарок возвращен в профиль юзера');
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
                'catalog'  => $catalogCount,
                'history'  => (int) ($historyStats->total ?? 0),
                'all'      => (int) ($historyStats->total ?? 0),
                'public'   => (int) ($historyStats->public ?? 0),
                'private'  => (int) ($historyStats->private ?? 0),
            ];
        });
    }
}
