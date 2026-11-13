<?php

namespace App\Enums\V1;

enum BanReason: string
{
    case Spam = 'spam';
    case Scam = 'scam';
    case Prostitution = 'prostitution';
    case Minor = 'minor';
    case Insult = 'insult';
    case Drugs = 'drugs';
    case MultiAccount = 'multi_account';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Spam => 'Спам / Реклама',
            self::Scam => 'Мошенничество',
            self::Prostitution => 'Проституция',
            self::Minor => 'Несовершеннолетний',
            self::Insult => 'Оскорбление / Харм',
            self::Drugs => 'Пропаганда наркотиков',
            self::MultiAccount => 'Мультиаккаунт',
            self::Other => 'Другое',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Spam => 'bg-yellow-500/10 text-yellow-500',
            self::Scam, self::Prostitution, self::Minor, self::Drugs => 'bg-red-500/10 text-red-500',
            self::Insult => 'bg-orange-500/10 text-orange-500',
            self::MultiAccount => 'bg-purple-500/10 text-purple-500',
            self::Other => 'bg-secondary text-secondary-foreground',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn($case) => [
            $case->value => $case->label()
        ])->toArray();
    }
}
