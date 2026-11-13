<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessSubscriptionPaymentGateway;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\UserSubscription;
use App\Http\Requests\V1\SubscriptionPlansRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SubscriptionController extends Controller
{
    /**
     * API endpoint для получения списка тарифов подписок.
     *
     * Заменяет View::share('premiumPlans'/'vipPlans') из AppServiceProvider
     * — это работало для Blade, но SPA на Next.js не использует Blade,
     * ему нужны отдельные HTTP-запросы.
     *
     * Используется:
     *  - VipModal.tsx     — GET /api/subscriptions/plans?tier=vip
     *  - PremiumModal.tsx — GET /api/subscriptions/plans?tier=premium
     *
     * Без tier — вернёт все активные тарифы (premium + vip).
     */
    public function plans(SubscriptionPlansRequest $request)
    {
        $validated = $request->validated();

        $query = SubscriptionPlan::active()->ordered();

        if (isset($validated['tier'])) {
            $query->where('tier', $validated['tier']);
        }

        // Явно отбираем поля, нужные фронту — не отдаём apple_product_id,
        // google_product_id, sort_order, is_active, timestamps и т.д.
        $plans = $query->get()->map(fn(SubscriptionPlan $plan) => [
            'id'            => $plan->id,
            'tier'          => $plan->tier,
            'name'          => $plan->name,
            'slug'          => $plan->slug,
            // decimal:2 возвращает строку "250.00" — кастуем к float для фронта
            'price'         => (float) $plan->price,
            'old_price'     => $plan->old_price !== null ? (float) $plan->old_price : null,
            'currency'      => $plan->currency,
            'duration_days' => $plan->duration_days,
        ])->values();

        return response()->json(['plans' => $plans]);
    }

    public function pay(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'plan_id' => 'required|exists:subscription_plans,id',
            'payment_method' => 'required|in:card,yoomoney',
            'auto_renew' => 'boolean',
            'return_url' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $user = $request->user();
        $plan = SubscriptionPlan::findOrFail($validated['plan_id']);

        // NEW: Защита от повторной покупки того же tier.
        // Это "defence in depth" — покрывает случай, когда пользователь вызовет
        // POST /api/subscriptions/pay напрямую через curl/Postman, минуя
        // фронтовую защиту в vip-page-client.tsx / premium-page-client.tsx
        // (где при активной подписке показывается 404 / NotFoundPlaceholder).
        //
        // ЛОГИКА (соответствует LovePlanet, Mamba, Twinby и другим dating-платформам):
        //  - Premium и VIP — НЕ взаимоисключающие. У пользователя может быть
        //    ОБА одновременно (это разные "уровни привилегий": Premium даёт
        //    общение без ограничений и расширенный поиск, VIP даёт приоритет
        //    в выдаче и выделение в поиске).
        //  - Нельзя купить ТОТ ЖЕ tier, если он уже активен (даже если подписка
        //    помечена как 'canceled' — она даёт доступ до конца оплаченного
        //    периода, ends_at > now).
        //  - После истечения подписки (ends_at < now, либо status='expired')
        //    пользователь может купить снова.
        //
        // Это правильное поведение: платформа не хочет дважды списывать деньги
        // за тот же период, и не хочет усложнять биллинг (как сложить два VIP?).
        $hasActiveSubscription = UserSubscription::where('user_id', $user->id)
            ->where('tier', $plan->tier)
            ->whereIn('status', [UserSubscription::STATUS_ACTIVE, UserSubscription::STATUS_CANCELED])
            ->where('ends_at', '>', now())
            ->exists();

        if ($hasActiveSubscription) {
            // Человекопонятное имя tier для сообщения об ошибке.
            // match() вместо ucfirst($plan->tier) — потому что "vip" должен
            // показываться как "VIP" (в верхнем регистре), а не "Vip".
            $tierLabel = match ($plan->tier) {
                SubscriptionPlan::TIER_PREMIUM => 'Премиум',
                SubscriptionPlan::TIER_VIP      => 'VIP',
                default                         => ucfirst($plan->tier),
            };

            return response()->json([
                'success' => false,
                'message' => "У вас уже активна подписка {$tierLabel}. Дождитесь окончания текущего периода, чтобы продлить.",
            ], 400);
        }

        try {
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'amount' => $plan->price,
                'currency' => $plan->currency,
                'type' => Transaction::TYPE_SUBSCRIPTION,
                'status' => Transaction::STATUS_PENDING,
                'provider' => $validated['payment_method'],
                'meta' => [
                    'plan_id' => $plan->id,
                    'tier' => $plan->tier,
                    'auto_renew' => $validated['auto_renew'] ?? false,
                    'return_url' => $validated['return_url'] ?? route('home'),
                ]
            ]);

            // ЗАПУСКАЕМ АСИНХРОННУЮ СИМУЛЯЦИЮ БАНКА (Задержка 5 секунд)
            ProcessSubscriptionPaymentGateway::dispatch($transaction->id)->delay(now()->addSeconds(5))->onQueue('payments');

            // Возвращаем ссылку на success page.
            // FIX: было route('subscription.success', ...) — но в SPA-проекте этого
            // named route НЕТ (success page живёт на Next.js, не на Laravel).
            //
            // ИСПОЛЬЗУЕМ config('app.frontend_url') вместо env('FRONTEND_URL', ...).
            // Это правильно для прода: php artisan config:cache кеширует конфиги,
            // и после этого env() в контроллерах НЕ РАБОТАЕТ (возвращает null).
            // Поэтому нужно:
            //   1) в config/app.php добавить 'frontend_url' => env('FRONTEND_URL', 'http://localhost:3000')
            //   2) в коде использовать config('app.frontend_url')
            // Тогда config:cache сохранит значение, и в проде всё будет работать.
            $confirmationUrl = config('app.frontend_url')
                . '/subscriptions/success?transaction_id=' . $transaction->id;

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

    /**
     * NEW: Продление уже активной подписки того же tier.
     *
     * В отличие от pay(), который отказывает при активной подписке того же tier,
     * renew() — наоборот, ТРЕБУЕТ активную (или отменённую, но ещё действующую)
     * подписку. Иначе нет чего продлевать.
     *
     * После успешной оплаты job ProcessSubscriptionPaymentGateway НЕ создаёт
     * новую UserSubscription, а ПРОДЛЕВАЕТ текущую — прибавляет duration_days
     * плана к ends_at текущей подписки. Так пользователь не теряет уже
     * оплаченные дни (если осталось 5 дней и он продлевает на 30 дней —
     * ends_at сдвигается на 35 дней от сегодня, а не на 30).
     *
     * Endpoint: POST /api/subscriptions/renew
     * Body: { plan_id, payment_method, auto_renew, return_url }
     *
     * Защита: renew() работает ТОЛЬКО при активной подписке того же tier.
     * Если у пользователя подписка Premium, и он вызывает renew() с plan_id
     * для VIP — будет 400 ошибка "У вас нет активной подписки VIP для продления".
     * Это правильно — renew() всегда продлевает ТОТ ЖЕ tier, что и текущая
     * активная подписка.
     */
    public function renew(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'plan_id' => 'required|exists:subscription_plans,id',
            'payment_method' => 'required|in:card,yoomoney',
            'auto_renew' => 'boolean',
            'return_url' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        $user = $request->user();
        $plan = SubscriptionPlan::findOrFail($validated['plan_id']);

        // Ищем активную (или отменённую, но действующую) подписку того же tier.
        // Берём самую "дальнюю" по ends_at — это та, которую надо продлить.
        // Сортировка orderByDesc('ends_at') покрывает edge case, если у пользователя
        // почему-то несколько записей (исторически сложилось) — продлеваем самую дальнюю.
        $currentSubscription = UserSubscription::where('user_id', $user->id)
            ->where('tier', $plan->tier)
            ->whereIn('status', [UserSubscription::STATUS_ACTIVE, UserSubscription::STATUS_CANCELED])
            ->where('ends_at', '>', now())
            ->orderByDesc('ends_at')
            ->first();

        if (!$currentSubscription) {
            // Человекопонятное имя tier для сообщения об ошибке.
            $tierLabel = match ($plan->tier) {
                SubscriptionPlan::TIER_PREMIUM => 'Премиум',
                SubscriptionPlan::TIER_VIP      => 'VIP',
                default                         => ucfirst($plan->tier),
            };

            return response()->json([
                'success' => false,
                'message' => "У вас нет активной подписки {$tierLabel} для продления. Используйте обычную покупку.",
            ], 400);
        }

        try {
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'amount' => $plan->price,
                'currency' => $plan->currency,
                'type' => Transaction::TYPE_SUBSCRIPTION,
                'status' => Transaction::STATUS_PENDING,
                'provider' => $validated['payment_method'],
                'meta' => [
                    'plan_id' => $plan->id,
                    'tier' => $plan->tier,
                    'auto_renew' => $validated['auto_renew'] ?? false,
                    'return_url' => $validated['return_url'] ?? route('home'),
                    // NEW: помечаем что это продление. Job ProcessSubscriptionPaymentGateway
                    // проверит это поле и вместо создания новой UserSubscription — продлит текущую.
                    'action' => 'renew',
                    'current_subscription_id' => $currentSubscription->id,
                ]
            ]);

            // ЗАПУСКАЕМ АСИНХРОННУЮ СИМУЛЯЦИЮ БАНКА (Задержка 5 секунд)
            ProcessSubscriptionPaymentGateway::dispatch($transaction->id)->delay(now()->addSeconds(5))->onQueue('payments');

            // Возвращаем ссылку на success page (тот же, что и для pay).
            // ИСПОЛЬЗУЕМ config('app.frontend_url') вместо env('FRONTEND_URL', ...).
            // См. комментарий в pay() методе — почему это правильно для прода.
            $confirmationUrl = config('app.frontend_url')
                . '/subscriptions/success?transaction_id=' . $transaction->id;

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

    public function success(Request $request)
    {
        // В SPA этот метод не нужен — Next.js сам рендерит страницу /subscriptions/success.
        // Оставлен только если в routes/web.php ещё есть роут на этот метод (для
        // обратной совместимости со старым Blade). Можно удалить, когда полностью
        // перейдёшь на SPA.
        $transactionId = $request->query('transaction_id');
        $transaction = Transaction::findOrFail($transactionId);

        $returnUrl = $transaction->meta['return_url'] ?? route('home');
        if (str_contains($returnUrl, '/premium') || str_contains($returnUrl, '/vip')) {
            $returnUrl = route('home');
        }

        return view('pages.subscriptions.success', [
            'transactionId' => $transaction->id,
            'tier' => $transaction->meta['tier'] ?? 'subscription',
            'returnUrl' => $returnUrl
        ]);
    }

    // API для опроса статуса (Polling)
    public function status(Request $request, Transaction $transaction)
    {
        // Проверка безопасности
        if ($transaction->user_id !== $request->user()->id) {
            abort(403);
        }

        $planName = SubscriptionPlan::find($transaction->meta['plan_id'] ?? null)?->name ?? 'тариф';
        $endsAt = null;
        if ($transaction->status === Transaction::STATUS_SUCCESS) {
            $sub = $transaction->user->subscriptions()->where('transaction_id', $transaction->id)->first();
            if ($sub) $endsAt = $sub->ends_at->format('d.m.Y H:i');
        }

        return response()->json([
            'status' => $transaction->status,
            'planName' => $planName,
            'endsAt' => $endsAt,
            'failReason' => $transaction->meta['fail_reason'] ?? null,
            // tier нужен фронту для выбора цветовой схемы (blue для VIP, orange для Premium)
            'tier' => $transaction->meta['tier'] ?? 'premium',
            // returnUrl нужен фронту для кнопки "Продолжить" на success page
            'returnUrl' => $transaction->meta['return_url'] ?? null,
        ]);
    }
}
