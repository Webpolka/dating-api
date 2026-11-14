<?php

namespace App\Http\Controllers\Api\V1\Admin\Communication;

use App\Actions\V1\Admin\Communication\SendSupportChatMessageAction;
use App\Events\V1\SupportChatArchived;
use App\Events\V1\SupportChatRead;
use App\Events\V1\SupportChatUnarchived;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Communication\SendSupportChatMessageRequest;
use App\Http\Resources\Api\V1\Admin\Communication\ChatMessageResource;
use App\Http\Resources\Api\V1\Admin\Communication\SupportChatResource;
use App\Models\AdminLog;
use App\Models\Chat;
use App\Models\Message;
use App\Models\SupportTemplate;
use App\Models\User;
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
 * === ИСПОЛЬЗУЕМЫЕ ХЕЛПЕРЫ ИЗ AdminController ===
 *  - $this->paginated()  — для list endpoint
 *  - $this->message()    — для mutation responses
 *  - $this->notFound()   — для 404
 *
 * === ИСПОЛЬЗУЕМЫЕ SCOPE'Ы ИЗ МОДЕЛЕЙ ===
 *  - Chat::support()              — where type='support'
 *  - User::withAvatar()           — eager-load аватара (вместо $avatarQuery closure)
 *
 * === КЭШ ===
 *  - admin_support_stats     (60с) — счётчики для списка тикетов
 *  - admin_sidebar_stats            — глобальный кэш сайдбара
 *  - admin_support_templates (forever) — кэш шаблонов для чата поддержки
 *  Инвалидация — через приватный invalidateStatsCache() (3 места → 1 метод).
 */
class SupportChatController extends AdminController
{
    /**
     * GET /api/admin/support-chats — список тикетов с фильтром и поиском.
     *
     * БЕЗ фильтра по participants — все staff видят все чаты.
     */
    public function index(Request $request): JsonResponse
    {
        $search   = mb_substr((string) $request->string('q', ''), 0, 100);
        $filter   = (string) $request->string('filter', 'unread');
        $perPage  = min(max((int) $request->integer('per_page', 20), 10), 100);
        $page     = max((int) $request->integer('page', 1), 1);

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isId           = $search !== '' && ctype_digit($search);

        $chats = Chat::support()
            // Фильтры по глобальному state чата (не per-admin).
            // Partial indexes (chats_support_unread_idx / _active_idx / _archived_idx)
            // покрывают каждый фильтр — IndexScan с last_message_at DESC.
            ->when($filter === 'archived', fn($q) => $q->where('is_archived', true))
            ->when($filter === 'active', fn($q) => $q
                ->where('is_archived', false)
                ->where('staff_unread_count', 0))
            ->when($filter === 'unread', fn($q) => $q
                ->where('is_archived', false)
                ->where('staff_unread_count', '>', 0))
            // Поиск. По ID — без special-case (раньше обходил фильтр по participants,
            // теперь фильтра нет — special-case тоже не нужен).
            // По имени — через participants.user (support-чат имеет 1 participant = юзер).
            ->when($search !== '', function ($q) use ($search, $searchOperator, $isId) {
                $q->where(function ($sub) use ($search, $searchOperator, $isId) {
                    if ($isId) {
                        $sub->where('id', (int) $search);
                    } else {
                        $sub->whereHas('participants.user', fn($uq) => $uq
                            ->withTrashed()
                            ->where('name', $searchOperator, "%{$search}%"));
                    }
                });
            })
            // При поиске по ID — нужный чат первым
            ->when($isId, fn($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]))
            // Сортировка: чаты без last_message_at в конце, потом по свежести.
            ->orderByRaw('last_message_at IS NULL, last_message_at DESC')
            ->orderBy('id', 'desc')
            ->with([
                'participants.user' => fn($q) => $q->withTrashed()->withAvatar(),
                'latestMessage'     => fn($q) => $q->select([
                    'messages.id', 'messages.chat_id', 'messages.type',
                    'messages.body', 'messages.created_at',
                ]),
                // Кто последний раз прочитал — для UI «Прочитал {name} 5 мин назад».
                // withTrashed — если админа удалили, имя всё равно подгрузится
                // (хотя в staff_last_read_by_id стоит nullOnDelete, так что
                // удалённый админ = null — подгрузка безопасна).
                'lastReadBy' => fn($q) => $q->withTrashed()->select(['id', 'name']),
            ])
            ->paginate($perPage, ['*'], 'page', $page);

