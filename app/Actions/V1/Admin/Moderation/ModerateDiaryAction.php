<?php

namespace App\Actions\V1\Admin\Moderation;

use App\Enums\V1\DiaryStatus;
use App\Models\AdminLog;
use App\Models\Diary;
use App\Models\DiaryRubric;
use App\Models\User;
use App\Notifications\V1\DiaryModerated;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Бизнес-логика модерации записей дневников (посты; рубрики — в ManageDiaryRubricAction).
 * Извлечена из DiaryController (thin-controller pattern, см. ModeratePhotoAction
 * и ModerateDiaryCommentAction).
 *
 * КОНТРАКТ ВОЗВРАТА (все методы):
 *   ['status' => int, 'body' => array]
 * — дескриптор HTTP-ответа. Контроллер строит из него JsonResponse:
 *   return response()->json($result['body'], $result['status']);
 * Формат позволяет сохранить исходные тела ответов 1:1, включая 422-ошибки
 * доступа к рубрикам (body содержит и 'message', и 'errors' — как валидационные
 * ответы Laravel, поэтому их нельзя выразить простой строкой/DomainException).
 *
 * Идемпотентность:
 *   - approve/reject/unpublish/destroy/restore возвращают 200 с поясняющим
 *     сообщением БЕЗ мутации, если запись уже в целевом состоянии.
 *
 * Уведомления (DiaryModerated) — те же условия, что в исходном контроллере:
 *   - approve    — при смене статуса (guard выше гарантирует oldStatus !== Published).
 *   - reject     — только если старый статус НЕ rejected (повторный reject с другой
 *                  причиной юзера уже не уведомляет).
 *   - unpublish  — только если запись была published.
 *   - updateSettings / destroy / restore / forceDelete — без уведомлений.
 *
 * Кэш:
 *   - Все методы, КРОМЕ updateSettings, инвалидируют кэш счётчиков/списков
 *     (смена настроек не влияет на счётчики — как в исходном коде).
 *
 * Модель Diary контроллер захватывает через lockForUpdate ДО вызова Action'а —
 * ровно как это было в исходном контроллере.
 */
class ModerateDiaryAction
{
    // ============================================
    // ПОСТЫ — ДЕЙСТВИЯ
    // ============================================

