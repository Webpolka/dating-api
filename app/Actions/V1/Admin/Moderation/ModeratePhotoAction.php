<?php

namespace App\Actions\V1\Admin\Moderation;

use App\Jobs\V1\Admin\Moderation\ProcessApprovedPhoto;
use App\Models\AdminLog;
use App\Models\Photo;
use App\Models\User;
use App\Notifications\V1\PhotoModerated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Бизнес-логика модерации фотографий.
 *
 * Главное правило is_primary:
 *  - Фото не в approved статусе НЕ может быть is_primary=true
 *  - При любом "уводе" фото из approved (reject/softDelete) — снимаем primary и ищем замену
 *  - При restore из карантина — принудительно is_primary=false (вдруг уже назначили другой)
 *  - При destroy — запись удаляется, is_primary уже не важен
 *
 * Это гарантирует: у юзера всегда есть видимый аватар (если есть хотя бы одно approved фото).
 *
 * Блокировка:
 *  - В approve/reject/softDelete/restore/destroy используется lockForUpdate внутри транзакции —
 *    два модератора одновременно не получат двойных уведомлений или двойных job'ов.
 *
 * Идемпотентность:
 *  - approve/reject/restore возвращают 200 без действия, если фото уже в целевом состоянии.
 *  - Это защищает от повторных кликов и race conditions.
 */
class ModeratePhotoAction
{
    // ============================================
    // ОДИНОЧНЫЕ ДЕЙСТВИЯ
    // ============================================

    public function approve(Photo $photo, User $admin, bool $notify = true): Photo
    {
        // Идемпотентность: уже approved — ничего не делаем.
        if ($photo->status === 'approved') {
            return $photo;
        }

        $before = [
            'status'     => $photo->getOriginal('status'),
            'is_primary' => $photo->getOriginal('is_primary'),
        ];

        // lockForUpdate — два модератора одновременно одобряют одно фото.
        // Без lock: оба видят status=pending, оба диспатчат job, оба шлют уведомление.
        // С lock: первый захватывает, второй ждёт, перечитывает, видит уже approved — выходит.
        $alreadyApproved = false;
        DB::transaction(function () use ($photo, $admin, &$alreadyApproved) {
            $locked = Photo::lockForUpdate()->find($photo->id);
            if ($locked && $locked->status === 'approved') {
                $alreadyApproved = true;
                return;
            }
            $photo->markAsApproved($admin->id);
        });

        if ($alreadyApproved) {
            return $photo;
        }

        // Job диспатчим ПОСЛЕ commit — фото уже в approved.
        // Cache Lock внутри ProcessApprovedPhoto предотвращает duplicate processing.
        ProcessApprovedPhoto::dispatch($photo->id);

        // Если одобрили аватар — юзер верифицируется
        if ($photo->is_primary && $photo->user) {
            $photo->user->update(['is_verified' => true]);
        }

        // Дедуп уведомлений (не спамим, если за 5 мин уже было одобрение)
        if ($notify && $photo->user) {
            $cacheKey = "photo_approved_notif_{$photo->user_id}";
            if (!Cache::has($cacheKey)) {
                $photo->user->notify(new PhotoModerated($photo->id, $photo->user_id, 'approved', 1));
                Cache::put($cacheKey, true, now()->addMinutes(5));
            }
        }

        $after = [
            'status'        => 'approved',
            'moderated_by'  => $admin->id,
            'moderated_at'  => now()->toDateTimeString(),
            'context'       => [
                'photo_id' => $photo->id,
                'user_id'  => $photo->user_id,
                'url'      => $photo->path_original,
            ],
        ];

        AdminLog::record('photo.approve', $photo, $admin, $before, $after, participants: [$photo->user_id]);
        $this->clearCaches();

        return $photo;
    }

