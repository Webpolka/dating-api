<?php

namespace App\Http\Controllers\Api\V1\Admin\Communication;

use App\Actions\V1\Admin\Communication\ToggleChatLockAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Models\Chat;
use App\Models\User;
use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Модерация чатов пользователей.
 *
 * Архитектура:
 *  - Список чатов: только type='private', где ВСЕ участники — обычные
 *    юзеры (role='user'). Чаты user↔staff (если такие есть) НЕ показываем
 *    — это служебные переписки, не подлежащие модерации здесь.
 *  - Фильтр: all / locked / unlocked
 *  - Поиск: по имени участника (ilike) или по ID чата
 *  - При поиске по ID — искомый чат всегда первым (ORDER BY CASE WHEN id = ? THEN 0)
 *  - Превью последнего сообщения: через latestMessage() HasOne-связь
 *    (latestOfMany) — корректно грузит 1 сообщение на каждый чат.
 *    ВАЖНО: нельзя использовать ->with(['messages' => fn($q) => $q->limit(1)])
 *    — это грузит 1 сообщение ВСЕГО на всю страницу (limit глобальный).
 *  - Детали чата: пагинация по курсору (?before={messageId}).
 *    Первая загрузка — последние 50 сообщений. Дальше — кнопкой
 *    «Загрузить ещё» подгружаем более старые пачками по 50.
 *  - Блокировка/разблокировка: toggle is_locked + системное сообщение + AdminLog
 *
 * Performance:
 *  - getCounts() кэшируется на 60с (TTL), инвалидируется при toggleLock.
 *  - Фильтр «только юзер-юзер» через whereDoesntHave(staff participant) —
 *    один EXISTS-подзапрос, не два вложенных как раньше.
 */
