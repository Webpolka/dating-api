<?php

namespace App\Actions\V1\Admin\Moderation;

use App\Actions\V1\Admin\Main\ToggleUserBanAction;
use App\Enums\V1\ReportReason;
use App\Enums\V1\ReportResolution;
use App\Models\AdminLog;
use App\Models\Photo;
use App\Models\Report;
use App\Models\User;
use App\Notifications\V1\ReportModerated;
use App\Notifications\V1\UserWarned;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Бизнес-логика модерации жалоб (Reports).
 * Извлечена из ReportController (thin-controller pattern, см. ModeratePhotoAction).
 *
 * Архитектура:
 *   - Controller (HTTP-слой): валидация (FormRequest), resolution модели + 404,
 *     форматирование ответа (список/счётчики).
 *   - ModerateReportAction (бизнес-логика): смена статусов жалоб, AdminLog,
 *     уведомления (ReportModerated / UserWarned), инвалидация кэша.
 *
 * Композиция (см. ModerateDiaryCommentAction):
 *   - toggleUserBan: бан/разбан юзера через ToggleUserBanAction
 *     + массовое закрытие его pending-жалоб.
 *   - rejectPhoto: отклонение фото через ModeratePhotoAction (в общей транзакции)
 *     + массовое закрытие жалоб на это фото.
 *
 * Кэш:
 *   - 'admin_report_stats' — сбрасывается после каждой мутации (counts кэшируется 60 сек).
 *   - 'admin_users_stats'  — сбрасывается после успешного бана/разбана (toggleUserBan).
 */
class ModerateReportAction
{
    public function __construct(
        private readonly ToggleUserBanAction $toggleBanAction,
        private readonly ModeratePhotoAction $moderatePhotoAction,
    ) {
    }

    // ============================================
    // ОДИНОЧНЫЕ ДЕЙСТВИЯ
    // ============================================

    /**
     * Принять жалобу (resolution: warn / ban / temp_ban / shadowban / photo_deleted / no_action).
     *
     * @param Report $report  Только что заблокированная (lockForUpdate в контроллере) pending-жалоба.
     * @param User   $admin   Кто модерировал.
     * @param array  $validated Валидированные данные ResolveReportRequest.
     *                          Формат: {resolution: string, note?: string|null}.
     *                          Неверный resolution молча падает в Warn (историческое поведение).
     */
    public function resolve(Report $report, User $admin, array $validated): void
    {
        $resolution = ReportResolution::tryFrom($validated['resolution']) ?? ReportResolution::Warn;
        $note = $validated['note'] ?? 'Решено модератором';

        $before = [
            'status' => $report->getOriginal('status'),
            'resolution' => $report->getOriginal('resolution'),
        ];

        $report->resolve($admin->id, $resolution->value, $note);

        $after = [
            'status' => $resolution === ReportResolution::NoAction ? 'rejected' : 'resolved',
            'resolution' => $resolution->value,
            'resolved_by' => $admin->id,
            'resolved_at' => now()->toDateTimeString(),
            'context' => [
                'report_id' => $report->id,
                'reporter_id' => $report->reporter_id,
                'reported_id' => $report->reported_id,
                'reason' => $report->reason,
                'resolution_label' => $resolution->label(),
                'note' => $note,
            ],
        ];

        $participants = array_filter([$report->reporter_id, $report->reported_id]);

        AdminLog::record('report.resolve', $report, $admin, $before, $after, participants: $participants);

        // 1. Уведомляем жалобщика
        if ($report->reporter) {
            $report->reporter->notify(new ReportModerated(
                reportId: $report->id,
                reportableType: $report->reportable_type,
                reportableId: $report->reportable_id,
                reportedName: $report->reported?->name,
                reason: $report->reason,
                action: 'resolved',
                additionalInfo: $note
            ));
        }

        // 2. Если вынесено предупреждение — уведомляем нарушителя
        if ($resolution === ReportResolution::Warn && $report->reported) {
            $reasonText = 'Нарушение правил сервиса';
            $reportReasonEnum = ReportReason::tryFrom($report->reason ?? '');
            if ($reportReasonEnum) {
                $reasonText = $reportReasonEnum->label();
            }

            try {
                $report->reported->notify(new UserWarned($reasonText));
            } catch (\Exception $e) {
                Log::error('Ошибка отправки уведомления UserWarned: ' . $e->getMessage());
            }
        }

        $this->clearStatsCache();
    }

