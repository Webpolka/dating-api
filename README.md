# DatingApp — запуск всех сервисов

psql -h 127.0.0.1 -U devuser -d dating_data

> Все сервисы для разработки: Redis, Mailpit, Horizon, Reverb, Pulse, Laravel, Next.js
> Управляются одной командой. Запустил — кодишь. Остановил — память свободна.

## Архитектура сервисов

| Сервис          | Порт         | Зачем                     | Управление                       |
| --------------- | ------------ | ------------------------- | -------------------------------- |
| **Redis**       | 6379         | Кэш, очереди, broadcast   | `redis-start` / `redis-stop`     |
| **Mailpit**     | 1025 / 8025  | Ловля писем               | `mailpit-start` / `mailpit-stop` |
| **Horizon**     | 8000/horizon | Очереди (jobs)            | `horizon-start` / `horizon-stop` |
| **Reverb**      | 8080         | WebSocket (чат, realtime) | `reverb-start` / `reverb-stop`   |
| **Pulse**       | 8000/pulse   | Мониторинг метрик         | `pulse-start` / `pulse-stop`     |
| **Laravel API** | 8000         | Backend                   | `api-start` / `api-stop`         |
| **Next.js**     | 3000         | Frontend                  | `web-start` / `web-stop`         |

## Полный набор алиасов для `~/.bashrc`

Скопируй **всё** в `~/.bashrc`:

```bash
nano ~/.bashrc
```

Вставь в конец:

