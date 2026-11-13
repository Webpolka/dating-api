<?php

namespace App\Enums\V1;

enum ReportResolution: string
{
    case Ban = 'ban';
    case TempBan = 'temp_ban'; // Временный бан
    case Shadowban = 'shadowban';
    case Warn = 'warn';
    case PhotoDeleted = 'photo_deleted';
    case NoAction = 'no_action';

    public function label(): string
    {
        return match ($this) {
            self::Ban => 'Вечный бан',
            self::TempBan => 'Временный бан',
            self::Shadowban => 'Теневой бан',
            self::Warn => 'Предупреждение',
            self::PhotoDeleted => 'Фото удалено',
            self::NoAction => 'Нет нарушения',
        };
    }
 
    public function color(): string
    {
        return match ($this) {
            self::Ban, self::PhotoDeleted => 'bg-red-500/10 text-red-500',
            self::TempBan => 'bg-orange-500/10 text-orange-500',
            self::Shadowban => 'bg-purple-500/10 text-purple-500',
            self::Warn => 'bg-yellow-500/10 text-yellow-500',
            self::NoAction => 'bg-secondary text-secondary-foreground',
        };
    }
}