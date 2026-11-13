# Reverb — шпаргалка

> WebSocket-сервер Laravel для realtime-функций: чат, онлайн-статус,
> уведомления в реальном времени, push-события.
> Запускаешь когда работаешь — останавливаешь когда закончил.

## Что даёт

| Функция | Что работает в realtime |
|---------|------------------------|
| Чат | Сообщения приходят мгновенно, без перезагрузки |
| Онлайн-статус | Видишь когда юзер онлайн/оффлайн |
| Уведомления | Лайки, матчи, новые сообщения — push в браузер |
| Typing indicator | Видишь когда собеседник печатает |
| Read receipts | Видишь когда сообщение прочитано |

## Установка (один раз)

```bash
cd ~/projects/dating-api
composer require laravel/reverb
php artisan reverb:install
php artisan optimize:clear
```

## .env настройки

```env
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=local-app
REVERB_APP_KEY=local-key
REVERB_APP_SECRET=local-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
```

## Запуск / остановка

```bash
# Запустить в фоне (когда начал кодить)
cd ~/projects/dating-api
nohup php artisan reverb:start --debug > /tmp/reverb.log 2>&1 &
disown

# Проверить что работает
pgrep -af "reverb:start"

# Остановить (когда закончил)
pkill -f "reverb:start"
```

## Дашборд

Прямого дашборда нет. WebSocket-сервер слушает `ws://localhost:8080/`.

Проверить что работает:

```bash
# HTTP-запрос на 8080 (вернёт 404 — это нормально)
curl -I http://localhost:8080/
# → HTTP/1.1 404 Not Found (значит сервер жив)

# Или проверить порт
ss -tlnp | grep 8080
# → LISTEN 0.0.0.0:8080
```

## artisan команды

```bash
php artisan reverb:start              # запустить foreground
php artisan reverb:start --debug      # запустить с детальным логом
php artisan reverb:stop               # остановить
php artisan reverb:restart            # перезапустить
```

## Алиасы для `~/.bashrc`

```bash
# Reverb
alias reverb-start='cd ~/projects/dating-api && nohup php artisan reverb:start --debug > /tmp/reverb.log 2>&1 & disown && echo "Reverb: ws://localhost:8080"'
alias reverb-stop='pkill -f "reverb:start" 2>/dev/null; echo "Reverb stopped"'
alias reverb-status='pgrep -af "reverb:start" | head -3 || echo "Reverb is stopped"'
alias reverb-log='tail -f /tmp/reverb.log'
```

Применить:

```bash
source ~/.bashrc
```

## Использование

```bash
reverb-start    # запустить
reverb-status   # проверить
reverb-log      # логи (Ctrl+C для выхода)
reverb-stop     # остановить
```

## Как проверить что работает в браузере

1. Открой чат в двух вкладках (логин под разными юзерами)
2. Отправь сообщение из одной
3. Во второй должно появиться мгновенно, без перезагрузки

Если не появилось — глянь вкладку Network → WS в DevTools, должны быть WebSocket-сообщения.

## Диагностика

```bash
# Сервер запущен?
pgrep -af "reverb:start"

# Порт слушается?
ss -tlnp | grep 8080

# Логи
tail -f /tmp/reverb.log

# .env правильный?
grep -E "^BROADCAST|^REVERB" ~/projects/dating-api/.env

# Очистить кэш после изменения .env
cd ~/projects/dating-api && php artisan optimize:clear

# Убить зависший процесс
pkill -f "reverb:start"
```

## TL;DR — ежедневный workflow

```bash
# Начал кодить
reverb-start

# Закончил
reverb-stop
```
