<?php

namespace App\Enums;

enum FraudAlertStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case FalsePositive = 'false_positive';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Открыт',
            self::Resolved => 'Подтвержден',
            self::FalsePositive => 'Ложняк',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Resolved => 'success',
            self::FalsePositive => 'secondary',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($case) => [
            $case->value => $case->label()
        ])->toArray();
    }
}