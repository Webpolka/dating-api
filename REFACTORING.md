# REFACTORING — Часть 1: структура бэкенда (API)

Дата: 2026-11-13
Объём: 320 → 393 PHP-файла; все изменения проверены `php -l` (0 синтаксических ошибок)
и PSR-4-аудитом (namespace = путь файла). URL всех эндпоинтов и форматы JSON-ответов
сохранены без изменений — админ-фронтенд не требует доработок.

---

## Итоговая структура `app/`

```
app/
├── Actions/
│   ├── Admin/
│   │   ├── Content/      ManageGiftAction, ManageBlogPostAction
│   │   ├── Moderation/   ModeratePhoto/PhotoComment/Diary/DiaryComment/Report/
│   │   │                 Dating/StopWord/BlockSignal, ManageDiaryRubricAction,
│   │   │                 ResolveFraudAlertAction
│   │   ├── Support/      SendSupportChatMessageAction, ToggleChatLockAction
│   │   ├── System/       ManageStaffAction, ManageSettingsAction
│   │   └── Users/        DeleteUser, ToggleUserBan, ManageUserProfile/Sessions/Blocks
│   └── Api/              SendGiftAction (пользовательское API)
├── Console/Commands/     (без изменений)
├── DTO/                  ContentFilterResult (бывш. Services/)
├── Enums/                (без изменений)
├── Events/               (без изменений)
├── Http/
│   ├── Controllers/
│   │   ├── AdminController.php   ← НОВЫЙ базовый класс админ-контроллеров
│   │   ├── Admin/V1/
│   │   │   ├── Auth/             AuthController
│   │   │   ├── Users/            UserController
│   │   │   ├── Moderation/       Photo, PhotoComment, Diary, DiaryComment, Report,
│   │   │   │                     Dating, BlockSignal, FraudAlert, StopWord
│   │   │   ├── Content/          Blog*, Page, Gift, Broadcast
│   │   │   ├── Finance/          Transaction, SubscriptionPlan
│   │   │   ├── Support/          SupportChat, SupportTemplate, Chat
│   │   │   └── System/           Dashboard, Setting, Staff, AdminLog, Log, System,
│   │   │                         Media, GeoIPLocation
│   │   └── Api/V1/               13 пользовательских контроллеров
│   ├── Middleware/       (без изменений)
│   ├── Requests/
│   │   ├── Admin/V1/{Users,Moderation,Content,Finance,Auth,Support,System}/
│   │   └── V1/                    пользовательские FormRequests
│   └── Resources/
│       └── Admin/V1/Users/        UserBadge, UserDetail, Photo,
│                                 AdminUserBadgeResource (новый)
├── Jobs/ Listeners/ Models/ Notifications/ Observers/   (без изменений)
├── Providers/
│   ├── AppServiceProvider.php        (544 → 85 строк)
│   └── RateLimitServiceProvider.php  (новый, ~500 строк)
├── Services/
│   ├── Admin/            AdminAuthService, AdminLogger (новый)
│   ├── Moderation/       CommentTreeService (новый)
│   └── ...               (остальные без изменений)
└── Support/              UserAgentParser (бывш. Helpers/)
```

---

## Этап 1. Документация → `docs/`

- `GEONAMES.md` → `docs/geo/geonames.md`
- `HORIZON|MAILPIT|PULSE|PURIFIER|REDIS|REVERB.md` → `docs/services/*.md`
- `Z_INFO/` → `docs/environment/` (`storage-sheme.txt` → `storage-scheme.txt`)
- Создан индекс `docs/README.md`; README в корне ссылается на docs.
- `AGENTS.md` / `CLAUDE.md` осознанно оставлены в корне: их ждёт AI-tooling по фиксированному пути.

## Этап 2. Валидация → FormRequests (49 конвертаций)

Все `$request->validate([...])` в контроллерах заменены на типизированные
FormRequests: 48 новых классов + переиспользован `UserListRequest`.
Размещение повторяет доменную структуру контроллеров
(`app/Http/Requests/Admin/V1/<Домен>/`, `app/Http/Requests/V1/`).
Правила скопированы дословно; нюансы `unique()->ignore()` решены через
`$this->route('id')` (паттерн существующего `BlogPostRequest`).

