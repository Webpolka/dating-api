<?php

namespace App\Actions\Admin\Content;

use App\Models\AdminLog;
use App\Models\Gift;
use App\Models\User;
use App\Models\UserGift;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Запись в каталог подарков + история дарений (user_gifts) (thin-controller pattern).
 * Извлечена из Admin\V1\Content\GiftController (S5-6).
 *
 * Две сущности:
 *  - Gift — каталог подарков (CRUD + toggle is_active)
 *  - UserGift — история дарений (скрыть/вернуть в профиль юзера)
 *
 * КОНТРАКТ ВОЗВРАТА:
 *   - store / update / toggleStatus мутируют и возвращают модель Gift —
 *     контроллер форматирует её приватным formatGift() для 'data' в теле ответа.
 *   - destroy(int $id): ?array — сам резолвит подарок ВНУТРИ транзакции
 *     (lockForUpdate, защита от TOCTOU), возвращает null (не найден → 404 в
 *     контроллере) либо ['deleted' => bool, 'sent_count' => int].
 *   - hideUserGift / restoreUserGift — void; тело ответа фиксировано,
 *     контроллер строит его сам.
 *
 * Особенность удаления подарка: если подарок уже дарили — нельзя удалить физически
 * (иначе сломается история), вместо этого он деактивируется (is_active = false).
 *
 * Защита от TOCTOU в destroy(): между count() и delete() кто-то может подарить
 * подарок — нарушится история. Поэтому DB::transaction + lockForUpdate(gift).
 *
 * Логирование: AdminLog с action'ами gift.create / gift.update / gift.deactivate /
 * gift.delete / gift.toggle_status / user_gift.hide / user_gift.restore — форматы
 * before/after как в исходном контроллере.
 */
class ManageGiftAction
{
    // ============================================
    // КАТАЛОГ ПОДАРКОВ
    // ============================================