```bash
# ============================================
# DATINGAPP — сервисы разработки
# ============================================

# --- Redis (кэш, очереди, broadcast) ---
alias redis-start='sudo fuser -k 6379/tcp 2>/dev/null; sleep 1; sudo service redis-server start && echo "Redis started"'
alias redis-stop='sudo service redis-server stop 2>/dev/null; sudo fuser -k 6379/tcp 2>/dev/null; echo "Redis stopped"'
alias redis-status='redis-cli ping 2>/dev/null && echo "Redis is running" || echo "Redis is stopped"'
alias redis-gui='(~/Applications/ardm.AppImage &>/dev/null &) && echo "ARDM started"'
alias redis-gui-stop='pkill -f "ardm.AppImage" 2>/dev/null; echo "ARDM stopped"'

# --- Mailpit (ловля писем) ---
alias mailpit-start='(nohup mailpit > /tmp/mailpit.log 2>&1 &) && echo "Mailpit: http://localhost:8025"'
alias mailpit-stop='pkill -f mailpit 2>/dev/null; echo "Mailpit stopped"'
alias mailpit-status='pgrep -af mailpit | head -3 || echo "Mailpit is stopped"'
alias mailpit-open='xdg-open http://localhost:8025'
alias mailpit-log='tail -f /tmp/mailpit.log'

# --- Horizon (очереди) ---
alias horizon-start='(cd ~/projects/dating-api && nohup php artisan horizon > /tmp/horizon.log 2>&1 &) && sleep 1 && echo "Horizon: http://localhost:8000/horizon"'
alias horizon-stop='cd ~/projects/dating-api && php artisan horizon:terminate 2>/dev/null; pkill -f "artisan horizon" 2>/dev/null; echo "Horizon stopped"'
alias horizon-status='pgrep -af "artisan horizon" | head -3 || echo "Horizon is stopped"'
alias horizon-open='xdg-open http://localhost:8000/horizon'
alias horizon-log='tail -f /tmp/horizon.log'

# --- Reverb (WebSocket, realtime) ---
alias reverb-start='(cd ~/projects/dating-api && nohup php artisan reverb:start --debug > /tmp/reverb.log 2>&1 &) && sleep 1 && echo "Reverb: ws://localhost:8080"'
alias reverb-stop='pkill -f "reverb:start" 2>/dev/null; echo "Reverb stopped"'
alias reverb-status='pgrep -af "reverb:start" | head -3 || echo "Reverb is stopped"'
alias reverb-log='tail -f /tmp/reverb.log'

# --- Pulse (мониторинг метрик) ---
alias pulse-start='(cd ~/projects/dating-api && nohup php artisan pulse:work > /tmp/pulse.log 2>&1 &) && sleep 1 && echo "Pulse: http://localhost:8000/pulse"'
alias pulse-stop='pkill -f "pulse:work" 2>/dev/null; echo "Pulse stopped"'
alias pulse-status='pgrep -af "pulse:work" | head -3 || echo "Pulse is stopped"'
alias pulse-open='xdg-open http://localhost:8000/pulse'
alias pulse-log='tail -f /tmp/pulse.log'

# --- Telescope (убрали — дублирует Pulse, жрёт память) ---
# Если когда-нибудь понадобится — поставишь через:
# composer require laravel/telescope --dev && php artisan telescope:install

# --- Laravel API ---
alias api-start='(cd ~/projects/dating-api && nohup php artisan serve > /tmp/api.log 2>&1 &) && sleep 1 && echo "API: http://localhost:8000"'
alias api-stop='pkill -f "artisan serve" 2>/dev/null; echo "API stopped"'
alias api-status='pgrep -af "artisan serve" | head -3 || echo "API is stopped"'
alias api-log='tail -f /tmp/api.log'
alias api-open='xdg-open http://localhost:8000'

# --- Next.js Frontend ---
alias web-start='(cd ~/projects/dating-web && nohup pnpm dev > /tmp/web.log 2>&1 &) && sleep 2 && echo "Web: http://localhost:3000"'
alias web-stop='pkill -f "next dev" 2>/dev/null; pkill -f "pnpm dev" 2>/dev/null; echo "Web stopped"'
alias web-status='pgrep -af "next dev" | head -3 || echo "Web is stopped"'
alias web-open='xdg-open http://localhost:3000'
alias web-log='tail -f /tmp/web.log'

# ============================================
# ГЛОБАЛЬНЫЕ КОМАНДЫ
# ============================================

# Запустить ВСЁ одной командой (бекенд + фронтенд + сервисы)
# Используем ; вместо && чтобы каждый сервис стартовал независимо
alias dev-start='redis-start; mailpit-start; sleep 1; horizon-start; reverb-start; pulse-start; sleep 1; api-start; web-start; echo ""; echo "🚀 Все сервисы запущены"; echo "   API:      http://localhost:8000"; echo "   Web:      http://localhost:3000"; echo "   Mailpit:  http://localhost:8025"; echo "   Horizon:  http://localhost:8000/horizon"; echo "   Pulse:    http://localhost:8000/pulse"'

# Остановить ВСЁ одной командой
alias dev-stop='web-stop; api-stop; pulse-stop; reverb-stop; horizon-stop; mailpit-stop; redis-stop; echo ""; echo "🛑 Все сервисы остановлены"'

# Статус ВСЕХ сервисов одной командой
# ВАЖНО: все pgrep pattern'ы в кавычках, иначе pgrep ругается на несколько аргументов
alias dev-status='echo "=== Сервисы ===" && echo "Redis:    $(redis-cli ping 2>/dev/null || echo stopped)" && echo "Mailpit:  $(pgrep -f "mailpit" > /dev/null && echo running || echo stopped)" && echo "Horizon:  $(pgrep -f "artisan horizon" > /dev/null && echo running || echo stopped)" && echo "Reverb:   $(pgrep -f "reverb:start" > /dev/null && echo running || echo stopped)" && echo "Pulse:    $(pgrep -f "pulse:work" > /dev/null && echo running || echo stopped)" && echo "API:      $(pgrep -f "artisan serve" > /dev/null && echo running || echo stopped)" && echo "Web:      $(pgrep -f "next dev" > /dev/null && echo running || echo stopped)"'

# Открыть все дашборды в браузере
alias dev-open='mailpit-open; horizon-open; pulse-open; api-open; web-open'

# Логи всех сервисов (по очереди Ctrl+C для выхода)
alias dev-logs='echo "Хочешь конкретный лог?:" && echo "  mailpit-log" && echo "  horizon-log" && echo "  reverb-log" && echo "  pulse-log" && echo "  api-log" && echo "  web-log"'

# Очистить кэш Laravel
alias cc='cd ~/projects/dating-api && php artisan optimize:clear && echo "Кэш очищен"'

# Tinker
alias tinker='cd ~/projects/dating-api && php artisan tinker'

# Свежая миграция с сидами (ОСТОРОЖНО — удаляет данные)
alias mfs='cd ~/projects/dating-api && php artisan migrate:fresh --seed'
alias dbfresh='cd ~/projects/dating-api && php artisan migrate:fresh && php artisan geo:import && php artisan db:seed'
```

Применить:

```bash
source ~/.bashrc
```

## Главные команды

```bash
# 🚀 Запустить всё одной командой
dev-start

# 🛑 Остановить всё
dev-stop

# 📊 Статус всех сервисов
dev-status

# 🌐 Открыть все дашборды
dev-open
```

## Что делает `dev-start`

Запускает по очереди:

1. **Redis** (через `service`)
2. **Mailpit** (в фоне через `nohup`)
3. **Horizon** (в фоне через `nohup`)
4. **Reverb** (в фоне через `nohup`)
5. **Pulse** (в фоне через `nohup`)
6. **Laravel API** (`php artisan serve`)
7. **Next.js** (`pnpm dev`)

После запуска выводит все URL'ы:

```
🚀 Все сервисы запущены
   API:      http://localhost:8000
   Web:      http://localhost:3000
   Mailpit:  http://localhost:8025
   Horizon:  http://localhost:8000/horizon
   Pulse:    http://localhost:8000/pulse
```

