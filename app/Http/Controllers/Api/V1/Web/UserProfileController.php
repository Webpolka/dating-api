<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserFavorite;
use App\Models\UserBlock;
use App\Models\Report;
use App\Models\ProfileView;
use App\Services\ProfileViewService;
use Illuminate\Http\Request;

class UserProfileController extends Controller
{
    public function show(Request $request, string $slug, ProfileViewService $viewService)
    {
        /** @var \App\Models\User|null $authUser */
        $authUser = $request->user();

        $user = User::where('slug', $slug)
            ->with([
                'profile.city',
                'preferences',
                'photos' => function($query) {
                    $query->where('status', 'approved')->orderByDesc('is_primary')->orderBy('position');
                },
                // ============================================================
                // NEW: Интересы юзера с категорией (для multi-select в профиле)
                // Берём все интересы (модерации больше нет).
                // Сортируем по pivot.created_at (по времени добавления).
                // ============================================================
                'interests' => function($query) {
                    $query->select('interests.id', 'interests.name', 'interests.slug', 'interests.category_id')
                          ->orderByPivot('created_at', 'asc');
                },
                // Жадно грузим категории для интересов
                'interests.category:id,name,slug',
                'giftsReceived' => function($query) use ($authUser, $slug) {
                    $query->with(['sender' => function($q) {
                        $q->with(['photos' => function($q) {
                            $q->where('status', 'approved')->orderByDesc('is_primary')->limit(1);
                        }]);
                    }])->latest();

                    if (!$authUser || $authUser->slug !== $slug) {
                        $query->take(6);
                    }
                }
            ])
            ->withCount(['photos' => function($query) {
                $query->where('status', 'approved');
            }])
            ->firstOrFail();

        // FIX: используем сервис — запись идёт в Redis-буфер.
        if ($authUser && $authUser->id !== $user->id) {
            $viewService->recordViewIfNotRecent($authUser->id, $user->id);
        }

        $viewersCount = ProfileView::where('viewed_id', $user->id)
            ->where('updated_at', '>=', now()->subMinutes(5))
            ->count();

        $isFavorited = $authUser ? UserFavorite::isFavorite($authUser->id, $user->id) : false;
        $isBlocked = $authUser ? UserBlock::isBlocked($authUser->id, $user->id) : false;
        $isReported = $authUser ? Report::where('reporter_id', $authUser->id)
            ->where('reported_id', $user->id)
            ->where('status', Report::STATUS_PENDING)
            ->exists() : false;

        $giftsData = $user->giftsReceived->map(function ($gift) {
            return [
                'id' => $gift->id,
                'image_url' => $gift->image_url,
                'snapshot_name' => $gift->snapshot_name,
                'is_private' => $gift->is_private,
                'message' => $gift->message,
                'created_at' => $gift->created_at,
                'sender' => $gift->sender ? [
                    'slug' => $gift->sender->slug,
                    'name' => $gift->sender->name,
                    'avatar_url' => $gift->sender->avatar_url,
                ] : null,
            ];
        });

        $userArray = $user->toArray();
        $userArray['gifts_received'] = $giftsData;

        // ============================================================
        // NEW: Форматируем интересы — отдаём фронтенду чистый массив объектов
        // с id (нужен для multi-select), name (для chips), category (для иконки).
        // Без этого toArray() отдаст interests с pivot-данными — лишний мусор.
        // ============================================================
        // Кладём интересы ВНУТРЬ profile (как ожидает фронт)
        $userArray['profile']['interests'] = $user->interests->map(function ($interest) {
            return [
                'id'       => $interest->id,
                'name'     => $interest->name,
                'slug'     => $interest->slug,
                'category' => $interest->category ? [
                    'id'   => $interest->category->id,
                    'name' => $interest->category->name,
                    'slug' => $interest->category->slug,
                ] : null,
            ];
        })->values();

        return response()->json([
            'user' => $userArray,
            'is_favorited' => $isFavorited,
            'is_blocked' => $isBlocked,
            'is_reported' => $isReported,
            'is_auth' => (bool) $authUser,
            'viewers_count' => $viewersCount,
        ]);
    }
}