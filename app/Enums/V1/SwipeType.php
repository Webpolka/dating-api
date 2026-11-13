<?php

namespace App\Enums\V1;

enum SwipeType: string
{
    case Like = 'like';
    case Dislike = 'dislike';
    case Superlike = 'superlike';

    public function label(): string
    {
        return match ($this) {
            self::Like => 'Лайк',
            self::Dislike => 'Дизлайк',
            self::Superlike => 'Суперлайк',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Like => 'bg-green-500/10 text-green-500',
            self::Dislike => 'bg-red-500/10 text-red-500',
            self::Superlike => 'bg-yellow-500/10 text-yellow-500',
        };
    }
} 