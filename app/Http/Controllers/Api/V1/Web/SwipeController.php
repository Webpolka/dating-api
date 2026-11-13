<?php

namespace App\Http\Controllers\Api\V1\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Swipe;
use App\Http\Requests\Api\V1\Web\StoreSwipeRequest;

class SwipeController extends Controller
{
    public function store(StoreSwipeRequest $request, User $user)
    {
        /** @var \App\Models\User $authUser */
        $authUser = $request->user();
        if (!$authUser) return response()->json(['error' => 'Auth required'], 401);
        if ($authUser->id === $user->id) abort(403);

        $type = $request->validated()['type'];

        // Проверяем, не свайпал ли уже
        $existing = Swipe::where('user_id', $authUser->id)->where('target_user_id', $user->id)->first();
        
        if ($existing) {
            $existing->update(['type' => $type]);
        } else {
            Swipe::create(['user_id' => $authUser->id, 'target_user_id' => $user->id, 'type' => $type]);
        }

        // Проверяем на мэтч (если это лайк, и.target тоже лайкнул нас)
        $isMatch = false;
        if ($type === 'like') {
            $reverseLike = Swipe::where('user_id', $user->id)
                ->where('target_user_id', $authUser->id)
                ->where('type', 'like')
                ->exists();
                
            if ($reverseLike) {
                $isMatch = true;
            }
        }

        return response()->json([
            'success' => true,
            'is_match' => $isMatch,
            'current_swipe' => $type
        ]);
    }
}