        return $this->paginated($chats, SupportChatResource::class, [
            'counts' => $this->getStats(),
            'filter' => $filter,
        ]);
    }

    /**
     * GET /api/admin/support-chats/{chatId} — детали тикета + сообщения.
     *
     * БЕЗ проверки участия — любой staff может читать любой support-чат.
     *
     * Параметр ?before={messageId} (опционально):
     *  - НЕ передан — последние 50 сообщений.
     *  - Передан — 50 сообщений с id < before (более старые).
     */
    public function show(Request $request, int $chatId): JsonResponse
    {
        $chat = $this->findSupportChat($chatId);
        if (! $chat) {
            return $this->notFound('Чат');
        }

        // === Пагинация сообщений по курсору ===
        $beforeId = $request->integer('before');
        $perPage  = 50;

        // +1 чтобы узнать has_more без отдельного COUNT(*)
        $messages = Message::where('chat_id', $chatId)
            ->with(['sender' => fn($q) => $q->withTrashed()->withAvatar()])
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

        // SupportChatResource через whenLoaded('latestMessage') — в show() мы её
        // НЕ грузим, поэтому last_message в ответе будет отсутствовать (что и нужно).
        return response()->json([
            'data' => array_merge(
                (new SupportChatResource($chat))->resolve($request),
                [
                    'messages'      => ChatMessageResource::collection($messages)->resolve($request),
                    'messages_meta' => [
                        'has_more'  => $hasMore,
                        'oldest_id' => $oldestId,
                    ],
                ],
            ),
        ]);
    }

    /**
     * POST /api/admin/support-chats/{chatId}/mark-as-read
     *
     * ГЛОБАЛЬНО для всех staff: сбрасывает staff_unread_count, обновляет
     * staff_last_read_at/by_id. Broadcast SupportChatRead на admin.support —
     * другие staff видят «прочитано» в realtime.
     */
    public function markAsRead(Request $request, int $chatId): JsonResponse
    {
        $admin = $request->user();
        $chat  = $this->findSupportChat($chatId);
        if (! $chat) {
            return $this->notFound('Чат');
        }

        // Если уже прочитан этим же админом — нет смысла update + broadcast.
        // Экономим DB write и спам в WebSocket.
        if ((int) $chat->staff_unread_count === 0 && $chat->staff_last_read_by_id === $admin->id) {
            return $this->message('Уже прочитано.');
        }

        $before = [
            'staff_unread_count'    => (int) $chat->staff_unread_count,
            'staff_last_read_at'    => $chat->staff_last_read_at?->toIso8601String(),
            'staff_last_read_by_id' => $chat->staff_last_read_by_id,
        ];

        $chat->update([
            'staff_unread_count'    => 0,
            'staff_last_read_at'    => now(),
            'staff_last_read_by_id' => $admin->id,
        ]);

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
        broadcast(new SupportChatRead(
            chatId: $chat->id,
            readById: $admin->id,
            readByName: $admin->name,
            readAt: now()->toIso8601String(),
        ))->toOthers();

        $this->invalidateStatsCache();

        return $this->message('Тикет отмечен как прочитанный.');
    }

    /**
     * POST /api/admin/support-chats/{chatId}/archive
     * Заархивировать тикет — ГЛОБАЛЬНО для всех staff.
     */
    public function archive(Request $request, int $chatId): JsonResponse
    {
        $admin = $request->user();
        $chat  = $this->findSupportChat($chatId);
        if (! $chat) {
            return $this->notFound('Чат');
        }
        if ($chat->is_archived) {
            return $this->message('Тикет уже в архиве.');
        }

        $userId = $this->getTicketUserId($chat);

        AdminLog::record(
            action: 'support.archive',
            model: $chat,
            admin: $admin,
            before: ['is_archived' => false],
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

        $chat->update(['is_archived' => true]);

        broadcast(new SupportChatArchived(
            chatId: $chat->id,
            archivedById: $admin->id,
            archivedByName: $admin->name,
            archivedAt: now()->toIso8601String(),
        ))->toOthers();

        $this->invalidateStatsCache();

        return $this->message('Тикет архивирован.');
    }

    /**
     * POST /api/admin/support-chats/{chatId}/unarchive
     * Вернуть тикет из архива — ГЛОБАЛЬНО для всех staff.
     */
    public function unarchive(Request $request, int $chatId): JsonResponse
    {
        $admin = $request->user();
        $chat  = $this->findSupportChat($chatId);
        if (! $chat) {
            return $this->notFound('Чат');
        }
        if (! $chat->is_archived) {
            return $this->message('Тикет не в архиве.');
        }

        $userId = $this->getTicketUserId($chat);

        AdminLog::record(
            action: 'support.unarchive',
            model: $chat,
            admin: $admin,
            before: ['is_archived' => true],
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

        $chat->update(['is_archived' => false]);

        broadcast(new SupportChatUnarchived(
            chatId: $chat->id,
            unarchivedById: $admin->id,
            unarchivedByName: $admin->name,
            unarchivedAt: now()->toIso8601String(),
        ))->toOthers();

        $this->invalidateStatsCache();

        return $this->message('Тикет возвращен из архива.');
    }

    /**
     * POST /api/admin/support-chats/{chatId}/send
     *
     * Отправить сообщение от лица админа. БЕЗ проверки участия — любой staff
     * может написать в любой support-чат.
     *
     * Если чат был в архиве — AUTO-UNARCHIVE (reopen тикета).
     * Это стандартное поведение support systems (Freshdesk, Zendesk):
     * юзер ответил в архивный тикет → тикет переоткрывается.
     *
     * B5: проверка is_locked — нельзя писать в заблокированный чат.
     */
    public function sendMessage(SendSupportChatMessageRequest $request, int $chatId, SendSupportChatMessageAction $action): JsonResponse
    {
        $admin = $request->user();
        $chat  = $this->findSupportChat($chatId);
        if (! $chat) {
            return $this->notFound('Чат');
        }

        // B5: проверка is_locked.
        if ($chat->is_locked) {
            return $this->message('Чат заблокирован. Разблокируйте его, чтобы писать.', 422);
        }

        $body = trim($request->validated()['body']);

        // Бизнес-логика (транзакция, аудит, broadcast, кэш) — в Action.
        $wasArchived = $action->send($chat, $admin, $body);

        return $this->message(
            $wasArchived
                ? 'Сообщение отправлено. Тикет переоткрыт из архива.'
                : 'Сообщение отправлено',
            200,
            ['auto_unarchived' => $wasArchived],
        );
    }

    /**
     * POST /api/admin/support-chats/start/{userId}
     * Создать support-чат с пользователем (если ещё не существует).
     */
    public function startWithUser(Request $request, int $userId): JsonResponse
    {
        $user = User::withTrashed()->find($userId);
        if (! $user) {
            return $this->notFound('Пользователь');
        }
        if ($user->isStaff()) {
            return $this->message('Нельзя начать чат с сотрудником', 422);
        }

        $chat = Chat::getOrCreateSupportChat($user->id);

        return $this->message('Чат готов', 200, ['chat_id' => $chat->id]);
    }

    /**
     * GET /api/admin/support-chats/templates
     * Короткий список активных шаблонов для чата поддержки (без пагинации).
     */
    public function templates(Request $request): JsonResponse
    {
        $templates = Cache::rememberForever('admin_support_templates', function () {
            return SupportTemplate::with('category')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('title')
                ->get()
                ->map(fn ($t) => [
                    'id'       => $t->id,
                    'title'    => $t->title,
                    'body'     => $t->body,
                    'category' => $t->category?->name ?? 'Без категории',
                ])
                ->toArray();
        });

        return response()->json(['data' => $templates]);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    /**
     * Найти support-чат по ID с eager-load связей для show(),
     * либо вернуть null если чат не существует или не support-тип.
     *
     * Заменяет дубликат `if (!$chat || $chat->type !== Chat::TYPE_SUPPORT)`
     * который копипастился в 6 методах (show, markAsRead, archive, unarchive,
     * sendMessage, и т.д.).
     */
    private function findSupportChat(int $chatId): ?Chat
    {
        return Chat::support()
            ->with([
                'participants.user' => fn($q) => $q->withTrashed()->withAvatar(),
                'lastReadBy'        => fn($q) => $q->withTrashed()->select(['id', 'name']),
            ])
            ->find($chatId);
    }

    /**
     * ID юзера-владельца тикета (для аудита AdminLog).
     *
     * Берём НЕ-staff participant — в старых чатах могло быть [admin, user],
     * без фильтра получили бы admin. В новых чатах participant один = юзер.
     */
    private function getTicketUserId(Chat $chat): ?int
    {
        return $chat->participants()
            ->whereHas('user', fn($q) => $q->whereNotIn('role', ['admin', 'moderator', 'support']))
            ->value('user_id');
    }

    /**
     * Глобальные счётчики тикетов — ОДИНАКОВЫЕ для всех staff.
     * Cache key БЕЗ admin ID (раньше был per-admin — это была бага).
     */
    private function getStats(): array
    {
        return Cache::remember('admin_support_stats', 60, function () {
            $stats = Chat::support()
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

    /**
     * Инвалидация кэшей support-секции.
     *
     * Заменяет дубликат `Cache::forget('admin_support_stats'); Cache::forget('admin_sidebar_stats');`
     * который копипастился в markAsRead/archive/unarchive (3 раза).
     */
    private function invalidateStatsCache(): void
    {
        Cache::forget('admin_support_stats');
        Cache::forget('admin_sidebar_stats');
    }
} 