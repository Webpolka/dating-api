<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Finance\StoreSubscriptionPlanRequest;
use App\Http\Requests\Api\V1\Admin\Finance\UpdateSubscriptionPlanRequest;
use App\Http\Resources\Api\V1\Admin\Finance\SubscriptionPlanResource;
use App\Models\AdminLog;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Тарифы и подписки (Premium / VIP).
 *
 * Логика из ManageSubscriptionPlansAction залита прямо в методы (это OK —
 * операции простые, без транзакций/каскадов).
 *
 * Slug — UNIQUE constraint в БД. При создании/обновлении ловим
 * QueryException с кодом 23505 (unique_violation) → 422.
 *
 * Cache: admin_plan_counts (60 сек). Инвалидация через clearCache() после
 * каждого mutation (store / update / toggleActive).
 */
class SubscriptionPlanController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $tier   = (string) $request->string('tier', 'premium');
        $search = (string) $request->string('q', '');

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isId           = $search !== '' && ctype_digit($search);

        $plans = SubscriptionPlan::where('tier', $tier)
            ->when($search !== '', function ($q) use ($search, $searchOperator, $isId) {
                $q->where(function ($q) use ($search, $searchOperator, $isId) {
                    $q->where('name', $searchOperator, "%{$search}%")
                        ->orWhere('slug', $searchOperator, "%{$search}%");
                    if ($isId) {
                        $q->orWhere('id', (int) $search);
                    }
                });
            })
            ->when($isId, fn($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]))
            ->orderBy('sort_order')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'data'   => SubscriptionPlanResource::collection($plans)->resolve($request),
            'counts' => $this->getCounts(),
        ]);
    }

    public function store(StoreSubscriptionPlanRequest $request): JsonResponse
    {
        $admin = $request->user();
        $data  = $request->validated();

        // Нормализация: пустые строки → null (для nullable-полей).
        $data['currency']           = 'RUB';
        $data['old_price']          = !empty($data['old_price']) ? $data['old_price'] : null;
        $data['apple_product_id']   = $data['apple_product_id'] ?: null;
        $data['google_product_id']   = $data['google_product_id'] ?: null;

        try {
            $plan = SubscriptionPlan::create($data);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23505') {
                return $this->message(
                    'Тариф с таким slug уже существует.',
                    422,
                    ['errors' => ['slug' => ['Slug «' . $data['slug'] . '» уже занят.']]],
                );
            }
            throw $e;
        }

        AdminLog::record('plan.create', $plan, $admin, null, [
            'status'  => 'created',
            'context' => [
                'plan_id'       => $plan->id,
                'name'          => $plan->name,
                'tier'          => $plan->tier,
                'price'         => $plan->price,
                'duration_days' => $plan->duration_days,
                'admin_id'      => $admin->id,
            ],
        ]);

        $this->clearCache();

        return $this->message('Тариф создан', 200, [
            'data' => new SubscriptionPlanResource($plan),
        ]);
    }

    public function update(UpdateSubscriptionPlanRequest $request, $id): JsonResponse
    {
        $admin = $request->user();
        $plan  = SubscriptionPlan::find((int) $id);
        if (!$plan) return $this->notFound('Тариф');

        $data = $request->validated();

        // Та же нормализация, что в store().
        $data['old_price']          = !empty($data['old_price']) ? $data['old_price'] : null;
        $data['apple_product_id']   = $data['apple_product_id'] ?: null;
        $data['google_product_id']   = $data['google_product_id'] ?: null;

        $before = [
            'name'           => $plan->getOriginal('name'),
            'price'          => $plan->getOriginal('price'),
            'old_price'      => $plan->getOriginal('old_price'),
            'duration_days'  => $plan->getOriginal('duration_days'),
        ];

        $plan->update($data);

        AdminLog::record('plan.update', $plan, $admin, $before, [
            'name'          => $plan->name,
            'price'         => $plan->price,
            'old_price'     => $plan->old_price,
            'duration_days' => $plan->duration_days,
            'context'       => [
                'plan_id'  => $plan->id,
                'name'     => $plan->name,
                'admin_id' => $admin->id,
            ],
        ]);

        $this->clearCache();

        return $this->message('Тариф обновлен', 200, [
            'data' => new SubscriptionPlanResource($plan),
        ]);
    }

    public function toggleActive(Request $request, $id): JsonResponse
    {
        $admin = $request->user();
        $plan  = SubscriptionPlan::find((int) $id);
        if (!$plan) return $this->notFound('Тариф');

        $before = ['is_active' => $plan->getOriginal('is_active')];
        $plan->update(['is_active' => !$plan->is_active]);

        AdminLog::record('plan.toggle_active', $plan, $admin, $before, [
            'is_active' => $plan->is_active,
            'context'   => [
                'plan_id'  => $plan->id,
                'name'     => $plan->name,
                'admin_id' => $admin->id,
            ],
        ]);

        $this->clearCache();

        return $this->message(
            $plan->is_active ? 'Тариф активирован' : 'Тариф скрыт',
            200,
            ['is_active' => $plan->is_active],
        );
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getCounts(): array
    {
        return Cache::remember('admin_plan_counts', 60, function () {
            return [
                'premium' => SubscriptionPlan::where('tier', 'premium')->count(),
                'vip'     => SubscriptionPlan::where('tier', 'vip')->count(),
            ];
        });
    }

    private function clearCache(): void
    {
        Cache::forget('admin_plan_counts');
    }
}
