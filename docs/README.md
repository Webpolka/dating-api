# Документация проекта

Единый каталог всей документации API (backend). Корень репозитория держим чистым:
только `README.md` (ранбук по запуску сервисов) и `AGENTS.md` / `CLAUDE.md`
(инструкции для AI-агентов — они должны лежать в корне, tooling ищет их там).

## Структура

```
docs/
├── README.md            ← этот индекс
├── environment/         ← окружение, env-переменные, схема storage
├── geo/                 ← гео-данные (GeoNames, импорт стран/городов)
├── services/            ← документация по инфраструктурным сервисам
└── refactoring/         ← журналы рефакторинга
```

## Окружение

| Документ | О чём |
| --- | --- |
| [environment/PART-1.env и окружение.md](environment/PART-1.env%20и%20окружение.md) | Полный разбор всех переменных `.env`, часть 1 |
| [environment/storage-scheme.txt](environment/storage-scheme.txt) | Схема каталогов `storage/` |

## Гео-данные

| Документ | О чём |
| --- | --- |
| [geo/geonames.md](geo/geonames.md) | Импорт гео-данных из GeoNames (страны/регионы/города, переводы) |

## Сервисы

| Документ | О чём |
| --- | --- |
| [services/redis.md](services/redis.md) | Redis: кэш, очереди, broadcast, ключи и TTL |
| [services/horizon.md](services/horizon.md) | Horizon: супервизоры очередей, настройка, мониторинг |
| [services/reverb.md](services/reverb.md) | Reverb: WebSocket-сервер (чат, realtime-события) |
| [services/mailpit.md](services/mailpit.md) | Mailpit: ловля писем в dev-окружении |
| [services/pulse.md](services/pulse.md) | Pulse: мониторинг метрик приложения |
| [services/purifier.md](services/purifier.md) | HTML Purifier: очистка пользовательского HTML |

## Рефакторинг

| Документ | О чём |
| --- | --- |
| [../REFACTORING.md](../REFACTORING.md) | Журнал структурного рефакторинга (этапы, маппинг переносов) |

## Правила

- Новая документация кладётся в `docs/` в подходящий подраздел (или создаётся новый).
- Один документ = одна тема. Имя файла — kebab-case, латиницей (кроме уже существующих
  русскоязычных имён, сохранённых для совместимости ссылок).
- Ссылки на документы добавляются в этот индекс.
