<?php

namespace App\Enums;

enum GiftCategory: string
{
    case Romantic = 'romantic';
    case Cars = 'cars';
    case Male = 'male';
    case Female = 'female';
    case Adult = '18+';
    case Fun = 'fun';

    public function label(): string
    {
        return match ($this) {
            self::Romantic => 'Романтика',
            self::Cars => 'Авто',
            self::Male => 'Для него',
            self::Female => 'Для неё',
            self::Adult => '18+',
            self::Fun => 'Приколы',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Romantic => 'bg-pink-500/10 text-pink-500 border-pink-500/20',
            self::Cars => 'bg-blue-500/10 text-blue-500 border-blue-500/20',
            self::Male => 'bg-cyan-500/10 text-cyan-500 border-cyan-500/20',
            self::Female => 'bg-fuchsia-500/10 text-fuchsia-500 border-fuchsia-500/20',
            self::Adult => 'bg-red-500/10 text-red-500 border-red-500/20',
            self::Fun => 'bg-yellow-500/10 text-yellow-500 border-yellow-500/20',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($case) => [
            $case->value => $case->label()
        ])->toArray();
    }
}