class ChatController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        // Обрезаем поиск до 100 символов — защита от гигантских строк,
        // которые замедляют LIKE-запрос и память.
        $search     = mb_substr($request->string('q', '')->toString(), 0, 100);
        $lockFilter = $request->string('lock', 'all')->toString();
        $perPage    = min(max((int) $request->integer('per_page', 20), 10), 100);
        $page       = max((int) $request->integer('page', 1), 1);

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
        ])
            ->orderByDesc('is_primary')
            ->limit(1);

        $query = Chat::where('type', Chat::TYPE_PRIVATE)
            // Только чаты, где НЕТ ни одного staff-участника.
            // Раньше было whereHas('participants.user', excludeStaff) —
            // это «есть ≥1 юзер», что пропускало user↔staff чаты.
            // Теперь: whereDoesntHave staff-участника = «все участники — юзеры».
            ->whereDoesntHave('participants', function ($q) {
                $q->whereHas('user', function ($uq) {
                    $uq->withTrashed()->where('role', '!=', User::ROLE_USER);
                });
            })
            ->when($lockFilter === 'locked',   fn($q) => $q->where('is_locked', true))
            ->when($lockFilter === 'unlocked', fn($q) => $q->where('is_locked', false))
            ->with([
                'participants' => fn($q) => $q->with(['user' => fn($uq) => $uq->withTrashed()->with(['photos' => $avatarQuery])]),
                // ✅ latestMessage вместо messages->limit(1) — корректно
                // грузит 1 последнее сообщение на каждый чат.
                'latestMessage' => fn($q) => $q->select([
                    'messages.id',
                    'messages.chat_id',
                    'messages.type',
                    'messages.body',
                    'messages.created_at',
                ]),
            ]);

        // Поиск: по имени участника или по ID чата
        if ($search !== '') {
            $query->where(function ($q) use ($search, $searchOperator, $isId) {
                $q->whereHas('participants.user', function ($sub) use ($search, $searchOperator) {
                    $sub->withTrashed()->where('name', $searchOperator, "%{$search}%");
                });
                if ($isId) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }

        // При поиске по ID — искомый чат ВСЕГДА первым
        $query->when($isId, function ($q) use ($search) {
            $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]);
        })
            ->orderBy('id', 'desc');

        $chats = $query->paginate($perPage, ['*'], 'page', $page);

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
            'counts' => $this->getCounts(),
        ]);
    }

    /**
     * Детали чата: участники + пагинация сообщений по курсору.
     *
     * Параметр ?before={messageId} (опционально):
     *  - НЕ передан (первая загрузка) — последние 50 сообщений.
     *  - Передан — 50 сообщений с id < before (более старые).
     *
     * В ответе:
     *  - meta.has_more — есть ли ещё более старые сообщения (для кнопки)
     *  - meta.oldest_id — id самого старого сообщения в пачке (для следующего запроса)
     *
     * Фронт при подгрузке делает запрос /chats/{id}?before={oldest_id},
     * получает 50 старых сообщений, добавляет их ВВЕРХ списка.
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
        ])
            ->orderByDesc('is_primary')
            ->limit(1);

        $chat = Chat::with([
            'participants' => fn($q) => $q->with(['user' => fn($uq) => $uq->withTrashed()->with(['photos' => $avatarQuery])]),
        ])->find($chatId);

        if (!$chat) {
            return response()->json(['message' => 'Чат не найден'], 404);
        }

        // Защита: этот endpoint только для private-чатов юзеров.
        // Support-чаты читаются через /admin/support-chats/{id}.
        // Раньше можно было передать ID support-чата и прочитать его здесь.
        if ($chat->type !== Chat::TYPE_PRIVATE) {
            return response()->json(['message' => 'Чат не найден'], 404);
        }

        // Защита: в чате не должно быть staff-участников.
        // Список чатов в index() фильтрует такие через whereDoesntHave,
        // но show() принимает любой ID — нужно проверить явно.
        // Если в participants есть staff → 404 (не раскрываем существование).
        $hasStaffParticipant = $chat->participants->contains(fn($p) => $p->user && $p->user->isStaff());
        if ($hasStaffParticipant) {
            return response()->json(['message' => 'Чат не найден'], 404);
        }

        // === Пагинация сообщений по курсору ===
        // before — id сообщения, РАНЬШЕ которого грузим (не включительно).
        // Если не передан — первые 50 (самые свежие).
        $beforeId = $request->integer('before');
        $perPage  = 50;

        $messagesQuery = $chat->messages()
            ->with([
                'sender' => fn($sq) => $sq->withTrashed()->with(['photos' => $avatarQuery]),
                'gift:id,name,image_url',
            ])
            ->when($beforeId > 0, fn($q) => $q->where('id', '<', $beforeId))
            ->orderByDesc('id')
            ->limit($perPage + 1); // +1 чтобы узнать, есть ли ещё (has_more)

        $messages = $messagesQuery->get();

        // Если вернулось больше perPage — значит есть ещё старые.
        // Лишнюю запись (perPage+1) убираем, она нужна была только как флаг.
        $hasMore = $messages->count() > $perPage;
        if ($hasMore) {
            $messages = $messages->take($perPage);
        }

        // Сортируем по created_at ASC для фронтенда (старые вверху, новые внизу).
        $messages = $messages->sortBy('created_at')->values();

        $oldestId = $messages->isNotEmpty() ? $messages->first()->id : null;

        return response()->json([
            'data' => array_merge(
                $this->formatChatDetail($chat),
                [
                    'messages' => $messages->map(fn($m) => [
                        'id'             => $m->id,
                        'type'           => $m->type,
                        'body'           => $m->body,
                        'attachment_url' => $m->attachment_url,
                        'status'         => $m->status,
                        'created_at'     => $m->created_at?->toIso8601String(),
                        'sender'         => $m->sender ? AdminUserBadgeResource::nullable($m->sender) : null,
                        // system_action — для system messages: 'lock' | 'unlock' | null.
                        // Фронт использует это для цветовой дифференциации (red/green/blue)
                        // вместо fragile text matching по body. Парсим body здесь, в бэке —
                        // централизованно. Если текст изменится — правим в одном месте.
                        'system_action'  => $m->type === 'system' ? $this->detectSystemAction($m->body) : null,
                        'gift'           => $m->gift ? [
                            'id'        => $m->gift->id,
                            'name'      => $m->gift->name,
                            'image_url' => $m->gift->image_url,
                        ] : null,
                    ])->toArray(),
                    'messages_meta' => [
                        'has_more'  => $hasMore,
                        'oldest_id' => $oldestId,
                    ],
                ]
            ),
        ]);
    }

    /**
     * Блокировка/разблокировка чата админом.
     * Бизнес-логика (транзакция, системное сообщение, AdminLog, кэш) —
     * в ToggleChatLockAction::toggle().
     */
    public function toggleLock(Request $request, int $chatId, ToggleChatLockAction $action): JsonResponse
    {
        $admin = $request->user();
        $chat  = Chat::find($chatId);
        if (!$chat) {
            return response()->json(['message' => 'Чат не найден'], 404);
        }

        // Защита: toggleLock только для private-чатов.
        // Support-чаты имеют свой flow (archive/unarchive через SupportChatController).
        if ($chat->type !== Chat::TYPE_PRIVATE) {
            return response()->json(['message' => 'Чат не найден'], 404);
        }

        // Action мутирует $chat (is_locked, last_message_at) —
        // состояние модели доступно для ответа ниже.
        $action->toggle($chat, $admin);

        return response()->json([
            'message'    => $chat->is_locked
                ? 'Чат заблокирован. Общение остановлено.'
                : 'Чат разблокирован.',
            'is_locked' => (bool) $chat->is_locked,
        ]);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getCounts(): array
    {
        return Cache::remember('admin_chat_stats', 60, function () {
            $baseQuery = Chat::where('type', Chat::TYPE_PRIVATE)
                ->whereDoesntHave('participants', function ($q) {
                    $q->whereHas('user', function ($uq) {
                        $uq->withTrashed()->where('role', '!=', User::ROLE_USER);
                    });
                });

            $stats = (clone $baseQuery)
                ->selectRaw("COUNT(*) as total")
                ->selectRaw("SUM(CASE WHEN is_locked = true THEN 1 ELSE 0 END) as locked")
                ->first();

            $total  = (int) ($stats?->total ?? 0);
            $locked = (int) ($stats?->locked ?? 0);

            return [
                'total'    => $total,
                'locked'   => $locked,
                'unlocked' => $total - $locked,
            ];
        });
    }

    private function formatChatListItem(Chat $chat): array
    {
        $u1 = $chat->participants->get(0)?->user;
        $u2 = $chat->participants->get(1)?->user;
        // ✅ latestMessage — корректная HasOne-связь (1 на чат).
        $lastMsg = $chat->latestMessage;

        return [
            'id'               => $chat->id,
            'is_locked'        => (bool) $chat->is_locked,
            'last_message_at'  => $chat->last_message_at?->toIso8601String(),
            'participants'     => [
                AdminUserBadgeResource::nullable($u1),
                AdminUserBadgeResource::nullable($u2),
            ],
            'last_message' => $lastMsg ? [
                'id'         => $lastMsg->id,
                'type'       => $lastMsg->type,
                'body'       => $lastMsg->body,
                'created_at' => $lastMsg->created_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * Форматирует чат без сообщений (для show() — сообщения грузятся отдельно
     * с пагинацией, здесь только метаданные чата + участники).
     */
    private function formatChatDetail(Chat $chat): array
    {
        $u1 = $chat->participants->get(0)?->user;
        $u2 = $chat->participants->get(1)?->user;

        return [
            'id'              => $chat->id,
            'is_locked'       => (bool) $chat->is_locked,
            'last_message_at' => $chat->last_message_at?->toIso8601String(),
            'participants'    => [
                AdminUserBadgeResource::nullable($u1),
                AdminUserBadgeResource::nullable($u2),
            ],
        ];
    }


    /**
     * Определить action системного сообщения по body.
     *
     * Возвращает 'lock' | 'unlock' | null.
     * Используется в show() для поля system_action — фронт использует
     * это вместо fragile text matching по body.
     *
     * Централизованный парсинг — если текст системных сообщений изменится,
     * правим только здесь (не на фронте).
     *
     * Сейчас распознаёт:
     *   - "Чат заблокирован администрацией." → 'lock'
     *   - "Чат разблокирован администрацией." → 'unlock'
     *   - Любое другое system message → null (синий цвет по умолчанию)
     */
    private function detectSystemAction(?string $body): ?string
    {
        if (!$body) return null;
        if (str_contains($body, 'заблокирован')) return 'lock';
        if (str_contains($body, 'разблокирован')) return 'unlock';
        return null;
    }
}
