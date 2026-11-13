<?php

namespace App\Http\Controllers\Api\V1\Admin\Communication;

use App\Events\V1\SupportChatArchived;
use App\Events\V1\SupportChatRead;
use App\Events\V1\SupportChatUnarchived;
use App\Actions\Admin\Support\SendSupportChatMessageAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Communication\SendSupportChatMessageRequest;
use App\Models\AdminLog;
use App\Models\Chat;
use App\Models\Message;
use App\Models\SupportTemplate;
use App\Models\User;
use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Чат поддержки: общение админов с юзерами в служебных чатах (type='support').
 *
 * === АРХИТЕКТУРА «ОДНО ЛИЦО ДЛЯ STAFF» ===
 *
 * Все staff (admin/moderator/support) видят ВСЕ support-чаты.
 * State «прочитано/архив» — глобальное на уровне чата:
 *   - chats.staff_unread_count — сколько сообщений от юзера staff ещё не видел
 *   - chats.staff_last_read_at — когда любой staff последний раз прочитал
 *   - chats.staff_last_read_by_id — кто из staff прочитал (для аудита)
 *   - chats.is_archived — глобальный архив тикета
 *
 * Если один staff прочитал → все остальные тоже видят «прочитано».
 * Если архивнул → у всех в архиве.
 *
 * В chat_participants для support-чатов хранится ТОЛЬКО юзер
 * (один участник). Админ — «виртуальный» участник через staff-роль.
 *
 * Юзеру нужен participant-record для:
 *   - unread_count (per-user, для notification юзеру)
 *   - is_hidden / is_muted (per-user preferences, если будут нужны)
 */
