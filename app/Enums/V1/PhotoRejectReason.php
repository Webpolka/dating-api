<?php

namespace App\Enums;

/**
 * Причины отклонения фото.
 *
 * Стратегия (Вариант A — симметрично с CommentRejectReason):
 *   - Пользовательские причины (isSystem() === false) — модератор выбирает в dropdown.
 *   - Системные (isSystem() === true) — ставятся только бэкендом (job'ой), в UI не видны как опция.
 *   - mass_reject убран — bulk-операции ставят 'other', контекст bulk хранится в admin_logs.action.
 *   - report_violation и user_banned убраны — не были в enum, но записывались в БД.
 *     ReportController и ToggleUserBanAction теперь используют 'other'.
 */
enum PhotoRejectReason: string
{
    // === Пользовательские причины (модератор выбирает в UI) ===
    case Porn = 'porn';
    case Minor = 'minor';
    case Ad = 'ad';
    case Stolen = 'stolen';
    case LowQuality = 'low_quality';
    case Other = 'other';

        // === Системные причины (генерируются ProcessApprovedPhoto job'ой) ===
    case FileMissing = 'file_missing';
    case TooLarge = 'too_large';
    case ProcessingError = 'processing_error';

    public function label(): string
    {
        return match ($this) {
            self::Porn            => 'Порнография',
            self::Minor           => 'Несовершеннолетний',
            self::Ad              => 'Реклама / Контакты',
            self::Stolen          => 'Чужое фото',
            self::LowQuality      => 'Плохое качество',
            self::Other           => 'Другое',
            self::FileMissing     => 'Файл не найден',
            self::TooLarge        => 'Превышен размер',
            self::ProcessingError => 'Ошибка обработки',
        };
    }

    /**
     * Системная причина — генерируется автоматически, не модератором.
     * options() и userValues() исключают их.
     */
    public function isSystem(): bool
    {
        return in_array($this, [
            self::FileMissing,
            self::TooLarge,
            self::ProcessingError,
        ], true);
    }

    /**
     * Опции для Select модератора — только пользовательские причины.
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->filter(fn(self $case) => !$case->isSystem())
            ->mapWithKeys(fn(self $case) => [
                $case->value => $case->label()
            ])
            ->toArray();
    }

    /**
     * Массив пользовательских значений (для валидации в контроллере).
     * Использовать ТОЛЬКО этот метод в Rule::in() — он не пропустит системные причины.
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
     * Label по строковому значению — для badge на уже отклонённых фото.
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
