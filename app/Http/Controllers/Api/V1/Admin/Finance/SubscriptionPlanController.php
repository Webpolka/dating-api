<?php

namespace App\Http\Controllers\Api\V1\Admin\Finance;

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Finance\StoreSubscriptionPlanRequest;
use App\Http\Requests\Api\V1\Admin\Finance\UpdateSubscriptionPlanRequest;
use App\Models\AdminLog;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Тарифы и подписки (Premium / VIP).
 *
 * Логика из ManageSubscriptionPlansAction залита прямо в методы.
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
            ->when($isId, function ($q) use ($search) {
                $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
            })
            ->orderBy('sort_order')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json([
            'data'   => $plans->map(fn(SubscriptionPlan $p) => $this->formatPlan($p)),
            'counts' => $this->getCounts(),
        ]);
    }

    public function store(StoreSubscriptionPlanRequest $request): JsonResponse
    {
        $admin = $request->user();
        $data  = $request->validated();

        $data['currency']           = 'RUB';
        $data['old_price']          = !empty($data['old_price']) ? $data['old_price'] : null;
        $data['apple_product_id']   = $data['apple_product_id'] ?: null;
        $data['google_product_id']   = $data['google_product_id'] ?: null;

        try {
            $plan = SubscriptionPlan::create($data);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($e->getCode() === '23505') {
                return response()->json([
                    'message' => 'Тариф с таким slug уже существует.',
                    'errors'   => ['slug' => ['Slug «' . $data['slug'] . '» уже занят.']],
                ], 422);
            }
            throw $e;
        }

        AdminLog::record('plan.create', $plan, $admin, null, [
            'status'  => 'created',
            'context' => [
                'plan_id'       => $plan->id,
                'name'           => $plan->name,
                'tier'           => $plan->tier,
                'price'           => $plan->price,
                'duration_days'  => $plan->duration_days,
                'admin_id'       => $admin->id,
            ],
        ]);

        $this->clearCache();

        return response()->json(['message' => 'Тариф создан', 'data' => $this->formatPlan($plan)]);
    }

    public function update(UpdateSubscriptionPlanRequest $request, $id): JsonResponse
    {
        $admin = $request->user();
        $plan  = SubscriptionPlan::find((int) $id);
        if (!$plan) {
            return response()->json(['message' => 'Тариф не найден'], 404);
        }

        $data = $request->validated();

        $data['old_price']          = !empty($data['old_price']) ? $data['old_price'] : null;
        $data['apple_product_id']   = $data['apple_product_id'] ?: null;
        $data['google_product_id']   = $data['google_product_id'] ?: null;

        $before = [
            'name'           => $plan->getOriginal('name'),
            'price'           => $plan->getOriginal('price'),
            'old_price'       => $plan->getOriginal('old_price'),
            'duration_days'  => $plan->getOriginal('duration_days'),
        ];

        $plan->update($data);

        AdminLog::record('plan.update', $plan, $admin, $before, [
            'name'           => $plan->name,
            'price'           => $plan->price,
            'old_price'       => $plan->old_price,
            'duration_days'  => $plan->duration_days,
            'context'         => [
                'plan_id'  => $plan->id,
                'name'      => $plan->name,
                'admin_id'  => $admin->id,
            ],
        ]);

        $this->clearCache();

        return response()->json(['message' => 'Тариф обновлен', 'data' => $this->formatPlan($plan)]);
    }

    public function toggleActive(Request $request, $id): JsonResponse
    {
        $admin = $request->user();
        $plan  = SubscriptionPlan::find((int) $id);
        if (!$plan) {
            return response()->json(['message' => 'Тариф не найден'], 404);
        }

        $before = ['is_active' => $plan->getOriginal('is_active')];
        $plan->update(['is_active' => !$plan->is_active]);

        AdminLog::record('plan.toggle_active', $plan, $admin, $before, [
            'is_active'  => $plan->is_active,
            'context'    => [
                'plan_id'  => $plan->id,
                'name'      => $plan->name,
                'admin_id'  => $admin->id,
            ],
        ]);

        $this->clearCache();

        return response()->json([
            'message'   => $plan->is_active ? 'Тариф активирован' : 'Тариф скрыт',
            'is_active' => $plan->is_active,
        ]);
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

    private function formatPlan(SubscriptionPlan $p): array
    {
        return [
            'id'                => $p->id,
            'tier'              => $p->tier,
            'name'              => $p->name,
            'slug'              => $p->slug,
            'price'             => (float) $p->price,
            'old_price'         => $p->old_price !== null ? (float) $p->old_price : null,
            'currency'          => $p->currency,
            'duration_days'     => (int) $p->duration_days,
            'apple_product_id'  => $p->apple_product_id,
            'google_product_id'  => $p->google_product_id,
            'is_active'          => (bool) $p->is_active,
            'sort_order'        => (int) $p->sort_order,
            'created_at'        => $p->created_at?->toIso8601String(),
            // B2: добавил updated_at — UI может показывать "последнее обновление"
            'updated_at'        => $p->updated_at?->toIso8601String(),
        ];
    }
}
