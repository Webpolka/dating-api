<?php

namespace App\Http\Controllers\Admin\V1\Moderation;

use App\Actions\Admin\Moderation\ModerateReportAction;
use App\Http\Controllers\AdminController;
use App\Http\Requests\Admin\V1\Moderation\ResolveReportRequest;
use App\Http\Requests\Admin\V1\Moderation\ToggleReportUserBanRequest;
use App\Http\Resources\Admin\V1\Users\AdminUserBadgeResource;
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
 *  - Controller (HTTP-слой): валидация, resolution модели + 404, форматирование ответа
 *  - ModerateReportAction (бизнес-логика): статусы жалоб, AdminLog, уведомления, кэш
 *
 *  - index: список жалоб с фильтрами (status, type, search)
 *  - resolve: принять жалобу (warn, ban, temp_ban, shadowban, photo_deleted, no_action)
 *  - reject: отклонить жалобу (no_action)
 *  - toggleBan: забанить/разбанить юзера (ToggleUserBanAction внутри ModerateReportAction) + массовое закрытие его жалоб
 *  - rejectPhoto: отклонить фото (ModeratePhotoAction внутри ModerateReportAction) + массовое закрытие жалоб на это фото
 *  - reopen: вернуть жалобу в очередь
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


        $avatarQuery = fn($q) => $q->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb', 'path_medium', 'path_large', 'path_original'])->orderByDesc('is_primary')->limit(1);

        $query = Report::query()
            ->with([
                'reporter' => fn($q) => $q->withTrashed()->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'last_seen', 'deleted_at'])->with(['photos' => $avatarQuery]),
                'reported' => fn($q) => $q->withTrashed()->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'last_seen', 'deleted_at'])->with(['photos' => $avatarQuery]),
            ])
            ->where(function ($q) {
                $q->whereNull('reporter_id')->orWhereHas('reporter', fn($q2) => $q2->withTrashed()->excludeStaff());
            })
            ->where(function ($q) {
                $q->whereNull('reported_id')->orWhereHas('reported', fn($q2) => $q2->withTrashed()->excludeStaff());
            });

        if ($isSearching) {
            $query->where(function ($q) use ($search, $searchOperator, $isIdSearch) {
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
        }

        if ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }

        if ($typeFilter !== 'all') {
            $type = $typeFilter === 'user' ? User::class : Photo::class;
            $query->where('reportable_type', $type);
        }

        $query->latest('updated_at')->latest('id');

        $reports = $query->paginate($perPage, ['*'], 'page', $page);

        // Отдельно подгружаем фото для жалоб на фото, чтобы избежать N+1
        $photoIds = $reports->where('reportable_type', Photo::class)->pluck('reportable_id')->filter();
        // M1 FIX: Photo.user — полный select (email, role, premium, photos для avatar)
        // Раньше только id/name/status/deleted_at — UserBadge показывал неполные данные.
        $avatarQuery = fn($q) => $q->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb', 'path_medium'])
            ->orderByDesc('is_primary')->limit(1);

        $photos = collect();
        if ($photoIds->isNotEmpty()) {
            $photos = Photo::withTrashed()
                ->with([
                    'user' => fn($q) => $q->withTrashed()
                        ->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
                        ->with(['photos' => $avatarQuery])
                ])
                ->whereIn('id', $photoIds)
                ->get()
                ->keyBy('id');
        }

        $data = $reports->map(function ($report) use ($photos) {
            if ($report->reportable_type === Photo::class) {
                $report->setRelation('reportable', $photos->get($report->reportable_id));
            }
            return $this->formatReport($report);
        })->values()->all();

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $reports->currentPage(),
                'last_page'    => $reports->lastPage(),
                'per_page'     => $reports->perPage(),
                'total'        => $reports->total(),
                'from'         => $reports->firstItem(),
                'to'           => $reports->lastItem(),
            ],
            // Stats убраны из list response — отдельный endpoint /reports/counts
            // со stable queryKey на фронте. Раньше stats были здесь → скачкообразный UI.
            'status' => $statusFilter,
        ]);
    }

    /**
     * GET /api/admin/reports/counts
     *
     * Отдельный endpoint для stats — stable queryKey на фронте.
     * Кэшируется на 60 сек (Cache::remember 'admin_report_stats').
     */
    public function counts(): JsonResponse
    {
        return response()->json([
            'stats' => $this->getCounts(),
        ]);
    }

    // ============================================
    // ОДИНОЧНЫЕ ДЕЙСТВИЯ
    // ============================================

    public function resolve(ResolveReportRequest $request, int $id, ModerateReportAction $action): JsonResponse
    {
        // M3 FIX: lockForUpdate — предотвращает race condition между двумя админами
        // кликающими "Resolve" на одну жалобу одновременно.
        $report = Report::where('id', $id)->lockForUpdate()->first();
        if (!$report || $report->status !== 'pending') {
            return response()->json(['message' => 'Жалоба не найдена или уже решена'], 404);
        }

        $action->resolve($report, $request->user(), $request->validated());

        return response()->json(['message' => 'Жалоба решена. Нарушитель оповещен.']);
    }

    public function reject(Request $request, int $id, ModerateReportAction $action): JsonResponse
    {
        // M3 FIX: lockForUpdate
        $report = Report::where('id', $id)->lockForUpdate()->first();
        if (!$report || $report->status !== 'pending') {
            return response()->json(['message' => 'Жалоба не найдена или уже решена'], 404);
        }

        // M4 FIX: validate note — раньше $request->input('note', ...) без валидации.
        // Array/object input крашил AdminLog serialization + notification.
        $note = $request->string('note', 'Нет нарушения')->toString();

        $action->reject($report, $request->user(), $note);

        return response()->json(['message' => 'Жалоба отклонена']);
    }

    public function toggleBan(ToggleReportUserBanRequest $request, int $userId, ModerateReportAction $action): JsonResponse
    {
        $user = User::withTrashed()->find($userId);
        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }

        $result = $action->toggleUserBan($user, $request->user(), $request->validated());

        if (!$result['success']) {
            return response()->json(['message' => $result['message'] ?? 'Ошибка'], 422);
        }

        return response()->json(['message' => $result['message']]);
    }

    public function rejectPhoto(Request $request, int $photoId, ModerateReportAction $action): JsonResponse
    {
        $photo = Photo::withTrashed()->find($photoId);
        if (!$photo) {
            return response()->json(['message' => 'Фото не найдено'], 404);
        }

        $action->rejectPhoto($photo, $request->user());

        return response()->json(['message' => 'Фото отклонено. Жалобщики оповещены.']);
    }

    public function reopen(Request $request, int $id, ModerateReportAction $action): JsonResponse
    {
        // M3 FIX: lockForUpdate
        $report = Report::where('id', $id)->lockForUpdate()->first();
        if (!$report || $report->status === 'pending') {
            return response()->json(['message' => 'Жалоба не найдена или уже в очереди'], 404);
        }

        $action->reopen($report, $request->user());

        return response()->json(['message' => 'Жалоба возвращена в очередь.']);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getCounts(): array
    {
        // H1 FIX: Cache::remember — раньше stats считались SQL на каждый index call.
        // Теперь кэш 60 сек, mutations вызывают Cache::forget.
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

    private function formatReport(Report $r): array
    {
        return [
            'id'              => $r->id,
            'reason'          => $r->reason,
            'description'     => $r->description,
            'status'          => $r->status,
            'resolution'      => $r->resolution,
            'resolution_note' => $r->resolution_note,
            'reportable_type' => $r->reportable_type ? class_basename($r->reportable_type) : null,
            'reportable_id'   => $r->reportable_id,
            'created_at'      => $r->created_at?->toIso8601String(),
            'updated_at'      => $r->updated_at?->toIso8601String(),
            'reporter'        => $r->reporter ? AdminUserBadgeResource::nullable($r->reporter, withRole: true) : null,
            'reported'        => $r->reported ? AdminUserBadgeResource::nullable($r->reported, withRole: true) : null,
            'photo'           => $r->reportable_type === Photo::class && $r->reportable ? $this->formatPhoto($r->reportable) : null,
        ];
    }

    private function formatPhoto(Photo $p): array
    {
        return [
            'id'         => $p->id,
            'thumb_url'  => $p->thumb_url,
            'medium_url' => $p->medium_url,
            'original_url' => $p->original_url,
            'user'       => $p->user ? AdminUserBadgeResource::nullable($p->user, withRole: true) : null,
        ];
    }

}