Остались инлайн: `SettingController`/`PageController` (Validator-фасад) и
`SubscriptionController::pay/renew` (ручной формат 422-ответа) — их конвертация
изменила бы формат ошибок, оставлено как есть.

## Этап 3. Ответы и дублирование

- `AdminUserBadgeResource` (флаги `withRole` / `withIsStaff`) заменил 8 разошедшихся
  приватных `formatUser()`/`formatUserShort()` (Dating, Report, Diary, DiaryComment,
  PhotoComment, Chat, Gift, SupportChat).
- `AdminController` — базовый класс с `notFound()` (все 29 админ-контроллеров
  наследуют его; BlogCategoryController ранее вообще не имел базового класса).
- `App\Services\Admin\AdminLogger::logCommentModeration()` заменил 2 копии `logAction()`.
- `App\Services\Moderation\CommentTreeService` заменил дубли `collectDescendantIds()`
  и `buildCascadeLog()` (BFS-обход дерева комментариев + каскадные логи).
- `formatChatListItem()` в Chat/SupportChat контроллерах оставлен — это разные
  структуры (2 участника vs partner+staff-поля), не дубликат.

## Этап 4+7. Неймспейсы и домены (Great Move, 66 файлов)

- Admin-контроллеры: `App\Http\Controllers\Api\Admin\*` → `App\Http\Controllers\Admin\V1\<Домен>\*`
- Пользовательские: `App\Http\Controllers\Api\*` → `App\Http\Controllers\Api\V1\*`
- Requests/Resources/Actions переехали в зеркальные доменные подпапки.
- `AdminAuthService` → `Services/Admin/`; `ContentFilterResult` → `App\DTO`;
  `UserAgentParser` → `App\Support`.
- Все ссылки в `routes/`, `app/`, `database/`, `tests/` переписаны скриптом с
  аудитом замен; URL эндпоинтов не менялись.

## Этап 5. Бизнес-логика → Actions (14 контроллеров, 26 транзакций)

Волна 1 (полное извлечение):
| Контроллер | Было | Стало | Action |
|---|---|---|---|
| DiaryController | 790 | 450 | ModerateDiaryAction + ManageDiaryRubricAction |
| DiaryCommentController | 678 | 363 | ModerateDiaryCommentAction |
| ReportController | 533 | 302 | ModerateReportAction (композиция ToggleUserBan + ModeratePhoto) |

Волна 2:
| Контроллер | Было | Стало | Action |
|---|---|---|---|
| PhotoCommentController | 972 | 385 | ModeratePhotoCommentAction (вкл. bulk) |
| StopWordController | 477 | 251 | ModerateStopWordAction |
| FraudAlertController | 376 | 265 | ResolveFraudAlertAction |
| BlockSignalController | 396 | 338 | ModerateBlockSignalAction |
| GiftController (admin) | 480 | 332 | ManageGiftAction |
| BlogPostController | 445 | 247 | ManageBlogPostAction |
| SupportChatController | 619 | 548 | SendSupportChatMessageAction |
| ChatController | 415 | 366 | ToggleChatLockAction |
| StaffController | 269 | 193 | ManageStaffAction |
| SettingController | 175 | 122 | ManageSettingsAction |
| GiftController (api) | 122 | 101 | SendGiftAction (App\Actions\Api) |

