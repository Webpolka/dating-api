<?php

namespace App\Enums\V1;

enum UserEventType: string
{
    case PhotoUpdated = 'photo_updated';
    case StatusChanged = 'status_changed';
    case VipPurchased = 'vip_purchased';
    case PremiumPurchased = 'premium_purchased';
    case DiaryCreated = 'diary_created';
    case ProfileCompleted = 'profile_completed';
    case Birthday = 'birthday';
 
    /**
     * Человекочитаемый заголовок события (для вывода в UI)
     */
    public function label(): string
    {
        return match($this) {
            self::PhotoUpdated => 'Обновил главное фото профиля',
            self::StatusChanged => 'Изменил статус',
            self::VipPurchased => 'Оформил VIP-статус',
            self::PremiumPurchased => 'Активировал Premium-доступ',
            self::DiaryCreated => 'Добавил новую запись в дневник',
            self::ProfileCompleted => 'Заполнил анкету на 100%',
            self::Birthday => 'День рождения!',
        };
    }

    /**
     * Имя иконки из набора Lucide (без префикса x-lucide-)
     */
    public function icon(): string
    {
        return match($this) {
            self::PhotoUpdated => 'image',
            self::StatusChanged => 'edit',
            self::VipPurchased => 'crown',
            self::PremiumPurchased => 'star',
            self::DiaryCreated => 'book-open',
            self::ProfileCompleted => 'check-circle',
            self::Birthday => 'cake',
        };
    }

    /**
     * CSS-классы Tailwind для покраски иконки (фон + цвет)
     */
    public function color(): string
    {
        return match($this) {
            self::PhotoUpdated => 'bg-blue-500/10 text-blue-500',
            self::StatusChanged => 'bg-yellow-500/10 text-yellow-500',
            self::VipPurchased, self::PremiumPurchased => 'bg-yellow-500/10 text-yellow-500',
            self::DiaryCreated => 'bg-indigo-500/10 text-indigo-500',
            self::ProfileCompleted => 'bg-green-500/10 text-green-500',
            self::Birthday => 'bg-pink-500/10 text-pink-500',
        };
    }
}