    public function reject(Photo $photo, User $admin, string $reason = 'other', bool $notify = true): void
    {
        // Идемпотентность: уже rejected с той же reason — ничего не делаем.
        if ($photo->status === 'rejected' && $photo->reject_reason === $reason) {
            return;
        }

        $user = $photo->user;

        $before = [
            'status'     => $photo->getOriginal('status'),
            'is_primary' => $photo->getOriginal('is_primary'),
        ];

        // lockForUpdate — два модератора одновременно reject'ят одно фото с разными причинами.
        $alreadyRejected = false;
        DB::transaction(function () use ($photo, $admin, $reason, $user, &$alreadyRejected) {
            $locked = Photo::lockForUpdate()->find($photo->id);
            if ($locked && $locked->status === 'rejected' && $locked->reject_reason === $reason) {
                $alreadyRejected = true;
                return;
            }
            $photo->markAsRejected($admin->id, $reason);
            // Отклонённое фото не может быть аватаром — снимаем и ищем замену.
            $this->reassignAvatarIfPrimary($photo, $user);
        });

        if ($alreadyRejected) {
            return;
        }

        if ($notify && $user) {
            $user->notify(new PhotoModerated($photo->id, $photo->user_id, 'rejected', 1));
        }

        $after = [
            'status'        => 'rejected',
            'reject_reason' => $reason,
            'is_primary'    => $photo->is_primary,
            'moderated_by'  => $admin->id,
            'moderated_at'  => now()->toDateTimeString(),
            'context'       => [
                'photo_id' => $photo->id,
                'user_id'  => $photo->user_id,
                'url'      => $photo->path_original,
            ],
        ];

        AdminLog::record('photo.reject', $photo, $admin, $before, $after, participants: [$photo->user_id]);
        $this->clearCaches();
    }

