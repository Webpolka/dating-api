<?php

namespace App\Enums;

enum MatchStatus: string
{
    case Active = 'active';
    case Unmatched = 'unmatched';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Активен',
            self::Unmatched => 'Разорван',
        };
    }
 
    public function color(): string
    {
        return match ($this) {
            self::Active => 'bg-green-500/10 text-green-500',
            self::Unmatched => 'bg-red-500/10 text-red-500',
        };
    }
}