<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessCreditPaymentGateway; 
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class BillingController extends Controller
{
    public function pay(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|in:80,250,500,900',
            'payment_method' => 'required|in:card,yoomoney',
            'auto_top_up' => 'boolean',
            'return_url' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $user = $request->user();

        $plans = [
            80 => ['credits' => 80, 'bonus' => 0],
            250 => ['credits' => 300, 'bonus' => 50],
            500 => ['credits' => 650, 'bonus' => 150],
            900 => ['credits' => 1250, 'bonus' => 350],
        ];

        $plan = $plans[$validated['amount']];
        $creditsToCharge = $plan['credits'] + $plan['bonus'];

        try {
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'amount' => $validated['amount'],
                'currency' => 'RUB',
                'type' => Transaction::TYPE_CREDITS,
                'status' => Transaction::STATUS_PENDING,
                'provider' => $validated['payment_method'],
                'credits_amount' => $creditsToCharge,
                'meta' => [
                    'auto_top_up' => $validated['auto_top_up'] ?? false,
                    'bonus_credits' => $plan['bonus'],
                    'return_url' => $validated['return_url'] ?? '/',
                ]
            ]);

            // Запускаем симуляцию банка
            ProcessCreditPaymentGateway::dispatch($transaction->id)->delay(now()->addSeconds(5))->onQueue('payments');

            // Возвращаем URL для редиректа (фронтенд откроет его в новой вкладке)
            $confirmationUrl = config('app.frontend_url') . '/billing/success?transaction_id=' . $transaction->id;

            return response()->json([
                'success' => true,
                'redirect_url' => $confirmationUrl
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Ошибка БД: ' . $e->getMessage()
            ], 500);
        }
    }

    //  API для опроса статуса (Polling)
      public function status(Request $request, Transaction $transaction)
    {
        if ($transaction->user_id !== $request->user()->id) {
            abort(403);
        }

        return response()->json([
            'status' => $transaction->status,
            'creditsAmount' => $transaction->credits_amount,
            'failReason' => $transaction->meta['fail_reason'] ?? null,
            'returnUrl' => $transaction->meta['return_url'] ?? '/', // <--- Добавили возвратный URL
        ]);
    }
}