    public function softDelete(Photo $photo, User $admin): void
    {
        // Идемпотентность: уже в карантине — ничего не делаем.
        if ($photo->trashed()) {
            return;
        }

        $before = [
            'status'     => $photo->getOriginal('status'),
            'is_primary' => $photo->getOriginal('is_primary'),
            'deleted_at' => $photo->getOriginal('deleted_at'),
        ];

        $user = $photo->user;

        // lockForUpdate — два модератора одновременно soft-delete одно фото.
        $alreadyTrashed = false;
        DB::transaction(function () use ($photo, $admin, $user, &$alreadyTrashed) {
            $locked = Photo::lockForUpdate()->find($photo->id);
            if ($locked && $locked->trashed()) {
                $alreadyTrashed = true;
                return;
            }
            // Снимаем primary если надо, ищем замену — всё в одной транзакции.
            $this->reassignAvatarIfPrimary($photo, $user);
            $photo->delete();
        });

        if ($alreadyTrashed) {
            return;
        }

        $after = [
            'status'     => 'quarantined',
            'is_primary' => false,
            'deleted_at' => now()->toDateTimeString(),
            'deleted_by' => $admin->id,
            'context'    => [
                'photo_id' => $photo->id,
                'user_id'  => $photo->user_id,
                'url'      => $photo->path_original,
            ],
        ];

        AdminLog::record('photo.soft_delete', $photo, $admin, $before, $after, participants: [$photo->user_id]);

        // Уведомляем юзера что фото отправлено в карантин.
        if ($user) {
            try {
                $user->notify(new PhotoModerated($photo->id, $photo->user_id, 'deleted', 1));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('PhotoModerated notification failed (softDelete)', [
                    'photo_id' => $photo->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->clearCaches();
    }

    public function restore(Photo $photo, User $admin): void
    {
        // Идемпотентность: уже не в карантине — ничего не делаем.
        if (!$photo->trashed()) {
            return;
        }

        $before = [
            'status'     => $photo->getOriginal('status'),
            'is_primary' => $photo->getOriginal('is_primary'),
            'deleted_at' => $photo->getOriginal('deleted_at'),
        ];

        // lockForUpdate — два модератора одновременно restore одно фото.
        $alreadyRestored = false;
        DB::transaction(function () use ($photo, &$alreadyRestored) {
            $locked = Photo::withTrashed()->lockForUpdate()->find($photo->id);
            if ($locked && !$locked->trashed()) {
                $alreadyRestored = true;
                return;
            }
            $photo->restore();
            $photo->update([
                'status'        => 'pending',
                'is_primary'    => false, // принудительно — за время карантина могли назначить другой primary
                'reject_reason' => null,
                'moderated_by'  => null,
                'moderated_at'  => null,
            ]);
        });

        if ($alreadyRestored) {
            return;
        }

        $after = [
            'status'      => 'pending',
            'is_primary'  => false,
            'restored_at' => now()->toDateTimeString(),
            'restored_by' => $admin->id,
            'context'     => [
                'photo_id' => $photo->id,
                'user_id'  => $photo->user_id,
                'url'      => $photo->path_original,
            ],
        ];

        AdminLog::record('photo.restore', $photo, $admin, $before, $after, participants: [$photo->user_id]);
        $this->clearCaches();
    }

    public function destroy(Photo $photo, User $admin): void
    {
        $userId    = $photo->user_id;
        $photoId   = $photo->id;
        $photoPath = $photo->path_original;

        $before = [
            'status'     => $photo->getOriginal('status'),
            'is_primary' => $photo->getOriginal('is_primary'),
            'path'       => $photoPath,
        ];

        // lockForUpdate — два модератора одновременно destroy одно фото.
        // forceDeleting hook (Photo::booted) удаляет файлы с диска + каскадно удаляет комментарии.
        DB::transaction(function () use ($photo) {
            $locked = Photo::withTrashed()->lockForUpdate()->find($photo->id);
            if (!$locked) {
                return; // уже удалён другим модератором
            }
            $photo->forceDelete();
        });

        $after = [
            'status'     => 'destroyed',
            'deleted_by' => $admin->id,
            'deleted_at' => now()->toDateTimeString(),
            'context'    => [
                'photo_id' => $photoId,
                'user_id'  => $userId,
                'url'      => $photoPath,
            ],
        ];

        AdminLog::record('photo.destroy', $photo, $admin, $before, $after, participants: [$userId]);

        // Уведомляем юзера что фото навсегда удалено.
        $user = User::find($userId);
        if ($user) {
            try {
                $user->notify(new PhotoModerated($photoId, $userId, 'deleted', 1));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('PhotoModerated notification failed (destroy)', [
                    'photo_id' => $photoId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->clearCaches();
    }

    /**
     * Назначает фото аватаром юзера (is_primary = true).
     *
     * Логика (только SET — снять нельзя):
     *  - Фото должно быть в approved статусе (guard).
     *  - Если фото уже аватар → возвращаем "Это уже аватар" (БД не трогаем)
     *  - Если не аватар → атомарно снимаем is_primary у всех фото юзера,
     *    ставим true этому, возвращаем "Установлено как аватар"
     *
     * @return array{message: string}
     * @throws \DomainException Если фото не в approved статусе.
     */
    public function setPrimary(Photo $photo, User $admin): array
    {
        // Guard: нельзя назначить аватаром неодобренное фото.
        // Это железное правило: is_primary=true возможно только при status=approved.
        if ($photo->status !== 'approved' || $photo->trashed()) {
            throw new \DomainException('Нельзя назначить аватаром неодобренное фото. Сначала одобрите его.');
        }

        if ($photo->is_primary) {
            return ['message' => 'Это фото уже является аватаром.'];
        }

        $before = [
            'is_primary' => $photo->getOriginal('is_primary'),
            'status'     => $photo->getOriginal('status'),
        ];

        DB::transaction(function () use ($photo) {
            Photo::where('user_id', $photo->user_id)->update(['is_primary' => false]);
            $photo->update(['is_primary' => true]);
        });

        $after = [
            'is_primary' => true,
            'set_by'     => $admin->id,
            'context'    => [
                'photo_id' => $photo->id,
                'user_id'  => $photo->user_id,
                'url'      => $photo->path_original,
            ],
        ];

        AdminLog::record('photo.set_primary', $photo, $admin, $before, $after, participants: [$photo->user_id]);
        $this->clearCaches();

        return ['message' => 'Установлено как аватар.'];
    }

    // ============================================
    // МАССОВЫЕ ДЕЙСТВИЯ
    // ============================================

    // ============================================
    // МАССОВЫЕ ДЕЙСТВИЯ
    //
    // Логирование: ОДНА запись AdminLog на bulk-операцию (не цикл!).
    // В after — структурированный массив объектов [{id}, ...], как в комментариях.
    // Если элементов > 50 — обрезаем, count остаётся точным.
    //
    // Уведомления: одно на юзера (bulk идёт по одному юзеру).
    // ============================================

    public function approveAllForUser(User $user, User $admin): int
    {
        // lockForUpdate на pending photos — предотвращает race condition
        // когда два модератора одновременно approve-all одного юзера.
        $photoIds = DB::transaction(function () use ($user, $admin) {
            $ids = $user->photos()
                ->where('status', 'pending')
                ->lockForUpdate()
                ->pluck('id');

            if ($ids->isEmpty()) {
                return $ids;
            }

            Photo::whereIn('id', $ids)->update([
                'status'        => 'approved',
                'moderated_by'  => $admin->id,
                'moderated_at'  => now(),
                'reject_reason' => null,
            ]);

            // Если среди одобренных есть primary — юзер верифицируется
            $hasPrimary = Photo::whereIn('id', $ids)->where('is_primary', true)->exists();
            if ($hasPrimary) {
                $user->update(['is_verified' => true]);
            }

            return $ids;
        });

        if ($photoIds->isEmpty()) {
            return 0;
        }

        $count = $photoIds->count();

        // Dispatch jobs ПОСЛЕ transaction commit — фото уже в approved статусе.
        // Cache Lock в ProcessApprovedPhoto предотвращает duplicate processing.
        foreach ($photoIds as $id) {
            ProcessApprovedPhoto::dispatch($id);
        }

        // ОДНО уведомление юзеру (bulk идёт по одному юзеру).
        $user->notify(new PhotoModerated($photoIds->first(), $user->id, 'approved', $count));

        // Структурированный лог: массив объектов [{id}, ...], как в комментариях.
        // Ограничение 50 элементов — чтобы не раздувать JSON.
        $approvedLog = [];
        foreach ($photoIds as $id) {
            $approvedLog[] = ['id' => $id];
            if (count($approvedLog) >= 50) break;
        }

        $after = [
            'status'        => 'approved',
            'count'         => $count,
            'approved'      => $approvedLog,
            'truncated'     => $count > 50,
            'bulk_context'  => 'per_user',
            'moderated_by'  => $admin->id,
            'context'       => ['user_id' => $user->id],
        ];

        // ОДНА запись AdminLog — loggable = User (клик ведёт в профиль юзера).
        AdminLog::record('photo.mass_approve', $user, $admin, null, $after, participants: [$user->id]);
        $this->clearCaches();

        return $count;
    }

    public function rejectAllForUser(User $user, User $admin): int
    {
        // lockForUpdate на pending photos — предотвращает race condition.
        $photos = $user->photos()
            ->where('status', 'pending')
            ->lockForUpdate()
            ->select(['id', 'is_primary', 'user_id'])
            ->get();

        if ($photos->isEmpty()) {
            return 0;
        }

        $photoIds = $photos->pluck('id');
        $count = $photos->count();

        // Был ли среди отклоняемых аватар?
        $avatarRejected = $photos->contains(fn($p) => $p->is_primary);

        DB::transaction(function () use ($photoIds, $admin, $user, $avatarRejected) {
            Photo::whereIn('id', $photoIds)->update([
                'status'        => 'rejected',
                'moderated_by'  => $admin->id,
                'moderated_at'  => now(),
                // 'other' вместо 'mass_reject' — контекст bulk живёт в admin_logs.action.
                'reject_reason' => 'other',
                'is_primary'    => false,
            ]);

            // Если среди них был аватар — ищем замену среди оставшихся approved
            if ($avatarRejected) {
                $nextAvatar = $user->photos()
                    ->approved()
                    ->orderByDesc('is_primary')
                    ->oldest()
                    ->first();

                if ($nextAvatar) {
                    $nextAvatar->update(['is_primary' => true]);
                }
            }
        });

        // ОДНО уведомление юзеру (bulk идёт по одному юзеру).
        $user->notify(new PhotoModerated($photoIds->first(), $user->id, 'rejected', $count));

        // Структурированный лог: массив объектов [{id}, ...], как в комментариях.
        $rejectedLog = [];
        foreach ($photoIds as $id) {
            $rejectedLog[] = ['id' => $id];
            if (count($rejectedLog) >= 50) break;
        }

        $after = [
            'status'        => 'rejected',
            'count'         => $count,
            'rejected'      => $rejectedLog,
            'truncated'     => $count > 50,
            'bulk_context'  => 'per_user',
            'reject_reason' => 'other',
            'moderated_by'  => $admin->id,
            'context'       => ['user_id' => $user->id],
        ];

        // ОДНА запись AdminLog — loggable = User.
        AdminLog::record('photo.mass_reject', $user, $admin, null, $after, participants: [$user->id]);
        $this->clearCaches();

        return $count;
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    /**
     * Если фото является аватаром (is_primary=true):
     *  - снимаем is_primary
     *  - ищем замену среди оставшихся approved фото юзера
     *  - если нашли — делаем её новым аватаром
     *
     * Используется в reject() и softDelete() — везде, где фото "уходит" из approved.
     */
    private function reassignAvatarIfPrimary(Photo $photo, ?User $user): void
    {
        if (!$photo->is_primary) {
            return;
        }

        $photo->update(['is_primary' => false]);

        if (!$user) {
            return;
        }

        $nextAvatar = $user->photos()
            ->approved()
            ->where('id', '!=', $photo->id)
            ->orderByDesc('is_primary')
            ->oldest()
            ->first();

        if ($nextAvatar) {
            $nextAvatar->update(['is_primary' => true]);
        }
    }

    private function clearCaches(): void
    {
        Cache::forget('admin_sidebar_stats');
    }
}