class SupportChatController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $admin    = $request->user();
        $search   = mb_substr((string) $request->string('q', ''), 0, 100);
        $filter   = (string) $request->string('filter', 'unread');
        $perPage  = min(max((int) $request->integer('per_page', 20), 10), 100);
        $page     = max((int) $request->integer('page', 1), 1);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isId           = $search !== '' && ctype_digit($search);

        $avatarQuery = fn($q) => $q->select([
            'id',
            'user_id',
            'is_primary',
            'status',
            'path_thumb',
            'path_medium',
            'path_large',
            'path_original',
        ])->orderByDesc('is_primary')->limit(1);

        // Базовый запрос: все support-чаты.
        // ВАЖНО: БЕЗ фильтра по participants — все staff видят все чаты.
        $query = Chat::where('type', Chat::TYPE_SUPPORT);

        // Фильтры по глобальному state чата (не per-admin).
        // Partial indexes (chats_support_unread_idx / _active_idx / _archived_idx)
        // покрывают каждый фильтр — IndexScan с last_message_at DESC.
        $query->when($filter === 'archived', fn($q) => $q->where('is_archived', true))
            ->when($filter === 'active', fn($q) => $q
                ->where('is_archived', false)
                ->where('staff_unread_count', 0))
            ->when($filter === 'unread', fn($q) => $q
                ->where('is_archived', false)
                ->where('staff_unread_count', '>', 0));

        // Поиск. По ID — без special-case (раньше обходил фильтр по participants,
        // теперь фильтра нет — special-case тоже не нужен).
        // По имени — через participants.user (support-чат имеет 1 participant = юзер).
        if ($search !== '') {
            $query->where(function ($sub) use ($search, $searchOperator, $isId) {
                if ($isId) {
                    $sub->where('id', (int) $search);
                } else {
                    $sub->whereHas('participants.user', fn($uq) => $uq
                        ->withTrashed()->where('name', $searchOperator, "%{$search}%"));
                }
            });
        }

        // При поиске по ID — нужный чат первым
        $query->when($isId, fn($q) => $q
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]));

        // Сортировка: чаты без last_message_at в конце, потом по свежести.
        $query->orderByRaw('last_message_at IS NULL, last_message_at DESC')
            ->orderBy('id', 'desc');

        $chats = $query
            ->with([
                'participants.user' => fn($q) => $q->withTrashed()->with(['photos' => $avatarQuery]),
                'latestMessage' => fn($q) => $q->select([
                    'messages.id',
                    'messages.chat_id',
                    'messages.type',
                    'messages.body',
                    'messages.created_at',
                ]),
                // Кто последний раз прочитал — для UI «Прочитал {name} 5 мин назад».
                // withTrashed — если админа удалили, имя всё равно подгрузится
                // (хотя в staff_last_read_by_id стоит nullOnDelete, так что
                // удалённый админ = null — подгрузка безопасна).
                'lastReadBy' => fn($q) => $q->withTrashed()->select(['id', 'name']),
            ])
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'  => $chats->map(fn(Chat $c) => $this->formatChatListItem($c)),
            'meta'  => [
                'current_page' => $chats->currentPage(),
                'last_page'    => $chats->lastPage(),
                'per_page'     => $chats->perPage(),
                'total'        => $chats->total(),
                'from'         => $chats->firstItem(),
                'to'           => $chats->lastItem(),
            ],
            // Глобальные счётчики — ОДИНАКОВЫЕ для всех staff.
            'counts'  => $this->getStats(),
            'filter'  => $filter,
        ]);
    }

    /**
     * Детали чата: участники + пагинация сообщений по курсору.
     *
     * БЕЗ проверки участия — любой staff может читать любой support-чат.
     *
     * Параметр ?before={messageId} (опционально):
     *  - НЕ передан (первая загрузка) — последние 50 сообщений.
     *  - Передан — 50 сообщений с id < before (более старые).
     */
    public function show(Request $request, int $chatId): JsonResponse
    {
        $avatarQuery = fn($q) => $q->select([
            'id',
            'user_id',
            'is_primary',
            'status',
            'path_thumb',
            'path_medium',
            'path_large',
            'path_original',
        ])->orderByDesc('is_primary')->limit(1);

        $chat = Chat::with([
            'participants.user' => fn($q) => $q->withTrashed()->with(['photos' => $avatarQuery]),
            'lastReadBy' => fn($q) => $q->withTrashed()->select(['id', 'name']),
        ])->find($chatId);

        if (!$chat || $chat->type !== Chat::TYPE_SUPPORT) {
            return response()->json(['message' => 'Чат не найден'], 404);
        }

        // В support-чате partner = НЕ-staff participant (т.е. юзер).
        // В старых чатах (до рефактора на «одно лицо») в participants могло
        // быть [admin, user] — first() мог вернуть admin и в шапке показывалась
        // аватарка текущего админа вместо юзера. Фильтруем по !isStaff() —
        // берём именно юзера, независимо от порядка insertion.
        $partner = $chat->participants->first(fn($p) => $p->user && !$p->user->isStaff())?->user;

        // === Пагинация сообщений по курсору ===
        $beforeId = $request->integer('before');
        $perPage  = 50;

        $messages = Message::where('chat_id', $chatId)
            ->with(['sender' => fn($q) => $q->withTrashed()->with(['photos' => $avatarQuery])])
            ->when($beforeId > 0, fn($q) => $q->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($perPage + 1)
            ->get();

        $hasMore = $messages->count() > $perPage;
        if ($hasMore) {
            $messages = $messages->take($perPage);
        }

        $messages = $messages->sortBy('created_at')->values();
        $oldestId = $messages->isNotEmpty() ? $messages->first()->id : null;

        return response()->json([
            'data' => [
                'id'                => $chat->id,
                'is_locked'         => (bool) $chat->is_locked,
                'is_archived'       => (bool) $chat->is_archived,
                'last_message_at'   => $chat->last_message_at?->toIso8601String(),
                'partner'           => AdminUserBadgeResource::nullable($partner, withIsStaff: true),
                // Глобальное staff-state:
                'staff_unread_count'    => (int) $chat->staff_unread_count,
                'staff_last_read_at'    => $chat->staff_last_read_at?->toIso8601String(),
                'staff_last_read_by'    => $chat->lastReadBy ? [
                    'id'   => $chat->lastReadBy->id,
                    'name' => $chat->lastReadBy->name,
                ] : null,
                'messages' => $messages->map(fn($m) => [
                    'id'         => $m->id,
                    'type'       => $m->type,
                    'body'       => $m->body,
                    'status'     => $m->status,
                    'created_at' => $m->created_at?->toIso8601String(),
                    'sender'     => $m->sender ? AdminUserBadgeResource::nullable($m->sender, withIsStaff: true) : null,
                ])->toArray(),
                'messages_meta' => [
                    'has_more'  => $hasMore,
                    'oldest_id' => $oldestId,
                ],
            ],
        ]);
    }

    /**
     * Отметить чат как прочитанный — ГЛОБАЛЬНО для всех staff.
     *
     * Сбрасывает staff_unread_count в 0, обновляет staff_last_read_at/by_id.
     * Broadcast SupportChatRead на admin.support — другие staff увидят
     * «прочитано» в realtime в своих списках.
     */
    public function markAsRead(Request $request, int $chatId): JsonResponse
    {
        $admin = $request->user();
        $chat  = Chat::find($chatId);
        if (!$chat || $chat->type !== Chat::TYPE_SUPPORT) {
            return response()->json(['message' => 'Чат не найден'], 404);
        }

        // Если уже прочитан этим же админом — нет смысла update + broadcast.
        // Экономим DB write и спам в WebSocket.
        if ((int) $chat->staff_unread_count === 0 && $chat->staff_last_read_by_id === $admin->id) {
            return response()->json(['message' => 'Уже прочитано.']);
        }

        $before = [
            'staff_unread_count'  => (int) $chat->staff_unread_count,
            'staff_last_read_at'  => $chat->staff_last_read_at?->toIso8601String(),
            'staff_last_read_by_id' => $chat->staff_last_read_by_id,
        ];

        $chat->update([
            'staff_unread_count'    => 0,
            'staff_last_read_at'    => now(),
            'staff_last_read_by_id' => $admin->id,
        ]);

        // Аудит — кто и когда прочитал тикет.
        AdminLog::record(
            action: 'support.mark_as_read',
            model: $chat,
            admin: $admin,
            before: $before,
            after: [
                'staff_unread_count'    => 0,
                'staff_last_read_at'    => now()->toIso8601String(),
                'staff_last_read_by_id' => $admin->id,
                'context' => [
                    'chat_id'  => $chat->id,
                    'admin_id' => $admin->id,
                ],
            ],
        );

        // Realtime: другие staff видят «прочитано» сразу.
        // ShouldBroadcastNow + afterCommit — срабатывает сразу после коммита.
        broadcast(new SupportChatRead(
            chatId: $chat->id,
            readById: $admin->id,
            readByName: $admin->name,
            readAt: now()->toIso8601String(),
        ))->toOthers();

        Cache::forget('admin_support_stats');
        Cache::forget('admin_sidebar_stats');

        return response()->json(['message' => 'Тикет отмечен как прочитанный.']);
    }

    /**
     * Заархивировать тикет — ГЛОБАЛЬНО для всех staff.
     */
    public function archive(Request $request, int $chatId): JsonResponse
    {
        $admin = $request->user();
        $chat  = Chat::find($chatId);
        if (!$chat || $chat->type !== Chat::TYPE_SUPPORT) {
            return response()->json(['message' => 'Чат не найден'], 404);
        }

        if ($chat->is_archived) {
            return response()->json(['message' => 'Тикет уже в архиве.']);
        }

        // Кто юзер (для аудита). Берём НЕ-staff participant — в старых чатах
        // могло быть [admin, user], без фильтра получили бы admin.
        $userId = $chat->participants()
            ->whereHas('user', fn($q) => $q->whereNotIn('role', ['admin', 'moderator', 'support']))
            ->value('user_id');

        $before = ['is_archived' => false];
        $chat->update(['is_archived' => true]);

        AdminLog::record(
            action: 'support.archive',
            model: $chat,
            admin: $admin,
            before: $before,
            after: [
                'is_archived' => true,
                'context' => [
                    'chat_id'  => $chat->id,
                    'user_id'  => $userId,
                    'admin_id' => $admin->id,
                ],
            ],
            participants: array_filter([$admin->id, $userId]),
        );

        broadcast(new SupportChatArchived(
            chatId: $chat->id,
            archivedById: $admin->id,
            archivedByName: $admin->name,
            archivedAt: now()->toIso8601String(),
        ))->toOthers();

        Cache::forget('admin_support_stats');
        Cache::forget('admin_sidebar_stats');

        return response()->json(['message' => 'Тикет архивирован.']);
    }

    /**
     * Вернуть тикет из архива — ГЛОБАЛЬНО для всех staff.
     */
    public function unarchive(Request $request, int $chatId): JsonResponse
    {
        $admin = $request->user();
        $chat  = Chat::find($chatId);
        if (!$chat || $chat->type !== Chat::TYPE_SUPPORT) {
            return response()->json(['message' => 'Чат не найден'], 404);
        }

        if (!$chat->is_archived) {
            return response()->json(['message' => 'Тикет не в архиве.']);
        }

        // Кто юзер (для аудита). Берём НЕ-staff participant — в старых чатах
        // могло быть [admin, user], без фильтра получили бы admin.
        $userId = $chat->participants()
            ->whereHas('user', fn($q) => $q->whereNotIn('role', ['admin', 'moderator', 'support']))
            ->value('user_id');

        $before = ['is_archived' => true];
        $chat->update(['is_archived' => false]);

        AdminLog::record(
            action: 'support.unarchive',
            model: $chat,
            admin: $admin,
            before: $before,
            after: [
                'is_archived' => false,
                'context' => [
                    'chat_id'  => $chat->id,
                    'user_id'  => $userId,
                    'admin_id' => $admin->id,
                ],
            ],
            participants: array_filter([$admin->id, $userId]),
        );

        broadcast(new SupportChatUnarchived(
            chatId: $chat->id,
            unarchivedById: $admin->id,
            unarchivedByName: $admin->name,
            unarchivedAt: now()->toIso8601String(),
        ))->toOthers();

        Cache::forget('admin_support_stats');
        Cache::forget('admin_sidebar_stats');

        return response()->json(['message' => 'Тикет возвращен из архива.']);
    }

    /**
     * Отправить сообщение от лица админа в чат поддержки.
     *
     * БЕЗ проверки участия — любой staff может написать в любой support-чат.
     *
     * Если чат был в архиве — AUTO-UNARCHIVE (reopen тикета).
     * Это стандартное поведение support systems (Freshdesk, Zendesk):
     * юзер ответил в архивный тикет → тикет переоткрывается.
     *
     * После отправки:
     *   - chats.last_message_at = now() (для сортировки списка)
     *   - chats.staff_unread_count = 0, staff_last_read_* обновляются
     *     (админ сам прочитал своё сообщение)
     *   - юзеру chat_participants.unread_count++ (для notification юзеру)
     *
     * B5: проверка is_locked — нельзя писать в заблокированный чат.
     */
    public function sendMessage(SendSupportChatMessageRequest $request, int $chatId, SendSupportChatMessageAction $action): JsonResponse
    {
        $admin = $request->user();
        $chat  = Chat::find($chatId);
        if (!$chat || $chat->type !== Chat::TYPE_SUPPORT) {
            return response()->json(['message' => 'Чат не найден'], 404);
        }

        // B5: проверка is_locked.
        if ($chat->is_locked) {
            return response()->json(['message' => 'Чат заблокирован. Разблокируйте его, чтобы писать.'], 422);
        }

        $data = $request->validated();
        $body = trim($data['body']);

        // Бизнес-логика (транзакция, аудит, broadcast, кэш) — в Action.
        $wasArchived = $action->send($chat, $admin, $body);

        return response()->json([
            'message' => $wasArchived
                ? 'Сообщение отправлено. Тикет переоткрыт из архива.'
                : 'Сообщение отправлено',
            'auto_unarchived' => $wasArchived,
        ]);
    }

    /**
     * Создать support-чат с пользователем (если ещё не существует).
     *
     * БЕЗ adminId — чат не привязывается к конкретному админу.
     * Все staff видят его сразу после создания.
     */
    public function startWithUser(Request $request, int $userId): JsonResponse
    {
        $user = User::withTrashed()->find($userId);

        if (!$user) {
            return response()->json(['message' => 'Пользователь не найден'], 404);
        }
        if ($user->isStaff()) {
            return response()->json(['message' => 'Нельзя начать чат с сотрудником'], 422);
        }

        $chat = Chat::getOrCreateSupportChat($user->id);

        return response()->json([
            'message' => 'Чат готов',
            'chat_id' => $chat->id,
        ]);
    }

    public function templates(Request $request): JsonResponse
    {
        $templates = Cache::rememberForever('admin_support_templates', function () {
            return SupportTemplate::with('category')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('title')
                ->get()
                ->map(fn($t) => [
                    'id'        => $t->id,
                    'title'     => $t->title,
                    'body'      => $t->body,
                    'category'  => $t->category?->name ?? 'Без категории',
                ])
                ->toArray();
        });

        return response()->json(['data' => $templates]);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    /**
     * Глобальные счётчики — ОДИНАКОВЫЕ для всех staff.
     * Cache key БЕЗ admin ID (раньше был per-admin).
     */
    private function getStats(): array
    {
        return Cache::remember('admin_support_stats', 60, function () {
            $stats = Chat::where('type', Chat::TYPE_SUPPORT)
                ->selectRaw("
                    COUNT(*) as total_count,
                    SUM(CASE WHEN is_archived = false AND staff_unread_count = 0 THEN 1 ELSE 0 END) as active_count,
                    SUM(CASE WHEN is_archived = true THEN 1 ELSE 0 END) as archived_count,
                    SUM(CASE WHEN is_archived = false AND staff_unread_count > 0 THEN 1 ELSE 0 END) as unread_count
                ")->first();

            return [
                'all'      => (int) ($stats?->total_count ?? 0),
                'active'   => (int) ($stats?->active_count ?? 0),
                'unread'   => (int) ($stats?->unread_count ?? 0),
                'archived' => (int) ($stats?->archived_count ?? 0),
            ];
        });
    }

    private function formatChatListItem(Chat $chat): array
    {
        // Partner = НЕ-staff participant (юзер).
        // Фильтруем по !isStaff() вместо firstWhere('user_id', '!=', $adminId)
        // потому что:
        //   - в старых чатах (до рефактора) в participants могло быть [admin, user]
        //     и first() мог вернуть admin (если он был вставлен первым)
        //   - в новых чатах participant один = юзер
        // Фильтр по isStaff() даёт одинаковый правильный результат в обоих случаях.
        $partner    = $chat->participants->first(fn($p) => $p->user && !$p->user->isStaff())?->user;
        $lastMsg    = $chat->latestMessage;
        $lastReadBy = $chat->lastReadBy;

        return [
            'id'                => $chat->id,
            'is_locked'         => (bool) $chat->is_locked,
            'is_archived'       => (bool) $chat->is_archived,
            'last_message_at'   => $chat->last_message_at?->toIso8601String(),
            // Глобальное staff-state (раньше было per-admin):
            'staff_unread_count'    => (int) $chat->staff_unread_count,
            'staff_last_read_at'    => $chat->staff_last_read_at?->toIso8601String(),
            'staff_last_read_by'    => $lastReadBy ? [
                'id'   => $lastReadBy->id,
                'name' => $lastReadBy->name,
            ] : null,
            'partner'         => AdminUserBadgeResource::nullable($partner, withIsStaff: true),
            'last_message'    => $lastMsg ? [
                'id'         => $lastMsg->id,
                'type'       => $lastMsg->type,
                'body'       => $lastMsg->body,
                'created_at' => $lastMsg->created_at?->toIso8601String(),
            ] : null,
        ];
    }

}
