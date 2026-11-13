<?php

namespace App\Enums\V1;

enum DeletionReason: string
{
    case UserRequest = 'user_request'; // По просьбе пользователя (ФЗ-152)
    case TestAccount = 'test_account'; // Тестовый аккаунт
    case Duplicate = 'duplicate';      // Дубль-аккаунт
    case Underage = 'underage';        // Несовершеннолетний
    case Scam = 'scam';                // Мошенник (если удаляем перманентно без бана)
    case Other = 'other';              // Другое
 
    public function label(): string
    {
        return match ($this) {
            self::UserRequest => 'По просьбе пользователя',
            self::TestAccount => 'Тестовый аккаунт',
            self::Duplicate => 'Дубль-аккаунт',
            self::Underage => 'Несовершеннолетний',
            self::Scam => 'Мошенничество',
            self::Other => 'Другое',
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($case) => [
            $case->value => $case->label()
        ])->toArray();
    }
}