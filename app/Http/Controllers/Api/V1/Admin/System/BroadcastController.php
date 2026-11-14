<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\System\BroadcastRequest;
use App\Http\Requests\Api\V1\Admin\System\BulkDestroyBroadcastRequest;
use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use App\Http\Resources\Api\V1\Admin\System\BroadcastDetailResource;
use App\Http\Resources\Api\V1\Admin\System\BroadcastListItemResource;

use App\Jobs\V1\Admin\System\SendBroadcastJob;
use App\Models\AdminLog;
use App\Models\Broadcast;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Рассылки (in-app / email / push) пользователям.
 *
 * Архитектура:
 *  - Создание/обновление: controller (validate + target_audience формирование).
 *  - Отправка: SendBroadcastJob (async, через очередь 'broadcasts').
 *  - Дублирование: $broadcast->replicate() + сброс stats.
 *  - Bulk destroy: с защитой от удаления sending-рассылок.
 *
 * Target audience — JSONB-поле. Может быть либо {user_id, user_name} (на конкретного
 * юзера), либо фильтры (gender, is_premium, city_id, age_from/to, last_seen_days, has_photo).
 *
 * Защита от двойного клика в sendNow: atomic update через whereIn('status', [...]).
 * Если статус уже не draft/scheduled — update вернёт 0 строк → 422.
 */
