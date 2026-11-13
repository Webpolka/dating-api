<?php

namespace App\Enums;

enum StopWordAction: string
{
    case Mask = 'mask';
    case Reject = 'reject';
    case Alert = 'alert';

    public function label(): string
    {
        return match ($this) {
            self::Mask => 'Маскировать',
            self::Reject => 'Блокировать',
            self::Alert => 'Тревога (Антифрод)',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($case) => [
            $case->value => $case->label()
        ])->toArray();
    }
}