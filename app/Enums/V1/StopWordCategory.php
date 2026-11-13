<?php

namespace App\Enums;

enum StopWordCategory: string
{
    case Mat = 'mat';
    case Scam = 'scam';
    case Prostitution = 'prostitution';
    case Drugs = 'drugs';
    case Contacts = 'contacts';

    public function label(): string
    {
        return match ($this) {
            self::Mat => 'Мат',
            self::Scam => 'Мошенничество',
            self::Prostitution => 'Проституция',
            self::Drugs => 'Наркотики',
            self::Contacts => 'Контакты/ТГ',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($case) => [
            $case->value => $case->label()
        ])->toArray();
    }
}