    /**
     * Создать подарок в каталоге.
     *
     * @param  User  $admin     Кто создал.
     * @param  array $validated Данные StoreGiftRequest.
     * @return Gift Созданная модель (для formatGift в ответе).
     *
     * @throws \Illuminate\Database\QueryException Пробрасывает нарушение уникальности slug
     *         (код 23505) — контроллер мапит его в 422.
     */
    public function store(User $admin, array $validated): Gift
    {
        $data = $validated;

        // Для услуг категория не нужна — обнуляем
        if ($data['type'] === 'services') {
            $data['category'] = null;
        }

        $gift = Gift::create([
            'name'      => $data['name'],
            'slug'      => $data['slug'],
            'image_url' => $data['image_url'],
            'price'     => $data['price'],
            'category'  => $data['category'],
            'type'      => $data['type'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        AdminLog::record('gift.create', $gift, $admin, null, [
            'status'  => 'created',
            'context' => [
                'gift_id'   => $gift->id,
                'name'      => $gift->name,
                'price'     => $gift->price,
                'is_active' => $gift->is_active,
            ],
        ]);

        $this->clearCache();

        return $gift;
    }

    /**
     * Обновить подарок каталога.
     *
     * @param  Gift  $gift      Найдена в контроллере (404 там же).
     * @param  User  $admin     Кто изменил.
     * @param  array $validated Данные UpdateGiftRequest.
     * @return Gift Обновленная модель (для formatGift в ответе).
     */
    public function update(Gift $gift, User $admin, array $validated): Gift
    {
        $data = $validated;

        // Для услуг категория не нужна — обнуляем
        if ($data['type'] === 'services') {
            $data['category'] = null;
        }

        $before = $gift->only(['name', 'slug', 'image_url', 'price', 'category', 'type', 'is_active']);

        $gift->update([
            'name'      => $data['name'],
            'slug'      => $data['slug'],
            'image_url' => $data['image_url'],
            'price'     => $data['price'],
            'category'  => $data['category'],
            'type'      => $data['type'],
            'is_active' => $data['is_active'] ?? $gift->is_active,
        ]);

        AdminLog::record('gift.update', $gift, $admin, $before, [
            'name'      => $gift->name,
            'slug'      => $gift->slug,
            'price'     => $gift->price,
            'is_active' => $gift->is_active,
            'context'   => [
                'gift_id' => $gift->id,
                'name'    => $gift->name,
            ],
        ]);

        $this->clearCache();

        return $gift;
    }

    /**
     * Удалить подарок (или скрыть, если уже дарили).
     *
     * Резолвит подарок внутри транзакции: lockForUpdate делает подсчёт UserGift
     * и удаление атомарными (защита от TOCTOU — см. классовый докблок).
     *
     * @param  int  $id    ID подарка.
     * @param  User $admin Кто удалил.
     * @return array|null null — подарок не найден (контроллер вернёт 404);
     *                    иначе ['deleted' => bool, 'sent_count' => int].
     */
    public function destroy(int $id, User $admin): ?array
    {
        return DB::transaction(function () use ($id, $admin) {
            $gift = Gift::lockForUpdate()->find($id);
            if (!$gift) {
                return null;
            }

            // Подсчёт внутри транзакции — теперь атомарен с lockForUpdate.
            $sentCount = UserGift::where('gift_id', $gift->id)->count();

            // Если уже дарили — нельзя удалять физически (сломается история), деактивируем
            if ($sentCount > 0) {
                $before = ['is_active' => $gift->getOriginal('is_active')];
                $gift->update(['is_active' => false]);

                AdminLog::record('gift.deactivate', $gift, $admin, $before, [
                    'is_active'       => false,
                    'deactivated_by' => $admin->id,
                    'context'         => [
                        'gift_id'    => $gift->id,
                        'name'        => $gift->name,
                        'sent_count' => $sentCount,
                    ],
                ]);
                $this->clearCache();

                return ['deleted' => false, 'sent_count' => $sentCount];
            }

            // Никто не дарил — удаляем физически
            $after = [
                'status'      => 'destroyed',
                'deleted_by'  => $admin->id,
                'context'     => [
                    'gift_id' => $gift->id,
                    'name'     => $gift->name,
                ],
            ];

            AdminLog::record('gift.delete', $gift, $admin, null, $after);
            $gift->delete();
            $this->clearCache();

            return ['deleted' => true, 'sent_count' => 0];
        });
    }

    /**
     * Скрыть/Показать подарок в каталоге (toggle is_active).
     *
     * @param  Gift $gift Найдена в контроллере (404 там же).
     * @param  User $admin  Кто переключил.
     * @return Gift Модель с новым is_active (для тела ответа).
     */
    public function toggleStatus(Gift $gift, User $admin): Gift
    {
        $before = ['is_active' => $gift->getOriginal('is_active')];
        $gift->update(['is_active' => !$gift->is_active]);

        AdminLog::record('gift.toggle_status', $gift, $admin, $before, [
            'is_active'   => $gift->is_active,
            'toggled_by'  => $admin->id,
            'context'     => [
                'gift_id' => $gift->id,
                'name'     => $gift->name,
            ],
        ]);

        $this->clearCache();

        return $gift;
    }

    // ============================================
    // ИСТОРИЯ ДАРЕНИЙ (UserGift)
    // ============================================

    /**
     * Скрыть подарок из профиля юзера (soft delete).
     *
     * @param  UserGift $userGift Найдена в контроллере (404 там же).
     * @param  User     $admin    Кто скрыл.
     * @return void
     */
    public function hideUserGift(UserGift $userGift, User $admin): void
    {
        $before = ['deleted_at' => $userGift->getOriginal('deleted_at')];
        $userGift->delete();

        $participants = array_filter([$userGift->sender_id, $userGift->receiver_id]);

        AdminLog::record('user_gift.hide', $userGift, $admin, $before, [
            'deleted_at' => now()->toDateTimeString(),
            'hidden_by'  => $admin->id,
            'context'    => [
                'user_gift_id'    => $userGift->id,
                'gift_id'         => $userGift->gift_id,
                'sender_id'       => $userGift->sender_id,
                'receiver_id'     => $userGift->receiver_id,
                'snapshot_name'   => $userGift->snapshot_name,
            ],
        ], participants: $participants);

        $this->clearCache();
    }

    /**
     * Вернуть скрытый подарок в профиль юзера (restore).
     *
     * @param  UserGift $userGift Найдена в контроллере withTrashed (404 там же).
     * @param  User     $admin    Кто вернул.
     * @return void
     */
    public function restoreUserGift(UserGift $userGift, User $admin): void
    {
        $before = ['deleted_at' => $userGift->getOriginal('deleted_at')];
        $userGift->restore();

        $participants = array_filter([$userGift->sender_id, $userGift->receiver_id]);

        AdminLog::record('user_gift.restore', $userGift, $admin, $before, [
            'deleted_at'   => null,
            'restored_by'  => $admin->id,
            'context'      => [
                'user_gift_id'  => $userGift->id,
                'gift_id'        => $userGift->gift_id,
                'sender_id'      => $userGift->sender_id,
                'receiver_id'    => $userGift->receiver_id,
                'snapshot_name'  => $userGift->snapshot_name,
            ],
        ], participants: $participants);

        $this->clearCache();
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function clearCache(): void
    {
        Cache::forget('admin_gifts_counts');
    }
}