Итого 15 новых Action-классов (~4 000 строк бизнес-логики). В контроллерах не осталось
ни одного `DB::transaction` (проверено grep'ом) — только резолюция моделей, вызов
Action и формирование ответа. AdminLog-форматы, уведомления, кэш-ключи и тексты
ответов сохранены дословно.

## Этап 6. Провайдеры

`AppServiceProvider` (544 строки) разделён: SQL-дебаггер, observers и password-reset
остались (85 строк); все ~20 rate-limiter'ов переехали в новый
`App\Providers\RateLimitServiceProvider` (зарегистрирован в `bootstrap/providers.php`).

## Этап 8. Фабрики и тесты

- Фабрики: `UserFactory` (+states `admin/moderator/support/banned`), новые
  `PhotoFactory` (+`approved/rejected`), `SubscriptionPlanFactory` (+`vip/inactive`).
- Feature-тесты критических флоу (19 кейсов):
  - `tests/Feature/Admin/UserBanTest.php` — RBAC, permanent/temp/shadow, идемпотентность, AdminLog, уведомления.
  - `tests/Feature/Admin/PhotoModerationTest.php` — RBAC, approve (Queue::fake → ProcessApprovedPhoto), reject, системные причины, 404.
  - `tests/Feature/Api/SubscriptionPaymentTest.php` — создание транзакции, защита от дубля tier, репокупка после истечения, валидация.

Запуск: `composer install && php artisan test`. Требования: БД `testing`
(PostGIS-совместимая, как в compose), `CACHE_STORE=array`/`QUEUE_CONNECTION=sync`
уже заданы в phpunit.xml.

---

## Найденные и исправленные баги

1. **Висячий роут** `POST /api/admin/photo-comments/{id}/spam` → метод `markSpam`
   не существует (удалён ранее по «Варианту A»). Роут убран — раньше любой его вызов
   давал 500.
2. **BlogCategoryController не наследовал** `Controller` (голый класс) — подключён
   к `AdminController`.
3. **Кандидат на баг (НЕ исправлен, требует решения)**: `SubscriptionController::pay`
   подставляет `route('home')` как дефолт `return_url`, но именованного роута `home`
   в API-приложении нет → `RouteNotFoundException` при запросе без `return_url`.
   Фронт всегда передаёт return_url, поэтому прод не падает. Рекомендация: заменить
   на `config('app.frontend_url')`.

## Осознанно оставленное

- `.phpstorm.meta.php` содержит ссылки на старые FQCN — регенерируй
  `php artisan ide-helper:generate` после `composer install`.
- `support-chats` markAsRead/archive/unarchive остались в контроллере
  (нетранзакционные, один класс сущности) — кандидат на извлечение при следующем проходе.
- Инлайн-валидация `POST /api/settings/theme` (роут-замыкание) — FormRequest
  к замыканию не привязать без выноса в контроллер.

## Как развернуть

```bash
composer install          # + composer dump-autoload (неймспейсы уже PSR-4-валидны)
php artisan test          # 19 новых флоу-тестов + examples
vendor/bin/pint           # опционально: нормализация стиля импортов
php artisan ide-helper:generate   # обновить .phpstorm.meta.php
```

## Дополнение (фронд-часть 2): пер-юзер листинги для табов админки

Для страницы пользователя `/users/{id}` админки добавлены 13 read-only
эндпоинтов (все GET, без мутаций — действия остаются в глобальных разделах,
где throttle и RBAC):

| Эндпоинт | Контроллер | Роли |
|---|---|---|
| `GET /users/{user}/photo-comments` | UserListingsController::photoComments | admin, moderator |
| `GET /users/{user}/diaries` | ::diaries | admin, moderator |
| `GET /users/{user}/diary-comments` | ::diaryComments | admin, moderator |
| `GET /users/{user}/dating` | ::dating (swipes + matches, две пагинации) | admin, moderator |
| `GET /users/{user}/chats` | ::chats | admin, moderator |
| `GET /users/{user}/events` | ::events | admin, moderator |
| `GET /users/{user}/transactions` | ::transactions | admin |
| `GET /users/{user}/gifts` | ::gifts (sent + received) | admin |
| `GET /users/{user}/broadcasts` | ::broadcasts (персональные) | admin |
| `GET /users/{user}/admin-logs` | ::adminLogs (все действия, не только баны) | admin |
| `GET /users/{user}/cards` | ::cards (маскированные, без токенов) | admin |
| `GET /users/{user}/promocodes` | ::promocodes | admin |
| `GET /users/{user}/auth-logs` | ::authLogs | admin |

Новые файлы:

- `app/Http/Controllers/Admin/V1/Users/UserListingsController.php` —
  все 13 методов в стиле UserController (404-гард, плоский пагинатор,
  accessor-безопасные селекты, форматы 1:1 с глобальными списками).
- `app/Http/Requests/Admin/V1/Users/`: UserPhotoCommentsRequest,
  UserDiariesRequest, UserDiaryCommentsRequest, UserDatingRequest,
  UserTransactionsRequest, UserGiftsRequest, UserAuthLogsRequest
  (все наследуют UserListRequest).
- `routes/admin.php` — маршруты в группах по RBAC (см. таблицу).

Изменений в существующих контроллерах/ответах НЕТ — чисто аддитивно.