    /**
     * Отклонить жалобу (no_action).
     *
     * @param Report $report Только что заблокированная (lockForUpdate в контроллере) pending-жалоба.
     * @param User   $admin  Кто модерировал.
     * @param string $note   Комментарий модератора (уже нормализован через $request->string()
     *                       в контроллере — M4 FIX против array/object input).
     */
    public function reject(Report $report, User $admin, string $note = 'Нет нарушения'): void
    {
        $before = [
            'status' => $report->getOriginal('status'),
            'resolution' => $report->getOriginal('resolution'),
        ];

        $report->resolve($admin->id, 'no_action', $note);

        $after = [
            'status' => 'rejected',
            'resolution' => 'no_action',
            'resolved_by' => $admin->id,
            'resolved_at' => now()->toDateTimeString(),
            'context' => [
                'report_id' => $report->id,
                'reporter_id' => $report->reporter_id,
                'reported_id' => $report->reported_id,
                'reason' => $report->reason,
                'note' => $note,
            ],
        ];

        $participants = array_filter([$report->reporter_id, $report->reported_id]);

        AdminLog::record('report.reject', $report, $admin, $before, $after, participants: $participants);

        if ($report->reporter) {
            $report->reporter->notify(new ReportModerated(
                reportId: $report->id,
                reportableType: $report->reportable_type,
                reportableId: $report->reportable_id,
                reportedName: $report->reported?->name,
                reason: $report->reason,
                action: 'rejected',
                additionalInfo: $note
            ));
        }

        $this->clearStatsCache();
    }

    /**
     * Вернуть жалобу в очередь (pending).
     *
     * @param Report $report Не-pending жалоба (проверка в контроллере, lockForUpdate там же).
     * @param User   $admin  Кто модерировал.
     */
    public function reopen(Report $report, User $admin): void
    {
        $before = $report->only(['status', 'resolution', 'admin_id', 'resolved_at']);

        DB::transaction(function () use ($report, $admin, $before) {
            $report->reopen();
            $after = $report->only(['status', 'resolution', 'admin_id', 'resolved_at']);
            AdminLog::record('report.reopen', $report, $admin, $before, $after);
        });

        $this->clearStatsCache();
    }

    // ============================================
    // КОМПОЗИЦИЯ С ДРУГИМИ ДЕЙСТВИЯМИ
    // ============================================

    /**
     * Бан/разбан юзера из карточки жалобы.
     *
     * Композиция: ToggleUserBanAction (бан/разбан + его AdminLog/уведомления)
     * + массовое закрытие pending-жалоб нарушителя при бане.
     *
     * @param User  $user     Целевой юзер (withTrashed, найден в контроллере).
     * @param User  $admin    Кто модерировал.
     * @param array $validated Валидированные данные ToggleReportUserBanRequest.
     *                         Формат: {type: 'permanent'|'temp'|'shadow', reportId?: int|null}.
     *                         reportId задан — причина бана берётся из жалобы, иначе дефолт.
     * @return array{success: bool, is_banned?: bool, message: string} Результат ToggleUserBanAction::execute()
     *         как есть. При success=false — ранний возврат БЕЗ инвалидации кэша
     *         и БЕЗ массового закрытия жалоб (контроллер отдаёт 422).
     */
    public function toggleUserBan(User $user, User $admin, array $validated): array
    {
        $reasonText = 'Нарушение по жалобе пользователей';

        if ($validated['reportId']) {
            $report = Report::find($validated['reportId']);
            if ($report) {
                $reportReasonEnum = ReportReason::tryFrom($report->reason ?? '');
                if ($reportReasonEnum) {
                    $reasonText = $reportReasonEnum->label();
                }
            }
        }

        $result = $this->toggleBanAction->execute($user, $admin, $reasonText, $validated['type']);

        if (!$result['success']) {
            return $result;
        }

        // ФИКС: бан/разбан меняет статистику юзеров — сбрасываем кэш,
        // как это делает UserController. Раньше счётчик «Бан» на /users
        // жил по старому кэшу до 60 секунд.
        Cache::forget('admin_users_stats');

        // Массовое закрытие жалоб при бане
        if ($result['is_banned']) {
            $resolution = match ($validated['type']) {
                'shadow' => ReportResolution::Shadowban,
                'temp' => ReportResolution::TempBan,
                default => ReportResolution::Ban,
            };

            Report::where('reported_id', $user->id)
                ->where('status', 'pending')
                ->chunkById(200, function ($reports) use ($admin, $resolution) {
                    $this->bulkResolveReports($reports, $admin, $resolution);
                });
        }

        return $result;
    }