## Что делает `dev-stop`

Останавливает в обратном порядке:

1. Next.js
2. Laravel API
3. Pulse
4. Reverb
5. Horizon
6. Mailpit
7. Redis

Память полностью освобождается.

## Что делает `dev-status`

Показывает состояние всех сервисов одной строкой:

```
=== Сервисы ===
Redis:    PONG
Mailpit:  running
Horizon:  running
Reverb:   running
Pulse:    running
API:      running
Web:      running
```

## Ежедневный workflow

### Утром — начал кодить

```bash
dev-start        # запустили всё
dev-status       # проверили что всё работает
dev-open         # открыли дашборды в браузере
```

### В течение дня

```bash
api-log          # смотреть логи API
horizon-log      # смотреть логи очередей
cc               # очистить кэш если что-то странное
tinker           # залезть в tinker
```

### Вечером — закончил

```bash
dev-stop         # остановили всё
```

Память свободна, портов не занято.

## Индивидуальные команды

Если нужно запустить только что-то конкретное:

```bash
# Сервисы
redis-start / redis-stop / redis-status / redis-gui
mailpit-start / mailpit-stop / mailpit-open
horizon-start / horizon-stop / horizon-open
reverb-start / reverb-stop / reverb-status
pulse-start / pulse-stop / pulse-open

# Серверы
api-start / api-stop / api-open
web-start / web-stop / web-open

# Логи (Ctrl+C для выхода)
redis-log
mailpit-log
horizon-log
reverb-log
pulse-log
api-log
web-log

# Утилиты
cc          # очистить кэш Laravel
tinker      # войти в tinker
mfs         # свежая миграция с сидами (ОСТОРОЖНО)
```

## Диагностика

### Что-то не запускается

```bash
# 1. Проверь статус всех
dev-status

# 2. Посмотри лог проблемного сервиса
# Например если не запускается API:
api-log

# 3. Останови и запусти заново
dev-stop
dev-start
```

### Зависшие процессы

```bash
# Убить все PHP-процессы Laravel
pkill -f "artisan"

# Убить все node-процессы (ВНИМАНИЕ — убьёт все node, включая VS Code)
# Лучше конкретно:
pkill -f "next dev"
pkill -f "pnpm dev"

# Полный сброс
dev-stop
pkill -f "artisan"
pkill -f "next dev"
pkill -f mailpit
sudo fuser -k 6379/tcp
dev-start
```

### Конфликт портов

```bash
# Что заняло порт 8000?
sudo lsof -i :8000
sudo fuser -k 8000/tcp

# Что заняло порт 3000?
sudo lsof -i :3000
sudo fuser -k 3000/tcp

# Что заняло порт 8080?
sudo lsof -i :8080
sudo fuser -k 8080/tcp
```

## Чек-лист установки (если всё с нуля)

- [ ] Redis установлен: `redis-cli ping` → PONG
- [ ] Mailpit установлен: `which mailpit` → `/usr/local/bin/mailpit`
- [ ] Composer пакеты: `composer show laravel/horizon laravel/reverb laravel/pulse`
- [ ] `.env` настроен: `grep -E "QUEUE|BROADCAST|CACHE|MAIL|REVERB" .env`
- [ ] Миграции: `php artisan migrate` без ошибок
- [ ] ARDM скачан: `ls ~/Applications/ardm.AppImage`
- [ ] Next.js зависимости: `cd ~/projects/dating-web && pnpm install`
- [ ] Алиасы в `~/.bashrc` добавлены
- [ ] `source ~/.bashrc` выполнен
- [ ] `dev-start` работает
- [ ] `dev-status` показывает все running
- [ ] `dev-stop` освобождает память

## Структура файлов шпаргалок

Все шпаргалки в `/home/z/my-project/download/`:

| Файл                          | Сервис                                |
| ----------------------------- | ------------------------------------- |
| `redis-cheatsheet-final.md`   | Redis + ARDM GUI                      |
| `mailpit-cheatsheet-short.md` | Mailpit (письма)                      |
| `horizon-cheatsheet-short.md` | Horizon (очереди)                     |
| `reverb-cheatsheet-short.md`  | Reverb (WebSocket realtime)           |
| `pulse-cheatsheet-short.md`   | Pulse (мониторинг метрик)             |
| `dev-services-all-in-one.md`  | **Эта** — запуск всего одной командой |

Подробная документация по сервисам, окружению и гео-данным — в [`docs/`](docs/README.md)
(индекс: `docs/README.md`). Журнал структурного рефакторинга — [`REFACTORING.md`](REFACTORING.md).

## TL;DR

```bash
# Утром
dev-start

# Вечером
dev-stop
```

Всё. Остальное — по необходимости.
