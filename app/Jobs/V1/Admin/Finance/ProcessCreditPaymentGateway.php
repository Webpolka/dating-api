<?php

namespace App\Jobs;

use App\Models\Transaction;
use App\Models\UserBalance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessCreditPaymentGateway implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
   

    public function __construct(public int $transactionId) {}

       public function handle(): void
    {
        $transaction = Transaction::find($this->transactionId);
        if (!$transaction || $transaction->status !== Transaction::STATUS_PENDING) return;

        // Симулируем успешный ответ от банка
        $isSuccess = rand(1, 10) > 1;

        if ($isSuccess) {
            // 1. Отмечаем транзакцию успешной
            $transaction->markAsSuccess();

            // 2. Железобетонное начисление кредитов
            $balance = UserBalance::firstOrCreate(
                ['user_id' => $transaction->user_id],
                ['credits' => 0] // Если записи нет, создаем с нулем
            );
            
            // Используем инкремент (самый надежный способ прибавить число)
            $balance->increment('credits', $transaction->credits_amount);
            
        } else {
            $transaction->markAsFailed("Ошибка: Недостаточно средств (Симуляция)");
        }
    }
}