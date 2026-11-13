<?php

namespace App\Enums;

enum ReportReason: string
{
    case Spam = 'spam';
    case Porn = 'porn';
    case Scam = 'scam';
    case Insult = 'insult';
    case Minor = 'minor';
    case Fake = 'fake';

    public function label(): string
    {
        return match ($this) {
            self::Spam => 'Спам / Реклама',
            self::Porn => 'Порнография / 18+',
            self::Scam => 'Мошенничество',
            self::Insult => 'Оскорбление',
            self::Minor => 'Несовершеннолетний',
            self::Fake => 'Фейк / Чужие фото',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Spam => 'bg-secondary text-secondary-foreground',
            self::Porn => 'bg-red-500/10 text-red-500',
            self::Scam => 'bg-orange-500/10 text-orange-500',
            self::Insult => 'bg-yellow-500/10 text-yellow-500',
            self::Minor => 'bg-destructive/10 text-destructive',
            self::Fake => 'bg-purple-500/10 text-purple-500',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($case) => [
            $case->value => $case->label()
        ])->toArray();
    }
}