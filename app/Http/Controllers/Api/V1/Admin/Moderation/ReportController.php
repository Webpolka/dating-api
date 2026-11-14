<?php

namespace App\Http\Controllers\Api\V1\Admin\Moderation;

use App\Actions\V1\Admin\Moderation\ModerateReportAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Moderation\ResolveReportRequest;
use App\Http\Requests\Api\V1\Admin\Moderation\ToggleReportUserBanRequest;
use App\Http\Resources\Api\V1\Admin\Moderation\ReportResource;
use App\Models\Photo;
use App\Models\Report;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Модерация жалоб (Reports).
 *
 * Архитектура:
 *  - Controller: валидация, resolution модели + 404, форматирование ответа
 *    через ReportResource.
 *  - ModerateReportAction: статусы жалоб, AdminLog, уведомления, кэш.
 *
 * index() — список жалоб с фильтрами (status, type, search).
 * ВАЖНО про N+1: для жалоб на фото мы грузим Photo batch'ем отдельно
 * (не через morphTo reportable, т.к. morphTo с разными типами = много SQL).
 * Потом подменяем reportable через setRelation, и ReportResource видит
 * загруженную связь — отдаёт photo в ответе.
 *
 * Stats кэшируются на 60 сек (Cache::remember 'admin_report_stats').
 * Все mutations сбрасывают кэш (внутри ModerateReportAction).
 */
class ReportController extends AdminController
{
    // ============================================
    // СПИСОК + СЧЁТЧИКИ
    // ============================================

