<?php

namespace App\Actions\Api;

use App\Models\Gift;
use App\Models\User;
use App\Models\UserGift;
use Illuminate\Support\Facades\DB;

/**
 * Отправка подарка от юзера юзеру (user-API).
 *
 * Извлечено из Api\V1\GiftController::store (S5-7).
 * Контроллер оставляет себе: route-model резолюцию получателя, валидацию
 * (SendGiftRequest), guard-проверки (self-gift, отсутствие счёта), резолюцию
 * подарка (findOrFail → 404) и формирование JSON-ответов (в т.ч. 402 при
 * недостатке кредитов — исключение из транзакции пробрасывается наверх).
 */
class SendGiftAction
{
    /**
     * Списать кредиты у отправителя и создать UserGift-запись.
     *
     * Атомарно: если кредитов недостаточно (conditional update не затронул
     * строку) — бросает \Exception, транзакция откатывается.
     *
     * @param array{gift_id: int, message?: string|null} $validated Валидированные данные запроса.
     *
     * @throws \Exception «Недостаточно кредитов для совершения покупки.»
     */
    public function execute(User $sender, User $receiver, Gift $gift, array $validated): void
    {
        DB::transaction(function () use ($sender, $receiver, $gift, $validated) {
            $updated = $sender->balance()
                ->where('credits', '>=', $gift->price)
                ->update([
                    'credits' => DB::raw('credits - ' . $gift->price)
                ]);

            if (!$updated) {
                throw new \Exception('Недостаточно кредитов для совершения покупки.');
            }

            UserGift::create([
                'sender_id'           => $sender->id,
                'receiver_id'         => $receiver->id,
                'gift_id'             => $gift->id,
                'snapshot_name'       => $gift->name,
                'snapshot_image_url'  => $gift->getRawOriginal('image_url'),
                'snapshot_price'      => $gift->price,
                'message'             => $validated['message'],
                'is_private'          => false,
                'is_read'             => false,
            ]);
        });
    }
}
