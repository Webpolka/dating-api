<?php

namespace App\Enums;

enum UserBlockReason: string
{
    case Spam = 'spam';
    case Insult = 'insult';
    case Creepy = 'creepy';
    case Scam = 'scam';
    case Other = 'other'; // Вместо null: "Просто не понравился"

    public function label(): string
    {
        return match ($this) {
            self::Spam   => 'Спам',
            self::Insult => 'Оскорбление',
            self::Creepy => 'Странный / Мутный',
            self::Scam   => 'Мошенничество',
            self::Other  => 'Другое',
        };
    }

    // Для красивого вывода в админке
    public function color(): string
    {
        return match ($this) {
            self::Spam, self::Scam => 'text-red-500 bg-red-500/10',
            self::Insult           => 'text-orange-500 bg-orange-500/10',
            self::Creepy           => 'text-purple-500 bg-purple-500/10',
            self::Other            => 'text-muted-foreground bg-muted',
        };
    }
} 