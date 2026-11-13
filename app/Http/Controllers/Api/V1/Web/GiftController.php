<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Api\SendGiftAction;
use App\Http\Controllers\Controller;
use App\Models\Gift;
use App\Models\User;
use App\Http\Requests\V1\SendGiftRequest;
use Illuminate\Http\Request;

class GiftController extends Controller
{
    // Получить каталог подарков
    public function index()
    {
        $gifts = Gift::where('is_active', true)
            ->get()
            ->groupBy('type');

        return response()->json(['gifts' => $gifts]);
    }

    // Отправить подарок
    public function store(SendGiftRequest $request, User $user, SendGiftAction $action)
    {
        $validated = $request->validated();

        $sender = $request->user();
        $receiver = $user;

        if ($sender->id === $receiver->id) {
            return response()->json(['success' => false, 'message' => 'Нельзя дарить подарки себе'], 400);
        }

        $sender->load('balance');
        
        if (!$sender->balance) {
            return response()->json([
                'success' => false, 
                'message' => 'У вас нет счета. Пополните баланс.', 
            ], 403);
        }

        $gift = Gift::findOrFail($validated['gift_id']);

        try {
            // Списание кредитов + UserGift-запись (транзакция) — в Action.
            // \Exception из транзакции (недостаток кредитов) пробрасывается сюда.
            $action->execute($sender, $receiver, $gift, $validated);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false, 
                'message' => $e->getMessage() ?: 'Ошибка при отправке подарка',
            ], 402);
        }

        return response()->json([
            'success' => true, 
            'message' => 'Подарок успешно отправлен!'
        ]);
    }

       /**
     * Удалить подарок из коллекции получателя.
     *
     * Endpoint: DELETE /api/gifts/{giftId}
     *
     * Бизнес-логика:
     *   - Удаление — ЛИЧНОЕ ДЕЙСТВИЕ получателя.
     *   - Отправитель НЕ получает уведомления.
     *   - В user_events НЕ создаём запись (это не событие для ленты).
     *   - Просто удаляем запись из user_gifts.
     *
     * 404 если:
     *   - подарок не существует
     *   - подарок принадлежит другому пользователю (не $user)
     */
    public function destroy(Request $request, int $giftId)
    {
        $user = $request->user();

        // Ищем подарок СРЕДИ подарков, полученных текущим юзером.
        // belongsTo проверка — через связь receivedGifts() в User.
        $userGift = $user->giftsReceived()->where('id', $giftId)->first();

        if (!$userGift) {
            return response()->json([
                'success' => false,
                'message' => 'Подарок не найден или не принадлежит вам.',
            ], 404);
        }

        // Удаляем без уведомлений и user_events.
        $userGift->delete();

        return response()->json([
            'success' => true,
            'message' => 'Подарок удалён из вашей коллекции.',
        ]);
    }
}