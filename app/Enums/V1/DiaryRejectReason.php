<?php

namespace App\Enums\V1;

/**
 * Причины отклонения дневника (поста).
 *
 * Все причины — пользовательские (модератор выбирает в UI).
 * Системных причин нет (в отличие от PhotoRejectReason и CommentRejectReason).
 */
enum DiaryRejectReason: string
{
    case Spam = 'spam';
    case Porn = 'porn';
    case Scam = 'scam';
    case Insult = 'insult';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Spam => 'Спам / Реклама',
            self::Porn => 'Порнография / 18+',
            self::Scam => 'Мошенничество',
            self::Insult => 'Оскорбления',
            self::Other => 'Другое',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Spam => 'bg-yellow-500/10 text-yellow-500',
            self::Porn => 'bg-red-500/10 text-red-500',
            self::Scam => 'bg-orange-500/10 text-orange-500',
            self::Insult => 'bg-destructive/10 text-destructive',
            self::Other => 'bg-secondary text-secondary-foreground',
        };
    }

    /**
     * Системная причина — генерируется автоматически, не модератором.
     * Для дневников таких нет — все причины пользовательские.
     */
    public function isSystem(): bool
    {
        return false;
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn($case) => [
            $case->value => $case->label()
        ])->toArray();
    }

    /**
     * Массив пользовательских значений (для валидации в контроллере).
     * Использовать ТОЛЬКО этот метод в Rule::in() — он не пропустит системные причины.
     * Для дневников все причины пользовательские, но метод оставлен для симметрии
     * с CommentRejectReason и PhotoRejectReason.
     */
    public static function userValues(): array
    {
        return collect(self::cases())
            ->filter(fn(self $case) => !$case->isSystem())
            ->map(fn(self $case) => $case->value)
            ->values()
            ->all();
    }

    /**
     * Label по строковому значению — для badge на уже отклонённых постах.
     * Fallback на само значение если reason не найден в enum.
     */
    public static function labelFor(?string $reason): string
    {
        if (!$reason) return '—';
        try {
            return self::from($reason)->label();
        } catch (\ValueError $e) {
            return $reason;
        }
    }
}
