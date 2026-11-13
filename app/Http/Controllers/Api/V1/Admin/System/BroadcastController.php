<?php

namespace App\Http\Controllers\Api\V1\Admin\System;

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\System\BroadcastRequest;
use App\Http\Requests\Api\V1\Admin\System\BulkDestroyBroadcastRequest;
use App\Jobs\V1\Admin\System\SendBroadcastJob;
use App\Models\AdminLog;
use App\Models\Broadcast;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class BroadcastController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        // B1: убрал избыточный ->toString()
        $search = (string) $request->string('q', '');
        $status = (string) $request->string('status', 'all');
        $type = (string) $request->string('type', 'all');
        $dateFrom = (string) $request->string('date_from', '');
        $dateTo = (string) $request->string('date_to', '');
        $perPage = min(max((int) $request->integer('per_page', 10), 1), 100);
        $isId = $search !== '' && ctype_digit($search);

        // B2: оператор поиска через config, не хардкод 'ilike'
        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';

        $avatarQuery = fn($q) => $q->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb', 'path_medium'])
            ->orderByDesc('is_primary')
            ->limit(1);

        $query = Broadcast::query()
            ->with(['admin' => fn($q) => $q->select('id', 'name', 'email', 'last_seen')->with(['photos' => $avatarQuery])])
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
            ->when($type !== 'all', fn($q) => $q->where('type', $type))
            ->when($dateFrom, fn($q) => $q->where('created_at', '>=', \Carbon\Carbon::parse($dateFrom)->startOfDay()))
            ->when($dateTo, fn($q) => $q->where('created_at', '<=', \Carbon\Carbon::parse($dateTo)->endOfDay()));

        // ✅ Сортировка: при поиске по ID — нужная рассылка первой
        $query->when($isId, function ($q) use ($search) {
            $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
        })
            ->latest('updated_at')
            ->latest('id');

        $broadcasts = $query->paginate($perPage);

        // Загружаем таргет юзеров отдельно, чтобы избежать N+1
        $targetUserIds = $broadcasts->getCollection()
            ->pluck('target_audience.user_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $targetUsers = [];
        if (!empty($targetUserIds)) {
            $targetUsers = User::with(['photos' => $avatarQuery])
                ->withTrashed()
                ->whereIn('id', $targetUserIds)
                ->get()
                ->keyBy('id')
                ->toArray();
        }

        return response()->json([
            'data' => $broadcasts->getCollection()->map(function ($b) use ($targetUsers) {
                $userId = $b->target_audience['user_id'] ?? null;
                $targetUser = $userId && isset($targetUsers[$userId]) ? $targetUsers[$userId] : null;

                return [
                    'id' => $b->id,
                    'title' => $b->title,
                    'message' => \Illuminate\Support\Str::limit($b->message, 60),
                    'type' => $b->type,
                    'status' => $b->status,
                    'progress' => $b->progress,
                    'sent_count' => $b->sent_count,
                    'failed_count' => $b->failed_count,
                    'total_recipients' => $b->total_recipients,
                    'scheduled_at' => $b->scheduled_at?->toIso8601String(),
                    'sent_at' => $b->sent_at?->toIso8601String(),
                    'started_at' => $b->started_at?->toIso8601String(),
                    'created_at' => $b->created_at?->toIso8601String(),
                    'audience_label' => $b->audience_label,
                    'audience_parts' => $b->audience_parts,
                    'target_user' => $targetUser ? [
                        'id' => $targetUser['id'],
                        'name' => $targetUser['name'],
                        'email' => $targetUser['email'],
                        'avatar_url' => $targetUser['avatar_url'] ?? null,
                        'is_online' => $targetUser['is_online'] ?? false,
                        'status' => $targetUser['status'] ?? 'active',
                        'deleted_at' => $targetUser['deleted_at'] ?? null,
                        'has_active_premium' => $targetUser['has_active_premium'] ?? false,
                        'has_active_vip' => $targetUser['has_active_vip'] ?? false,
                        'is_verified' => $targetUser['is_verified'] ?? false,
                    ] : null,
                    'admin' => $b->admin ? [
                        'id' => $b->admin->id,
                        'name' => $b->admin->name,
                        'email' => $b->admin->email,
                        'avatar_url' => $b->admin->avatar_url,
                        'is_online' => $b->admin->is_online,
                        'status' => $b->admin->status ?? 'active',
                        'deleted_at' => $b->admin->deleted_at ?? null,
                        'has_active_premium' => $b->admin->has_active_premium ?? false,
                        'has_active_vip' => $b->admin->has_active_vip ?? false,
                        'is_verified' => $b->admin->is_verified ?? false,
                    ] : null,
                ];
            })->values(),
            'meta' => [
                'current_page' => $broadcasts->currentPage(),
                'last_page' => $broadcasts->lastPage(),
                'per_page' => $broadcasts->perPage(),
                'total' => $broadcasts->total(),
                'from' => $broadcasts->firstItem(),
                'to' => $broadcasts->lastItem(),
            ],
            'counts' => $this->getCounts(),
        ]);
    }

    public function counts(): JsonResponse
    {
        return response()->json(['data' => $this->getCounts()]);
    }

    public function show(Broadcast $broadcast): JsonResponse
    {
        // Подгружаем полные данные target_user, если рассылка на конкретного
        // юзера. Раньше фронт получал только user_id+user_name из target_audience
        // и создавал фейкового юзера с пустыми полями → UserBadge показывал
        // placeholder вместо реальных имени/аватара/email.
        $targetUser = null;
        $targetUserId = $broadcast->target_audience['user_id'] ?? null;
        if ($targetUserId) {
            $avatarQuery = fn($q) => $q->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb', 'path_medium'])
                ->orderByDesc('is_primary')
                ->limit(1);

            $user = \App\Models\User::with(['photos' => $avatarQuery])
                ->withTrashed()
                ->find($targetUserId);

            if ($user) {
                $targetUser = [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar_url' => $user->avatar_url,
                    'is_online' => $user->is_online,
                    'status' => $user->status,
                    'deleted_at' => $user->deleted_at,
                    'has_active_premium' => $user->has_active_premium,
                    'has_active_vip' => $user->has_active_vip,
                    'is_verified' => $user->is_verified,
                ];
            }
        }

        return response()->json(['data' => [
            'id' => $broadcast->id,
            'title' => $broadcast->title,
            'type' => $broadcast->type,
            'message' => $broadcast->message,
            'email_body' => $broadcast->email_body,
            'action_url' => $broadcast->data['action_url'] ?? null,
            'scheduled_at' => $broadcast->scheduled_at?->toIso8601String(),
            'status' => $broadcast->status,
            'target_audience' => $broadcast->target_audience,
            // Полные данные target_user для UserBadge (или null, если юзер удалён).
            'target_user' => $targetUser,
        ]]);
    }

    public function store(BroadcastRequest $request): JsonResponse
    {
        $validated = $this->validateBroadcast($request);
        $admin = $request->user();

        $scheduledAt = $validated['scheduled_at'] ?? null;
        $status = !empty($scheduledAt) ? Broadcast::STATUS_SCHEDULED : Broadcast::STATUS_DRAFT;

        $data = [
            'admin_id' => $admin->id,
            'type' => $validated['type'],
            'title' => $validated['title'],
            'message' => $validated['type'] === 'email' ? strip_tags($validated['email_body']) : $validated['message'],
            'email_body' => $validated['type'] === 'email' ? $validated['email_body'] : null,
            'data' => !empty($validated['action_url']) ? ['action_url' => $validated['action_url']] : null,
            'target_audience' => $validated['target_audience'],
            'status' => $status,
            'scheduled_at' => $scheduledAt,
        ];

        $broadcast = Broadcast::create($data);

        $targetUserId = $broadcast->target_audience['user_id'] ?? null;
        $participants = $targetUserId ? [$targetUserId] : [];

        AdminLog::record('broadcast.create', $broadcast, $admin, null, [
            'status' => 'created',
            'context' => [
                'broadcast_id' => $broadcast->id,
                'title' => $broadcast->title,
                'type' => $broadcast->type,
                'admin_id' => $admin->id
            ]
        ], $participants);

        return response()->json(['message' => 'Рассылка создана', 'data' => ['id' => $broadcast->id]], 201);
    }

    public function update(BroadcastRequest $request, Broadcast $broadcast): JsonResponse
    {
        if (!in_array($broadcast->status, [Broadcast::STATUS_DRAFT, Broadcast::STATUS_SCHEDULED])) {
            return response()->json(['message' => 'Эта рассылка заблокирована для редактирования.'], 422);
        }

        $validated = $this->validateBroadcast($request);
        $admin = $request->user();

        $scheduledAt = $validated['scheduled_at'] ?? null;
        $status = !empty($scheduledAt) ? Broadcast::STATUS_SCHEDULED : Broadcast::STATUS_DRAFT;

        $before = [
            'title' => $broadcast->getOriginal('title'),
            'status' => $broadcast->getOriginal('status'),
            'type' => $broadcast->getOriginal('type')
        ];

        $broadcast->update([
            'type' => $validated['type'],
            'title' => $validated['title'],
            'message' => $validated['type'] === 'email' ? strip_tags($validated['email_body']) : $validated['message'],
            'email_body' => $validated['type'] === 'email' ? $validated['email_body'] : null,
            'data' => !empty($validated['action_url']) ? ['action_url' => $validated['action_url']] : null,
            'target_audience' => $validated['target_audience'],
            'status' => $status,
            'scheduled_at' => $scheduledAt,
        ]);

        $targetUserId = $broadcast->target_audience['user_id'] ?? null;
        $participants = $targetUserId ? [$targetUserId] : [];

        AdminLog::record('broadcast.update', $broadcast, $admin, $before, [
            'title' => $broadcast->title,
            'status' => $broadcast->status,
            'context' => [
                'broadcast_id' => $broadcast->id,
                'title' => $broadcast->title,
                'type' => $broadcast->type,
                'admin_id' => $admin->id
            ]
        ], $participants);

        return response()->json(['message' => 'Рассылка обновлена']);
    }

    public function sendNow(Broadcast $broadcast, Request $request): JsonResponse
    {
        $admin = $request->user();

        if (!in_array($broadcast->status, [Broadcast::STATUS_DRAFT, Broadcast::STATUS_SCHEDULED])) {
            return response()->json(['message' => 'Эту рассылку уже нельзя отправить.'], 422);
        }

        $before = [
            'status' => $broadcast->getOriginal('status'),
            'started_at' => $broadcast->getOriginal('started_at')
        ];

        // Atomic status update — защита от race condition (двойной клик).
        $updated = Broadcast::where('id', $broadcast->id)
            ->whereIn('status', [Broadcast::STATUS_DRAFT, Broadcast::STATUS_SCHEDULED])
            ->update([
                'status' => Broadcast::STATUS_SENDING,
                'started_at' => now()
            ]);

        if ($updated) {
            $broadcast->refresh();
            $targetUserId = $broadcast->target_audience['user_id'] ?? null;
            $participants = $targetUserId ? [$targetUserId] : [];

            SendBroadcastJob::dispatch($broadcast->id, $broadcast->target_audience)->onQueue('broadcasts');

            AdminLog::record('broadcast.send_now', $broadcast, $admin, $before, [
                'status' => 'sending',
                'started_at' => now()->toDateTimeString(),
                'context' => [
                    'broadcast_id' => $broadcast->id,
                    'title' => $broadcast->title,
                    'type' => $broadcast->type,
                    'admin_id' => $admin->id
                ]
            ], $participants);

            return response()->json(['message' => 'Рассылка поставлена в очередь']);
        }

        return response()->json(['message' => 'Не удалось обновить статус рассылки.'], 422);
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
            'new_id' => $new->id,
            'new_title' => $new->title,
            'status' => 'draft',
            'context' => [
                'source_broadcast_id' => $broadcast->id,
                'new_broadcast_id' => $new->id,
                'title' => $new->title,
                'admin_id' => $admin->id
            ]
        ], $participants);

        return response()->json(['message' => 'Рассылка продублирована', 'data' => ['id' => $new->id]]);
    }

    public function destroy(Broadcast $broadcast, Request $request): JsonResponse
    {
        $admin = $request->user();

        if ($broadcast->status === Broadcast::STATUS_SENDING) {
            return response()->json(['message' => 'Нельзя удалить рассылку в процессе отправки!'], 422);
        }

        $targetUserId = $broadcast->target_audience['user_id'] ?? null;
        $participants = $targetUserId ? [$targetUserId] : [];

        AdminLog::record('broadcast.delete', $broadcast, $admin, null, [
            'status' => 'destroyed',
            'deleted_by' => $admin->id,
            'context' => [
                'broadcast_id' => $broadcast->id,
                'title' => $broadcast->title,
                'type' => $broadcast->type,
                'admin_id' => $admin->id
            ]
        ], $participants);

        $broadcast->delete();

        return response()->json(['message' => 'Рассылка удалена']);
    }

    public function bulkDestroy(BulkDestroyBroadcastRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $admin = $request->user();
        $ids = $validated['ids'];

        $broadcasts = Broadcast::whereIn('id', $ids)
            ->where('status', '!=', Broadcast::STATUS_SENDING)
            ->get();

        if ($broadcasts->isEmpty()) {
            return response()->json(['message' => 'Нет доступных для удаления рассылок.'], 422);
        }

        $logParticipants = [];
        $validIds = [];

        foreach ($broadcasts as $broadcast) {
            $targetUserId = $broadcast->target_audience['user_id'] ?? null;
            if ($targetUserId) $logParticipants[] = $targetUserId;

            AdminLog::record('broadcast.delete', $broadcast, $admin, null, [
                'status' => 'destroyed',
                'deleted_by' => $admin->id,
                'context' => [
                    'broadcast_id' => $broadcast->id,
                    'title' => $broadcast->title,
                    'admin_id' => $admin->id
                ]
            ], $targetUserId ? [$targetUserId] : []);

            $validIds[] = $broadcast->id;
        }

        Broadcast::whereIn('id', $validIds)->delete();

        return response()->json(['message' => "Удалено рассылок: " . count($validIds)]);
    }

    public function searchUsers(Request $request): JsonResponse
    {
        $search = (string) $request->string('q', '');
        if (empty($search) || (strlen($search) < 2 && !ctype_digit($search))) {
            return response()->json(['data' => []]);
        }

        $operator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isId = ctype_digit($search);

        $users = User::query()
            // B6: excludeStaff — не предлагаем админов как получателей.
            ->excludeStaff()
            ->where(function ($q) use ($operator, $search, $isId) {
                if ($isId) {
                    $q->where('id', (int) $search);
                } else {
                    $q->where('name', $operator, "%{$search}%")
                        ->orWhere('email', $operator, "%{$search}%");
                }
            })
            ->with(['photos' => fn($q) => $q->select(['id', 'user_id', 'is_primary', 'status', 'path_thumb'])->orderBy('is_primary', 'desc')->limit(1)])
            ->withTrashed()
            ->limit(15)
            ->get(['id', 'name', 'email', 'status', 'premium_expires_at', 'vip_expires_at', 'last_seen', 'is_verified', 'deleted_at']);

        return response()->json(['data' => $users->map(fn($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'avatar_url' => $u->avatar_url,
            'status' => $u->status,
            'deleted_at' => $u->deleted_at,
            'has_active_premium' => $u->has_active_premium,
            'has_active_vip' => $u->has_active_vip,
            'is_verified' => $u->is_verified,
            'is_online' => $u->is_online,
        ])]);
    }

    /**
     * B3: счётчики по статусам с кэшем 30 сек.
     * Раньше GROUP BY на каждом запросе — теперь берём из кэша.
     * Инвалидация: после sendNow/duplicate/delete/store/update — не чистим
     * (30 сек — короткий TTL, данные быстро подтянутся). Если критично —
     * можно добавить clearCountsCache() в эти методы.
     */
    private function getCounts(): array
    {
        return Cache::remember('admin_broadcasts_counts', 30, function () {
            $counts = Broadcast::select('status', DB::raw('count(*) as total'))
                ->groupBy('status')
                ->pluck('total', 'status')
                ->toArray();

            return [
                'draft' => $counts['draft'] ?? 0,
                'scheduled' => $counts['scheduled'] ?? 0,
                'sending' => $counts['sending'] ?? 0,
                'sent' => $counts['sent'] ?? 0,
                'failed' => $counts['failed'] ?? 0,
                'total' => array_sum($counts),
            ];
        });
    }

    private function validateBroadcast(BroadcastRequest $request): array
    {
        $validated = $request->validated();

        // Формируем target_audience
        $targetAudience = [];
        if (!empty($validated['selected_user_id'])) {
            $user = User::withTrashed()->find($validated['selected_user_id']);
            $targetAudience = [
                'user_id' => $user->id,
                'user_name' => $user->name,
            ];
        } else {
            $filters = $request->input('filters', []);
            $targetAudience = array_filter([
                'gender' => $filters['gender'] ?? null,
                'is_premium' => isset($filters['is_premium']) ? filter_var($filters['is_premium'], FILTER_VALIDATE_BOOLEAN) : null,
                'city_id' => $filters['city_id'] ?? null,
                'city_label' => $filters['city_label'] ?? null,
                'age_from' => $filters['age_from'] ?? null,
                'age_to' => $filters['age_to'] ?? null,
                'last_seen_days' => $filters['last_seen_days'] ?? null,
                'has_photo' => isset($filters['has_photo']) ? filter_var($filters['has_photo'], FILTER_VALIDATE_BOOLEAN) : null,
            ], fn($value) => !is_null($value) && $value !== '');
        }

        $validated['target_audience'] = $targetAudience;

        // Очищаем email через Purifier
        if ($validated['type'] === 'email' && !empty($validated['email_body']) && class_exists(\Mews\Purifier\Facades\Purifier::class)) {
            $validated['email_body'] = clean($validated['email_body']);
        }

        return $validated;
    }
}
