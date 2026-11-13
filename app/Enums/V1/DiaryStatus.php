<?php

namespace App\Enums\V1;

/**
 * Статус дневника (Diary).
 *
 * Хранится в DB column `status` (тип enum('draft','pending','published','rejected')).
 * В модели Diary подключен через $casts['status'] => DiaryStatus::class —
 * Laravel автоматически преобразует string из DB в enum case и обратно.
 *
 * ВАЖНО: case'ы в этом enum ДОЛЖНЫ совпадать с case'ами в DB column.
 * DB column не может содержать значение, которого нет в PHP enum —
 * если добавить новый case сюда, нужно ALTER TABLE сначала.
 *
 * Migration: database/migrations/0001_01_01_000044_create_diaries_table.php
 *   $table->enum('status', ['draft', 'pending', 'published', 'rejected'])->default('draft');
 *
 * Раньше в модели Diary были string constants (STATUS_DRAFT = 'draft' и т.д.).
 * Enum даёт типобезопасность — опечатка в строке ловится на этапе компиляции,
 * а не в runtime.
 */
enum DiaryStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Published = 'published';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Черновик',
            self::Pending   => 'На модерации',
            self::Published => 'Опубликован',
            self::Rejected  => 'Отклонён',
        };
    }

    /**
     * Bootstrap-variant для Badge в UI (синхронизировано с DIARY_STATUS_BADGES на фронте).
     */
    public function badgeVariant(): string
    {
        return match ($this) {
            self::Draft     => 'secondary',
            self::Pending   => 'warning',
            self::Published => 'success',
            self::Rejected  => 'destructive',
        };
    }

    /**
     * Модератор может сменить статус — т.е. это не draft (авторский) и не terminal.
     * Draft остаётся только если автор сам сохраняет в черновики.
     */
    public function isModeratable(): bool
    {
        return in_array($this, [self::Pending, self::Published, self::Rejected], true);
    }

    /**
     * Terminal status — после которого модератор не может вернуть в moderation queue.
     * Сейчас все статусы можно сменить (через approve/reject/unpublish), но
     * эта проверка пригодится для будущего audit trail.
     */
    public function isTerminal(): bool
    {
        return false;
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn($case) => [
            $case->value => $case->label()
        ])->toArray();
    }
}
