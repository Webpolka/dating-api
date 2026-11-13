<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserFavorite;
use App\Models\UserBlock;
use App\Models\Report;
use App\Http\Requests\V1\ReportUserRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserActionController extends Controller
{
    // Написать сообщение (Заглушка, позже подключим чаты)
    public function chat(Request $request, User $user)
    {
        /** @var \App\Models\User $authUser */
        $authUser = $request->user();
        if (!$authUser) {
            return response()->json(['error' => 'Auth required'], 401);
        }
        
        // Здесь будет логика создания/возврата диалога
        return response()->json(['success' => true, 'message' => 'Чат скоро будет доступен!']);
    }

    // Добавить/Убрать из избранного
    public function toggleFavorite(Request $request, User $user)
    {
        /** @var \App\Models\User $authUser */
        $authUser = $request->user();
        if (!$authUser) return response()->json(['error' => 'Auth required'], 401);
        if ($authUser->id === $user->id) abort(403);

        $isFavorited = UserFavorite::isFavorite($authUser->id, $user->id);

        if ($isFavorited) {
            UserFavorite::where('user_id', $authUser->id)->where('favorite_user_id', $user->id)->delete();
            $status = false;
        } else {
            UserFavorite::create(['user_id' => $authUser->id, 'favorite_user_id' => $user->id]);
            $status = true;
        }

        return response()->json(['success' => true, 'isFavorited' => $status]);
    }

    // Заблокировать/Разблокировать
    public function toggleBlock(Request $request, User $user)
    {
        /** @var \App\Models\User $authUser */
        $authUser = $request->user();
        if (!$authUser) return response()->json(['error' => 'Auth required'], 401);
        if ($authUser->id === $user->id) abort(403);

        $isBlocked = UserBlock::isBlocked($authUser->id, $user->id);

        if ($isBlocked) {
            UserBlock::where('blocker_id', $authUser->id)->where('blocked_id', $user->id)->delete();
            $status = false;
        } else {
            $reason = $request->input('reason', 'other'); // Берем причину из запроса
            UserBlock::create(['blocker_id' => $authUser->id, 'blocked_id' => $user->id, 'reason' => $reason]);
            UserFavorite::where('user_id', $authUser->id)->where('favorite_user_id', $user->id)->delete();
            $status = true;
        }

        return response()->json(['success' => true, 'isBlocked' => $status]);
    }

    // Отправить жалобу        
    public function report(ReportUserRequest $request, User $user)
    {
        /** @var \App\Models\User $authUser */
        $authUser = $request->user();
        if (!$authUser) return response()->json(['error' => 'Auth required'], 401);
        if ($authUser->id === $user->id) abort(403);

        $validated = $request->validated();

        $existingReport = Report::where('reporter_id', $authUser->id)
            ->where('reported_id', $user->id)
            ->where('status', Report::STATUS_PENDING)
            ->exists();

        if ($existingReport) {
            return response()->json(['success' => false, 'message' => 'Вы уже отправили жалобу на этого пользователя.']);
        }

        Report::create([
            'reporter_id' => $authUser->id,
            'reported_id' => $user->id,
            'reportable_type' => User::class,
            'reportable_id' => $user->id,
            'reason' => $validated['reason'],
            'description' => $validated['description'] ?? null,
            'status' => Report::STATUS_PENDING,
        ]);

        return response()->json(['success' => true, 'message' => 'Жалоба отправлена модераторам.']);
    }
}