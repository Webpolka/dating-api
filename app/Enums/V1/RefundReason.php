<?php

namespace App\Enums\V1;

enum RefundReason: string
{
    case UserRequest = 'user_request';
    case Fraud = 'fraud';
    case BankError = 'bank_error';
    case Duplicate = 'duplicate';
    case AdminMistake = 'admin_mistake';
    case Other = 'other';
 
    public function label(): string
    {
        return match ($this) {
            self::UserRequest => 'По запросу пользователя',
            self::Fraud => 'Фрод / Мошенничество',
            self::BankError => 'Ошибка банка / Эквайринга',
            self::Duplicate => 'Дублирующий платеж',
            self::AdminMistake => 'Ошибка модератора',
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