    /**
     * Опубликовать запись (status → published) + применить мета-настройки
     * (рубрика, is_comments_enabled) одним апдейтом.
     *
     * @param  Diary $diary     Захвачена lockForUpdate в контроллере.
     * @param  User  $admin     Кто модерировал.
     * @param  array $validated Данные ApproveDiaryRequest: diary_rubric_id?, is_comments_enabled?.
     * @return array{status: int, body: array} 200 — опубликована/уже опубликована; 422 — недоступная рубрика.
     */
    public function approve(Diary $diary, User $admin, array $validated): array
    {
        // Идемпотентность: уже опубликована — ничего не делаем.
        if ($diary->status === DiaryStatus::Published) {
            return ['status' => 200, 'body' => ['message' => 'Запись уже опубликована']];
        }

        if ($err = $this->validateRubricAccess($validated['diary_rubric_id'] ?? null, $diary->user_id, $diary->diary_rubric_id)) {
            return $err;
        }

        $oldStatus = $diary->status;
        $before = ['status' => $diary->getOriginal('status'), 'reject_reason' => $diary->getOriginal('reject_reason')];

        DB::transaction(function () use ($diary, $admin, $validated, $before) {
            $diary->update(array_merge($validated, [
                'status' => 'published',
                'published_at' => !$diary->published_at ? now() : $diary->published_at,
                'reject_reason' => null,
                'moderated_by' => $admin->id,
                'moderated_at' => now(),
            ]));

            $after = [
                'status' => 'published',
                'moderated_by' => $admin->id,
                'moderated_at' => now()->toDateTimeString(),
                'context' => ['diary_id' => $diary->id, 'title' => $diary->title],
            ];
            AdminLog::record('diary.approve', $diary, $admin, $before, $after, participants: [$diary->user_id]);
        });

        if ($oldStatus !== DiaryStatus::Published && $diary->user) {
            try {
                $diary->user->notify(new DiaryModerated($diary->id, $diary->title, 'approved'));
            } catch (\Throwable $e) {
                Log::warning('DiaryModerated notification failed (approve)', [
                    'diary_id' => $diary->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->clearCaches();

        return ['status' => 200, 'body' => ['message' => 'Запись опубликована! Настройки применены.']];
    }

    /**
     * Отклонить запись (status → rejected, reject_reason = reason)
     * + применить мета-настройки одним апдейтом.
     *
     * @param  Diary $diary     Захвачена lockForUpdate в контроллере.
     * @param  User  $admin     Кто модерировал.
     * @param  array $validated Данные RejectDiaryRequest: reason, diary_rubric_id?, is_comments_enabled?.
     * @return array{status: int, body: array} 200 — отклонена/уже отклонена по этой причине; 422 — недоступная рубрика.
     */
    public function reject(Diary $diary, User $admin, array $validated): array
    {
        // Идемпотентность: уже отклонена с этой причиной — ничего не делаем.
        if ($diary->status === DiaryStatus::Rejected && $diary->reject_reason === $validated['reason']) {
            return ['status' => 200, 'body' => ['message' => 'Запись уже отклонена по этой причине']];
        }

        if ($err = $this->validateRubricAccess($validated['diary_rubric_id'] ?? null, $diary->user_id, $diary->diary_rubric_id)) {
            return $err;
        }

        $oldStatus = $diary->status;
        $before = ['status' => $diary->getOriginal('status'), 'reject_reason' => $diary->getOriginal('reject_reason')];

        DB::transaction(function () use ($diary, $admin, $validated, $before) {
            $diary->update(array_merge($validated, [
                'status' => 'rejected',
                'reject_reason' => $validated['reason'],
                'moderated_by' => $admin->id,
                'moderated_at' => now(),
            ]));

            $after = [
                'status' => 'rejected',
                'reject_reason' => $validated['reason'],
                'moderated_by' => $admin->id,
                'moderated_at' => now()->toDateTimeString(),
                'context' => ['diary_id' => $diary->id, 'title' => $diary->title],
            ];
            AdminLog::record('diary.reject', $diary, $admin, $before, $after, participants: [$diary->user_id]);
        });

        if ($oldStatus !== DiaryStatus::Rejected && $diary->user) {
            try {
                $diary->user->notify(new DiaryModerated($diary->id, $diary->title, 'rejected', $validated['reason']));
            } catch (\Throwable $e) {
                Log::warning('DiaryModerated notification failed (reject)', [
                    'diary_id' => $diary->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->clearCaches();

        return ['status' => 200, 'body' => ['message' => 'Запись отклонена! Настройки применены.']];
    }

    /**
     * Снять запись с публикации (status → pending) + применить мета-настройки.
     *
     * @param  Diary $diary     Захвачена lockForUpdate в контроллере.
     * @param  User  $admin     Кто модерировал.
     * @param  array $validated Данные UnpublishDiaryRequest: diary_rubric_id?, is_comments_enabled?.
     * @return array{status: int, body: array} 200 — снята/уже снята; 422 — недоступная рубрика.
     */
    public function unpublish(Diary $diary, User $admin, array $validated): array
    {
        // Идемпотентность: уже pending — ничего не делаем.
        if ($diary->status === DiaryStatus::Pending) {
            return ['status' => 200, 'body' => ['message' => 'Запись уже снята с публикации']];
        }

        if ($err = $this->validateRubricAccess($validated['diary_rubric_id'] ?? null, $diary->user_id, $diary->diary_rubric_id)) {
            return $err;
        }

        $oldStatus = $diary->status;
        $before = ['status' => $diary->getOriginal('status')];

        DB::transaction(function () use ($diary, $admin, $validated, $before) {
            $diary->update(array_merge($validated, [
                'status' => 'pending',
                'moderated_by' => $admin->id,
                'moderated_at' => now(),
            ]));

            $after = [
                'status' => 'pending',
                'unpublished_by' => $admin->id,
                'unpublished_at' => now()->toDateTimeString(),
                'context' => ['diary_id' => $diary->id, 'title' => $diary->title],
            ];
            AdminLog::record('diary.unpublish', $diary, $admin, $before, $after, participants: [$diary->user_id]);
        });

        if ($oldStatus === DiaryStatus::Published && $diary->user) {
            try {
                $diary->user->notify(new DiaryModerated($diary->id, $diary->title, 'unpublished'));
            } catch (\Throwable $e) {
                Log::warning('DiaryModerated notification failed (unpublish)', [
                    'diary_id' => $diary->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->clearCaches();

        return ['status' => 200, 'body' => ['message' => 'Снято с публикации! Настройки применены.']];
    }

    /**
     * Обновить настройки записи (рубрика, is_comments_enabled) без смены статуса.
     *
     * @param  Diary $diary     Захвачена lockForUpdate в контроллере.
     * @param  User  $admin     Кто изменил.
     * @param  array $validated Данные UpdateDiarySettingsRequest: diary_rubric_id?, is_comments_enabled?.
     * @return array{status: int, body: array} 200 — сохранено; 422 — недоступная рубрика.
     */
    public function updateSettings(Diary $diary, User $admin, array $validated): array
    {
        $before = [
            'diary_rubric_id' => $diary->getOriginal('diary_rubric_id'),
            'is_comments_enabled' => $diary->getOriginal('is_comments_enabled'),
        ];

        if ($err = $this->validateRubricAccess($validated['diary_rubric_id'] ?? null, $diary->user_id, $diary->diary_rubric_id)) {
            return $err;
        }

        DB::transaction(function () use ($diary, $admin, $validated, $before) {
            $diary->update($validated);

            $after = [
                'diary_rubric_id' => $diary->diary_rubric_id,
                'is_comments_enabled' => $diary->is_comments_enabled,
                'context' => ['diary_id' => $diary->id, 'title' => $diary->title],
            ];
            AdminLog::record('diary.update', $diary, $admin, $before, $after, participants: [$diary->user_id]);
        });

        // Смена настроек не влияет на счётчики — кэш не инвалидируем (как в исходном коде).

        return ['status' => 200, 'body' => ['message' => 'Настройки сохранены!']];
    }

    /**
     * Отправить запись в карантин (soft-delete).
     *
     * @param  Diary $diary Захвачена lockForUpdate в контроллере.
     * @param  User  $admin Кто удалил.
     * @return array{status: int, body: array} 200 — в карантин / уже в карантине.
     */
    public function destroy(Diary $diary, User $admin): array
    {
        // Идемпотентность: уже в карантине — ничего не делаем.
        if ($diary->trashed()) {
            return ['status' => 200, 'body' => ['message' => 'Запись уже в карантине']];
        }

        $before = ['status' => $diary->getOriginal('status'), 'deleted_at' => $diary->getOriginal('deleted_at')];

        DB::transaction(function () use ($diary, $admin, $before) {
            $after = [
                'deleted_at' => now()->toDateTimeString(),
                'deleted_by' => $admin->id,
                'context' => ['diary_id' => $diary->id, 'title' => $diary->title],
            ];
            AdminLog::record('diary.delete', $diary, $admin, $before, $after, participants: [$diary->user_id]);
            $diary->delete();
        });

        $this->clearCaches();

        return ['status' => 200, 'body' => ['message' => 'Отправлено в карантин']];
    }

    /**
     * Восстановить запись из карантина (restore + сброс в pending на модерацию).
     *
     * @param  Diary $diary Захвачена withTrashed + lockForUpdate в контроллере.
     * @param  User  $admin Кто восстановил.
     * @return array{status: int, body: array} 200 — восстановлено / не в карантине.
     */
    public function restore(Diary $diary, User $admin): array
    {
        // Guard: restore имеет смысл только для записей в карантине.
        if (!$diary->trashed()) {
            return ['status' => 200, 'body' => ['message' => 'Запись не в карантине']];
        }

        $before = ['status' => $diary->getOriginal('status'), 'deleted_at' => $diary->getOriginal('deleted_at')];

        DB::transaction(function () use ($diary, $admin, $before) {
            $diary->restore();
            $diary->resetToPending();

            $after = [
                'status' => 'pending',
                'restored_by' => $admin->id,
                'restored_at' => now()->toDateTimeString(),
                'context' => ['diary_id' => $diary->id, 'title' => $diary->title],
            ];
            AdminLog::record('diary.restore', $diary, $admin, $before, $after, participants: [$diary->user_id]);
        });

        $this->clearCaches();

        return ['status' => 200, 'body' => ['message' => 'Восстановлено из карантина']];
    }

    /**
     * Удалить запись навсегда (force-delete).
     *
     * @param  Diary $diary Захвачена withTrashed + lockForUpdate в контроллере.
     * @param  User  $admin Кто удалил.
     * @return array{status: int, body: array} 200 — удалено навсегда.
     */
    public function forceDelete(Diary $diary, User $admin): array
    {
        $before = ['status' => $diary->getOriginal('status')];

        DB::transaction(function () use ($diary, $admin, $before) {
            $after = [
                'status' => 'destroyed',
                'deleted_by' => $admin->id,
                'deleted_at' => now()->toDateTimeString(),
                'context' => ['diary_id' => $diary->id, 'title' => $diary->title],
            ];
            AdminLog::record('diary.force_delete', $diary, $admin, $before, $after, participants: [$diary->user_id]);
            $diary->forceDelete();
        });

        $this->clearCaches();

        return ['status' => 200, 'body' => ['message' => 'Удалено навсегда']];
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    /**
     * Проверка доступности рубрики для поста
     * (используется approve/reject/unpublish/updateSettings).
     *
     * @param  int|string|null $rubricId        Новая рубрика из validated (null — не меняем).
     * @param  int|null        $diaryUserId     Автор поста.
     * @param  int|null        $currentRubricId Текущая рубрика поста.
     * @return array|null      null — ок; иначе 422-дескриптор ['status' => 422, 'body' => ['message' => ..., 'errors' => ...]].
     */
    private function validateRubricAccess($rubricId, $diaryUserId, $currentRubricId = null): ?array
    {
        if ($rubricId === null) {
            return null;
        }

        $rubric = DiaryRubric::find((int) $rubricId);
        if (!$rubric) {
            return ['status' => 422, 'body' => [
                'message' => 'Рубрика не найдена.',
                'errors' => ['diary_rubric_id' => ['Рубрика не найдена.']],
            ]];
        }

        if ($rubric->user_id !== null && $rubric->user_id !== $diaryUserId) {
            return ['status' => 422, 'body' => [
                'message' => 'Эта рубрика недоступна для данного поста (можно выбирать только системные или личные рубрики автора).',
                'errors' => ['diary_rubric_id' => ['Недоступная рубрика.']],
            ]];
        }

        // Защита от назначения скрытой рубрики.
        // Если модератор пытается назначить скрытую рубрику (is_active=false),
        // И эта рубрика НЕ является текущей рубрикой поста — запрещаем.
        // Если это текущая рубрика (модератор не менял её) — пропускаем.
        if (!$rubric->is_active && (int) $rubricId !== (int) $currentRubricId) {
            return ['status' => 422, 'body' => [
                'message' => 'Эту рубрику нельзя выбрать — она скрыта. Сначала активируйте её в разделе «Системные рубрики».',
                'errors' => ['diary_rubric_id' => ['Рубрика скрыта.']],
            ]];
        }

        return null;
    }

    private function clearCaches(): void
    {
        Cache::forget('admin_diary_counts');
        Cache::forget('admin_diary_rubrics_list');
        Cache::forget('admin_sidebar_stats');
        Cache::forget('admin_dashboard_live_v2');
    }
}