    public function index(Request $request): JsonResponse
    {
        $statusFilter = $request->string('status', 'pending')->toString();
        if (!in_array($statusFilter, ['all', 'pending', 'resolved', 'rejected'], true)) {
            $statusFilter = 'pending';
        }

        $typeFilter = $request->string('type', 'all')->toString();
        if (!in_array($typeFilter, ['all', 'user', 'photo'], true)) {
            $typeFilter = 'all';
        }

        $search = $request->string('q', '')->toString();
        $perPage = min(max((int) $request->integer('per_page', 10), 1), 100);
        $page = max((int) $request->integer('page', 1), 1);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isSearching = !empty(trim($search));
        $isIdSearch = $isSearching && ctype_digit($search);

        $reports = Report::query()
            ->with([
                'reporter' => fn($q) => $q->withTrashed()
                    ->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'last_seen', 'deleted_at'])
                    ->withAvatar(),
                'reported' => fn($q) => $q->withTrashed()
                    ->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'last_seen', 'deleted_at'])
                    ->withAvatar(),
            ])
            ->where(function ($q) {
                $q->whereNull('reporter_id')->orWhereHas('reporter', fn($q2) => $q2->withTrashed()->excludeStaff());
            })
            ->where(function ($q) {
                $q->whereNull('reported_id')->orWhereHas('reported', fn($q2) => $q2->withTrashed()->excludeStaff());
            })
            ->when($isSearching, function ($q) use ($search, $searchOperator, $isIdSearch) {
                $q->where(function ($q) use ($search, $searchOperator, $isIdSearch) {
                    if ($isIdSearch) {
                        $q->where('id', (int) $search)
                            ->orWhereHas('reporter', fn($q2) => $q2->withTrashed()->where('name', $searchOperator, "%{$search}%"))
                            ->orWhereHas('reported', fn($q2) => $q2->withTrashed()->where('name', $searchOperator, "%{$search}%"));
                    } else {
                        $q->whereHas('reporter', fn($q2) => $q2->withTrashed()->where(function ($q3) use ($search, $searchOperator) {
                            $q3->where('name', $searchOperator, "%{$search}%")->orWhere('email', $searchOperator, "%{$search}%");
                        }))
                            ->orWhereHas('reported', fn($q2) => $q2->withTrashed()->where(function ($q3) use ($search, $searchOperator) {
                                $q3->where('name', $searchOperator, "%{$search}%")->orWhere('email', $searchOperator, "%{$search}%");
                            }))
                            ->orWhere('reason', $searchOperator, "%{$search}%")
                            ->orWhere('description', $searchOperator, "%{$search}%");
                    }
                });
            })
            ->when($statusFilter !== 'all', fn($q) => $q->where('status', $statusFilter))
            ->when($typeFilter !== 'all', function ($q) use ($typeFilter) {
                $type = $typeFilter === 'user' ? User::class : Photo::class;
                $q->where('reportable_type', $type);
            })
            ->latest('updated_at')->latest('id')
            ->paginate($perPage, ['*'], 'page', $page);

        // Отдельно подгружаем фото для жалоб на фото — чтобы избежать N+1
        // (morphTo reportable с разными типами = много SQL).
        // M1 FIX: Photo.user — полный select (email, role, premium, photos для avatar).
        $photoIds = $reports->where('reportable_type', Photo::class)->pluck('reportable_id')->filter();
        $photos = collect();
        if ($photoIds->isNotEmpty()) {
            $photos = Photo::withTrashed()
                ->with([
                    'user' => fn($q) => $q->withTrashed()
                        ->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
                        ->withAvatar(),
                ])
                ->whereIn('id', $photoIds)
                ->get()
                ->keyBy('id');
        }

        // Подменяем reportable через setRelation — ReportResource видит loaded
        // связь и отдаёт photo. Для жалоб на User — reportable остаётся null
        // (нам не нужно загружать User дважды, он уже в reported).
        $reports->getCollection()->transform(function ($report) use ($photos) {
            if ($report->reportable_type === Photo::class && $photos->has($report->reportable_id)) {
                $report->setRelation('reportable', $photos->get($report->reportable_id));
            }
            return $report;
        });

        return response()->json([
            'data' => ReportResource::collection($reports->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $reports->currentPage(),
                'last_page'    => $reports->lastPage(),
                'per_page'     => $reports->perPage(),
                'total'        => $reports->total(),
                'from'         => $reports->firstItem(),
                'to'           => $reports->lastItem(),
            ],
            // Stats убраны из list response — отдельный endpoint /reports/counts
            // со stable queryKey на фронте.
            'status' => $statusFilter,
        ]);
    }

    /**
     * GET /api/admin/reports/counts — отдельный endpoint для stats.
     * Кэшируется на 60 сек (Cache::remember 'admin_report_stats').
     */
    public function counts(): JsonResponse
    {
        return response()->json(['stats' => $this->getCounts()]);
    }

    // ============================================
    // ОДИНОЧНЫЕ ДЕЙСТВИЯ — делегируют ModerateReportAction
    // ============================================

    public function resolve(ResolveReportRequest $request, int $id, ModerateReportAction $action): JsonResponse
    {
        // M3 FIX: lockForUpdate — предотвращает race condition между двумя админами.
        $report = Report::where('id', $id)->lockForUpdate()->first();
        if (!$report || $report->status !== 'pending') {
            return $this->message('Жалоба не найдена или уже решена', 404);
        }

        $action->resolve($report, $request->user(), $request->validated());

        return $this->message('Жалоба решена. Нарушитель оповещен.');
    }

    public function reject(Request $request, int $id, ModerateReportAction $action): JsonResponse
    {
        $report = Report::where('id', $id)->lockForUpdate()->first();
        if (!$report || $report->status !== 'pending') {
            return $this->message('Жалоба не найдена или уже решена', 404);
        }

        // M4 FIX: validate note — раньше $request->input('note', ...) без валидации.
        $note = $request->string('note', 'Нет нарушения')->toString();

        $action->reject($report, $request->user(), $note);

        return $this->message('Жалоба отклонена');
    }

    public function toggleBan(ToggleReportUserBanRequest $request, int $userId, ModerateReportAction $action): JsonResponse
    {
        $user = User::withTrashed()->find($userId);
        if (!$user) return $this->notFound('Пользователь');

        $result = $action->toggleUserBan($user, $request->user(), $request->validated());

        if (!$result['success']) {
            return $this->message($result['message'] ?? 'Ошибка', 422);
        }

        return $this->message($result['message']);
    }

    public function rejectPhoto(Request $request, int $photoId, ModerateReportAction $action): JsonResponse
    {
        $photo = Photo::withTrashed()->find($photoId);
        if (!$photo) return $this->notFound('Фото');

        $action->rejectPhoto($photo, $request->user());

        return $this->message('Фото отклонено. Жалобщики оповещены.');
    }

    public function reopen(Request $request, int $id, ModerateReportAction $action): JsonResponse
    {
        $report = Report::where('id', $id)->lockForUpdate()->first();
        if (!$report || $report->status === 'pending') {
            return $this->message('Жалоба не найдена или уже в очереди', 404);
        }

        $action->reopen($report, $request->user());

        return $this->message('Жалоба возвращена в очередь.');
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getCounts(): array
    {
        // H1 FIX: Cache::remember — раньше stats считались SQL на каждый index call.
        return Cache::remember('admin_report_stats', 60, function () {
            $baseQuery = Report::query()
                ->where(function ($q) {
                    $q->whereNull('reporter_id')->orWhereHas('reporter', fn($q2) => $q2->withTrashed()->excludeStaff());
                })
                ->where(function ($q) {
                    $q->whereNull('reported_id')->orWhereHas('reported', fn($q2) => $q2->withTrashed()->excludeStaff());
                });

            $stats = $baseQuery->selectRaw("
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
                COUNT(*) as total
            ")->first();

            return [
                'pending'  => (int) ($stats->pending ?? 0),
                'resolved' => (int) ($stats->resolved ?? 0),
                'rejected' => (int) ($stats->rejected ?? 0),
                'total'    => (int) ($stats->total ?? 0),
            ];
        });
    }
} 