    /**
     * Отклонить фото + массово закрыть жалобы на него.
     *
     * Композиция: массовое закрытие жалоб на фото и ModeratePhotoAction::reject()
     * в ОДНОЙ транзакции (photo.reject + report.bulk_resolve атомарны).
     *
     * @param Photo $photo Целевое фото (withTrashed, найдено в контроллере).
     * @param User  $admin Кто модерировал.
     */
    public function rejectPhoto(Photo $photo, User $admin): void
    {
        DB::transaction(function () use ($photo, $admin) {
            Report::where('reportable_type', Photo::class)
                ->where('reportable_id', $photo->id)
                ->where('status', 'pending')
                ->chunkById(200, function ($reports) use ($admin) {
                    $this->bulkResolveReports($reports, $admin, ReportResolution::PhotoDeleted);
                });

            $this->moderatePhotoAction->reject($photo, $admin, 'other');
        });
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    /**
     * Массово закрыть пачку жалоб (bulk_resolve).
     *
     * C2 FIX: DB transaction FIRST (only UPDATE + AdminLog).
     * Notifications AFTER commit — если transaction rollback,
     * юзеры не получат "resolved" для нерешённых жалоб.
     *
     * @param iterable|object $reports Пачка Report (chunkById, по 200).
     * @param User            $admin   Кто модерировал.
     * @param ReportResolution $resolution Автоматическая резолюция (Ban/TempBan/Shadowban/PhotoDeleted).
     */
    private function bulkResolveReports($reports, User $admin, ReportResolution $resolution): void
    {
        if ($reports->isEmpty()) return;

        $reports->loadMissing(['reported', 'reporter']);

        $reportIds = $reports->pluck('id');
        $resolutionValue = $resolution->value;
        $resolutionNote = "Автоматическое закрытие при: {$resolution->label()}";
        $resolvedAt = now();

        // C2 FIX: DB transaction FIRST (only UPDATE + AdminLog).
        // Notifications AFTER commit — если transaction rollback,
        // юзеры не получат "resolved" для нерешённых жалоб.
        DB::transaction(function () use ($reports, $reportIds, $admin, $resolutionValue, $resolutionNote, $resolvedAt) {
            Report::whereIn('id', $reportIds)->update([
                'status' => 'resolved',
                'resolution' => $resolutionValue,
                'resolution_note' => $resolutionNote,
                'admin_id' => $admin->id,
                'resolved_at' => $resolvedAt,
            ]);

            $firstReport = $reports->first();
            $logIds = $reportIds->take(100)->toArray();

            $after = [
                'status' => 'resolved',
                'resolution' => $resolutionValue,
                'resolved_by' => $admin->id,
                'resolved_at' => $resolvedAt->toDateTimeString(),
                'context' => [
                    'count' => $reportIds->count(),
                    'sample_ids' => $logIds,
                    'auto_resolved' => true,
                    'reason' => $firstReport->reason ?? null,
                ]
            ];

            $participants = $reports->pluck('reporter_id')->merge($reports->pluck('reported_id'))->filter()->unique()->toArray();

            AdminLog::record('report.bulk_resolve', $firstReport, $admin, null, $after, participants: $participants);
        });

        // Notifications ПОСЛЕ успешного commit.
        $notifiedReporters = [];
        foreach ($reports as $report) {
            if ($report->reporter && !isset($notifiedReporters[$report->reporter_id])) {
                try {
                    $report->reporter->notify(new ReportModerated(
                        reportId: $report->id,
                        reportableType: $report->reportable_type,
                        reportableId: $report->reportable_id,
                        reportedName: $report->reported?->name,
                        reason: $report->reason,
                        action: 'resolved',
                        additionalInfo: $resolutionNote
                    ));
                } catch (\Throwable $e) {
                    Log::warning('ReportModerated notification failed (bulkResolve)', [
                        'report_id' => $report->id,
                        'error' => $e->getMessage(),
                    ]);
                }
                $notifiedReporters[$report->reporter_id] = true;
            }
        }

        $this->clearStatsCache();
    }

    private function clearStatsCache(): void
    {
        Cache::forget('admin_report_stats');
    }
}