class BroadcastController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $search   = (string) $request->string('q', '');
        $status   = (string) $request->string('status', 'all');
        $type     = (string) $request->string('type', 'all');
        $dateFrom = (string) $request->string('date_from', '');
        $dateTo   = (string) $request->string('date_to', '');
        $perPage  = min(max((int) $request->integer('per_page', 10), 1), 100);
        $isId     = $search !== '' && ctype_digit($search);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        $broadcasts = Broadcast::query()
            ->with(['admin' => fn($q) => $q->select('id', 'name', 'email', 'last_seen')->withAvatar()])
            ->when($search !== '', function ($q) use ($search, $searchOperator, $isId) {
                $q->where(function ($q) use ($search, $searchOperator, $isId) {
                    $q->where('title', $searchOperator, "%{$search}%")
                        ->orWhere('message', $searchOperator, "%{$search}%");
                    if ($isId) {
                        $q->orWhere('id', (int) $search);
                    }
                });
            })
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->when($type !== 'all',   fn($q) => $q->where('type', $type))
            ->when($dateFrom, fn($q) => $q->where('created_at', '>=', \Carbon\Carbon::parse($dateFrom)->startOfDay()))
            ->when($dateTo,   fn($q) => $q->where('created_at', '<=', \Carbon\Carbon::parse($dateTo)->endOfDay()))
            ->when($isId, fn($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]))
            ->latest('updated_at')
            ->latest('id')
            ->paginate($perPage);

        // Загружаем target_users отдельно (batch), чтобы избежать N+1.
        // target_audience.user_id — это JSONB-поле, не morphTo/BelongsTo.
        $targetUserIds = $broadcasts->getCollection()
            ->pluck('target_audience.user_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $targetUsers = collect();
        if (!empty($targetUserIds)) {
            $targetUsers = User::withTrashed()
                ->withAvatar()
                ->whereIn('id', $targetUserIds)
                ->get()
                ->keyBy('id');
        }

        // Подменяем targetUser через setRelation — Resource видит loaded связь
        // и отдаёт её через whenLoaded('targetUser', ...).
        $broadcasts->getCollection()->transform(function ($b) use ($targetUsers) {
            $userId = $b->target_audience['user_id'] ?? null;
            if ($userId && $targetUsers->has($userId)) {
                $b->setRelation('targetUser', $targetUsers->get($userId));
            }
            return $b;
        });

        return $this->paginated($broadcasts, BroadcastListItemResource::class, [
            'counts' => $this->getCounts(),
        ]);
    }

    public function counts(): JsonResponse
    {
        return response()->json(['data' => $this->getCounts()]);
    }

    public function show(Broadcast $broadcast): JsonResponse
    {
        // Подгружаем полные данные target_user, если рассылка на конкретного юзера.
        $targetUserId = $broadcast->target_audience['user_id'] ?? null;
        if ($targetUserId) {
            $user = User::withTrashed()->withAvatar()->find($targetUserId);
            if ($user) {
                $broadcast->setRelation('targetUser', $user);
            }
        }

        return response()->json([
            'data' => (new BroadcastDetailResource($broadcast))->resolve(request()),
        ]);
    }

    public function store(BroadcastRequest $request): JsonResponse
    {
        $validated = $this->validateBroadcast($request);
        $admin = $request->user();

        $scheduledAt = $validated['scheduled_at'] ?? null;
        $status = !empty($scheduledAt) ? Broadcast::STATUS_SCHEDULED : Broadcast::STATUS_DRAFT;

        $broadcast = Broadcast::create([
            'admin_id'         => $admin->id,
            'type'             => $validated['type'],
            'title'            => $validated['title'],
            'message'          => $validated['type'] === 'email' ? strip_tags($validated['email_body']) : $validated['message'],
            'email_body'       => $validated['type'] === 'email' ? $validated['email_body'] : null,
            'data'             => !empty($validated['action_url']) ? ['action_url' => $validated['action_url']] : null,
            'target_audience'  => $validated['target_audience'],
            'status'           => $status,
            'scheduled_at'     => $scheduledAt,
        ]);

        $targetUserId = $broadcast->target_audience['user_id'] ?? null;
        $participants = $targetUserId ? [$targetUserId] : [];

        AdminLog::record('broadcast.create', $broadcast, $admin, null, [
            'status'  => 'created',
            'context' => [
                'broadcast_id' => $broadcast->id,
                'title'         => $broadcast->title,
                'type'          => $broadcast->type,
                'admin_id'      => $admin->id,
            ],
        ], $participants);

        return $this->message('Рассылка создана', 201, ['data' => ['id' => $broadcast->id]]);
    }

    public function update(BroadcastRequest $request, Broadcast $broadcast): JsonResponse
    {
        if (!in_array($broadcast->status, [Broadcast::STATUS_DRAFT, Broadcast::STATUS_SCHEDULED])) {
            return $this->message('Эта рассылка заблокирована для редактирования.', 422);
        }

        $validated = $this->validateBroadcast($request);
        $admin = $request->user();

        $scheduledAt = $validated['scheduled_at'] ?? null;
        $status = !empty($scheduledAt) ? Broadcast::STATUS_SCHEDULED : Broadcast::STATUS_DRAFT;

        $before = [
            'title'  => $broadcast->getOriginal('title'),
            'status' => $broadcast->getOriginal('status'),
            'type'   => $broadcast->getOriginal('type'),
        ];

        $broadcast->update([
            'type'            => $validated['type'],
            'title'           => $validated['title'],
            'message'         => $validated['type'] === 'email' ? strip_tags($validated['email_body']) : $validated['message'],
            'email_body'      => $validated['type'] === 'email' ? $validated['email_body'] : null,
            'data'            => !empty($validated['action_url']) ? ['action_url' => $validated['action_url']] : null,
            'target_audience' => $validated['target_audience'],
            'status'          => $status,
            'scheduled_at'    => $scheduledAt,
        ]);

        $targetUserId = $broadcast->target_audience['user_id'] ?? null;
        $participants = $targetUserId ? [$targetUserId] : [];

        AdminLog::record('broadcast.update', $broadcast, $admin, $before, [
            'title'  => $broadcast->title,
            'status' => $broadcast->status,
            'context' => [
                'broadcast_id' => $broadcast->id,
                'title'         => $broadcast->title,
                'type'          => $broadcast->type,
                'admin_id'      => $admin->id,
            ],
        ], $participants);

        return $this->message('Рассылка обновлена');
    }

    /**
     * Отправить рассылку немедленно (диспатчит Job в очередь 'broadcasts').
     *
     * Защита от двойного клика: atomic update через whereIn('status', [draft, scheduled]).
     * Если статус уже поменялся — update вернёт 0 строк → 422.
     */
    public function sendNow(Broadcast $broadcast, Request $request): JsonResponse
    {
        $admin = $request->user();

        if (!in_array($broadcast->status, [Broadcast::STATUS_DRAFT, Broadcast::STATUS_SCHEDULED])) {
            return $this->message('Эту рассылку уже нельзя отправить.', 422);
        }

        $before = [
            'status'     => $broadcast->getOriginal('status'),
            'started_at' => $broadcast->getOriginal('started_at'),
        ];

        // Atomic status update — защита от race condition (двойной клик).
        $updated = Broadcast::where('id', $broadcast->id)
            ->whereIn('status', [Broadcast::STATUS_DRAFT, Broadcast::STATUS_SCHEDULED])
            ->update([
                'status'     => Broadcast::STATUS_SENDING,
                'started_at' => now(),
            ]);

        if (!$updated) {
            return $this->message('Не удалось обновить статус рассылки.', 422);
        }

        $broadcast->refresh();
        $targetUserId = $broadcast->target_audience['user_id'] ?? null;
        $participants = $targetUserId ? [$targetUserId] : [];

        SendBroadcastJob::dispatch($broadcast->id, $broadcast->target_audience)->onQueue('broadcasts');

        AdminLog::record('broadcast.send_now', $broadcast, $admin, $before, [
            'status'      => 'sending',
            'started_at'  => now()->toDateTimeString(),
            'context' => [
                'broadcast_id' => $broadcast->id,
                'title'         => $broadcast->title,
                'type'          => $broadcast->type,
                'admin_id'      => $admin->id,
            ],
        ], $participants);

        return $this->message('Рассылка поставлена в очередь');
    }

    public function duplicate(Broadcast $broadcast, Request $request): JsonResponse
    {
        $admin = $request->user();

        $new = $broadcast->replicate();
        $new->status = Broadcast::STATUS_DRAFT;
        $new->sent_at = null;
        $new->scheduled_at = null;
        $new->sent_count = 0;
        $new->failed_count = 0;
        $new->total_recipients = 0;
        $new->started_at = null;
        $new->save();

        $targetUserId = $new->target_audience['user_id'] ?? null;
        $participants = $targetUserId ? [$targetUserId] : [];

        AdminLog::record('broadcast.duplicate', $broadcast, $admin, null, [
            'new_id'      => $new->id,
            'new_title'   => $new->title,
            'status'      => 'draft',
            'context' => [
                'source_broadcast_id' => $broadcast->id,
                'new_broadcast_id'    => $new->id,
                'title'               => $new->title,
                'admin_id'            => $admin->id,
            ],
        ], $participants);

        return $this->message('Рассылка продублирована', 200, ['data' => ['id' => $new->id]]);
    }

    public function destroy(Broadcast $broadcast, Request $request): JsonResponse
    {
        $admin = $request->user();

        if ($broadcast->status === Broadcast::STATUS_SENDING) {
            return $this->message('Нельзя удалить рассылку в процессе отправки!', 422);
        }

        $targetUserId = $broadcast->target_audience['user_id'] ?? null;
        $participants = $targetUserId ? [$targetUserId] : [];

        AdminLog::record('broadcast.delete', $broadcast, $admin, null, [
            'status'      => 'destroyed',
            'deleted_by'  => $admin->id,
            'context' => [
                'broadcast_id' => $broadcast->id,
                'title'         => $broadcast->title,
                'type'          => $broadcast->type,
                'admin_id'      => $admin->id,
            ],
        ], $participants);

        $broadcast->delete();

        return $this->message('Рассылка удалена');
    }

    public function bulkDestroy(BulkDestroyBroadcastRequest $request): JsonResponse
    {
        $admin = $request->user();
        $ids = $request->validated()['ids'];

        // Не удаляем sending-рассылки (защита от удаления в процессе).
        $broadcasts = Broadcast::whereIn('id', $ids)
            ->where('status', '!=', Broadcast::STATUS_SENDING)
            ->get();

        if ($broadcasts->isEmpty()) {
            return $this->message('Нет доступных для удаления рассылок.', 422);
        }

        $logParticipants = [];
        $validIds = [];

        foreach ($broadcasts as $broadcast) {
            $targetUserId = $broadcast->target_audience['user_id'] ?? null;
            if ($targetUserId) $logParticipants[] = $targetUserId;

            AdminLog::record('broadcast.delete', $broadcast, $admin, null, [
                'status'      => 'destroyed',
                'deleted_by'  => $admin->id,
                'context' => [
                    'broadcast_id' => $broadcast->id,
                    'title'         => $broadcast->title,
                    'admin_id'      => $admin->id,
                ],
            ], $targetUserId ? [$targetUserId] : []);

            $validIds[] = $broadcast->id;
        }

        Broadcast::whereIn('id', $validIds)->delete();

        return $this->message("Удалено рассылок: " . count($validIds));
    }

    /**
     * Поиск юзеров для выбора получателя рассылки (type=user).
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $search = (string) $request->string('q', '');
        if (empty($search) || (strlen($search) < 2 && !ctype_digit($search))) {
            return response()->json(['data' => []]);
        }

        $operator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isId = ctype_digit($search);

        $users = User::query()
            ->excludeStaff()
            ->when($isId, fn($q) => $q->where('id', (int) $search))
            ->when(!$isId, fn($q) => $q->where(function ($q) use ($operator, $search) {
                $q->where('name', $operator, "%{$search}%")
                    ->orWhere('email', $operator, "%{$search}%");
            }))
            ->withAvatar()
            ->withTrashed()
            ->limit(15)
            ->get(['id', 'name', 'email', 'status', 'premium_expires_at', 'vip_expires_at', 'last_seen', 'is_verified', 'deleted_at']);

        return response()->json([
            'data' => AdminUserBadgeResource::collection($users)->resolve(request()),
        ]);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    /**
     * Счётчики по статусам. Кэш 30 сек (короткий TTL — данные быстро подтянутся).
     */
    private function getCounts(): array
    {
        return Cache::remember('admin_broadcasts_counts', 30, function () {
            $counts = Broadcast::select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->toArray();

            return [
                'draft'     => $counts['draft'] ?? 0,
                'scheduled' => $counts['scheduled'] ?? 0,
                'sending'   => $counts['sending'] ?? 0,
                'sent'      => $counts['sent'] ?? 0,
                'failed'    => $counts['failed'] ?? 0,
                'total'     => array_sum($counts),
            ];
        });
    }

    /**
     * Формирование target_audience + Purifier для email_body.
     */
    private function validateBroadcast(BroadcastRequest $request): array
    {
        $validated = $request->validated();

        $targetAudience = [];
        if (!empty($validated['selected_user_id'])) {
            $user = User::withTrashed()->find($validated['selected_user_id']);
            $targetAudience = [
                'user_id'   => $user->id,
                'user_name' => $user->name,
            ];
        } else {
            $filters = $request->input('filters', []);
            $targetAudience = array_filter([
                'gender'          => $filters['gender'] ?? null,
                'is_premium'      => isset($filters['is_premium']) ? filter_var($filters['is_premium'], FILTER_VALIDATE_BOOLEAN) : null,
                'city_id'         => $filters['city_id'] ?? null,
                'city_label'      => $filters['city_label'] ?? null,
                'age_from'        => $filters['age_from'] ?? null,
                'age_to'          => $filters['age_to'] ?? null,
                'last_seen_days'  => $filters['last_seen_days'] ?? null,
                'has_photo'       => isset($filters['has_photo']) ? filter_var($filters['has_photo'], FILTER_VALIDATE_BOOLEAN) : null,
            ], fn($value) => !is_null($value) && $value !== '');
        }

        $validated['target_audience'] = $targetAudience;

        // Очищаем email через Purifier (XSS protection).
        if ($validated['type'] === 'email' && !empty($validated['email_body']) && class_exists(\Mews\Purifier\Facades\Purifier::class)) {
            $validated['email_body'] = clean($validated['email_body']);
        }

        return $validated;
    }
}
