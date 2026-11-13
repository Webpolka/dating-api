<?php

namespace App\Services;

use App\Models\Transaction;

class MockAcquiringService
{
    /**
     * Симуляция запроса на возврат средств в банк-эквайринг.
     * В реальной жизни здесь был бы HTTP-запрос (Guzzle/Http) к API Stripe/ЮKassa.
     *
     * @param Transaction $transaction
     * @return array
     */
    public function refund(Transaction $transaction): array
    {
        // 1. Имитируем сетевую задержку ответа от банка (1.5 секунды)
        usleep(1500000);

        // 2. Симулируем ответ (90% успех, 10% отказ)
        $bankApproved = rand(1, 10) > 1;

        if ($bankApproved) {
            // Успешный ответ от банка
            return [
                'success'      => true,
                'message'      => 'Возврат успешно обработан банком.',
                'provider_refund_id' => 'MOCK_REFUND_' . strtoupper(uniqid()),
                'raw_response' => [
                    'status'  => 'succeeded',
                    'amount'  => $transaction->amount,
                    'currency' => $transaction->currency,
                    'created' => now()->toIso8601String()
                ]
            ];
        }

        // Симулируем случайную ошибку банка
        $errors = [
            'Недостаточно средств на корр. счете мерчанта.',
            'Срок возврата истек (прошло более 30 дней).',
            'Карта плательщика заблокирована банком-эмитентом.',
            'Превышен лимит на возвраты в сутки.'
        ];

        return [
            'success'      => false,
            'message'      => $errors[array_rand($errors)],
            'provider_refund_id' => null,
            'raw_response' => [
                'status'     => 'failed',
                'error_code'  => 'bank_declined',
                'error_description' => $errors[array_rand($errors)],
                'created'    => now()->toIso8601String()
            ]
        ];
    }

    /**
     * Симуляция запроса статуса платежа в банк-эквайринг.
     * Используется саппортом для "подтолкнуть" зависшие pending транзакции.
     */
    public function checkStatus(Transaction $transaction): array
    {
        // Имитируем сетевую задержку (1 секунда)
        usleep(1000000);

        // 80% шанс, что платеж все-таки прошел успешно
        $isPaid = rand(1, 10) <= 8;

        if ($isPaid) {
            return [
                'success' => true,
                'status'  => 'success',
                'message' => 'Банк подтвердил успешную оплату.',
                'provider_transaction_id' => 'MOCK_PAY_' . strtoupper(uniqid()),
            ];
        }

        // 20% шанс, что банк отклонил платеж (карта заблокирована, не хватило средств и т.д.)
        return [
            'success' => false,
            'status'  => 'failed',
            'message' => 'Банк отклонил платеж (недостаточно средств).',
        ];
    }
}
