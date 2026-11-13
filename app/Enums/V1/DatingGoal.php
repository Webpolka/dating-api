<?php

namespace App\Enums;

enum DatingGoal: string
{
    case Friends = 'friends';
    case Romantic = 'romantic';
    case Family = 'family';
    case Casual = 'casual';
    case Travel = 'travel';
 
    public function label(): string
    {
        return match ($this) {
            self::Friends => 'Поиск друзей',
            self::Romantic => 'Романтические отношения',
            self::Family => 'Создание семьи',
            self::Casual => 'Свободные отношения',
            self::Travel => 'Путешествия',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Friends => 'bg-blue-500/10 text-blue-500',
            self::Romantic => 'bg-pink-500/10 text-pink-500',
            self::Family => 'bg-green-500/10 text-green-500',
            self::Casual => 'bg-purple-500/10 text-purple-500',
            self::Travel => 'bg-yellow-500/10 text-yellow-500',
        };
    }
}