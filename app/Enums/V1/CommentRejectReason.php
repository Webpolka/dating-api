<?php

namespace App\Enums;

/**
 * Причины отклонения комментария (для фото и дневников — единый enum).
 *
 * Стратегия модерации (Вариант A):
 *   - Статусов ровно три: pending / approved / rejected (+ виртуальный karantine через soft-delete).
 *   - «Спам» — это ПРИЧИНА отклонения, а не отдельный статус. Модератор выбирает её в dropdown
 *     наравне с «Мат», «Оскорбление» и т.д. Кнопка «Пометить спамом» в UI — это шорткат,
 *     который сразу вызывает reject с reason=spam без открытия dropdown.
 *   - Каскад один: при reject родителя ВСЕ его ответы получают reason=parent_rejected
 *     (без разделения на parent_rejected / parent_spam — оно избыточно).
 *   - Контекст bulk-операции (mass-approve / mass-reject) хранится в admin_logs.action,
 *     а не в reject_reason. mass_reject из enum'а убран.
 *
 * Пользовательские причины (isSystem() === false) — показываются в dropdown модератора.
 * Системные (isSystem() === true) — ставятся только бэкендом при каскаде, в UI не видны как опция.
 */
enum CommentRejectReason: string
{
    // === Пользовательские причины (модератор выбирает в dropdown) ===
    case Profanity = 'profanity';
    case Insult = 'insult';
    case OffTopic = 'off_topic';
    case Spam = 'spam';
    case Other = 'other';

        // === Системные причины (генерируются автоматически при каскаде) ===
        // НЕ показываются в dropdown Select — только отображаются как label на уже отклонённых.
    case ParentRejected = 'parent_rejected';

    public function label(): string
    {
        return match ($this) {
            self::Profanity       => 'Мат / Нецензурная лексика',
            self::Insult          => 'Оскорбление',
            self::OffTopic        => 'Не по теме',
            self::Spam            => 'Спам',
            self::Other           => 'Другое',
            self::ParentRejected  => 'Отклонён (по родителю)',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Profanity       => 'bg-red-500/10 text-red-500',
            self::Insult          => 'bg-destructive/10 text-destructive',
            self::OffTopic        => 'bg-yellow-500/10 text-yellow-500',
            self::Spam            => 'bg-orange-500/10 text-orange-500',
            self::Other           => 'bg-secondary text-secondary-foreground',
            self::ParentRejected  => 'bg-blue-500/10 text-blue-500',
        };
    }

    /**
     * Системная причина — генерируется автоматически, не модератором.
     * options() исключает их из Select.
     */
    public function isSystem(): bool
    {
        return $this === self::ParentRejected;
    }

    /**
     * Опции для Select модератора — только пользовательские причины.
     * Системные (parent_rejected) исключаются.
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
     * Label по строковому значению — для badge на уже отклонённых комментариях.
     * Fallback на само значение если reason не найден в enum (не должно случаться
     * после миграции, но безопасно для периода деплоя).
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
