<?php

namespace App\Actions\Admin\Users;

use App\Models\AdminLog;
use App\Models\User;
use App\Notifications\ProfileFieldCleared;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class ManageUserProfileAction
{
    /**
     * Очистка текстового поля модератором.
     */
    public function clearField(User $user, string $field, User $admin): bool
    {
        $allowedFields = ['headline', 'bio', 'looking_for'];
        if (!in_array($field, $allowedFields)) {
            return false;
        }

        // ФИКС: Eager load связи, чтобы избежать N+1 запроса в базе!
        $user->loadMissing('profile');

        $profile = $user->profile;
        if (!$profile) {
            return false;
        }

        $oldValue = $profile->getOriginal($field);
        if (empty($oldValue)) {
            return false; // Поле уже пустое
        }

        $before = [$field => $oldValue];

        // Очищаем поле в БД
        $profile->update([$field => null]);

        $after = [
            $field => null,
            'cleared_at' => now()->toDateTimeString(),
            'context' => [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'admin_id' => $admin->id,
                'field' => $field,
                'old_value_snippet' => Str::limit($oldValue, 50)
            ]
        ];

        AdminLog::record('profile.clear_field', $user, $admin, $before, $after, participants: [$user->id]);

        // Отправляем юзеру уведомление (если он не удален)
        if (!$user->trashed()) {
            try {
                $user->notify(new ProfileFieldCleared($field));
            } catch (\Exception $e) {
                Log::error('Ошибка отправки уведомления ProfileFieldCleared: ' . $e->getMessage());
            }
        }

        return true;
    }
}
