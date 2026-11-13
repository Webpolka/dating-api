# Mailpit — шпаргалка

> Локальный SMTP-сервер для перехвата писем.
> Запускаешь когда работаешь — останавливаешь когда закончил.
> Автозапуск не нужен (зачем память жрать, когда не кодишь).

## Установка (один раз)

```bash
# Скачать
curl -sL https://github.com/axllent/mailpit/releases/latest/download/mailpit-linux-amd64.tar.gz -o /tmp/mailpit.tar.gz

# Распаковать
sudo tar -xzf /tmp/mailpit.tar.gz -C /usr/local/bin/
sudo chmod +x /usr/local/bin/mailpit

# Проверить
which mailpit
# → /usr/local/bin/mailpit
```

## Запуск / остановка

```bash
# Запустить в фоне (когда начал кодить)
nohup mailpit > /tmp/mailpit.log 2>&1 &
disown

# Проверить что работает
pgrep -af mailpit

# Остановить (когда закончил)
pkill -f mailpit
```

## Web UI

```
http://localhost:8025
```

- SMTP: `127.0.0.1:1025`
- Web UI: `127.0.0.1:8025`
- Логин/пароль: не требуются

## .env для Laravel

```env
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="no-reply@datingapp.local"
MAIL_FROM_NAME="${APP_NAME}"
```

После изменения:

```bash
php artisan config:clear
```

## Алиасы для `~/.bashrc`

```bash
# Mailpit
alias mailpit-start='nohup mailpit > /tmp/mailpit.log 2>&1 & disown && echo "Mailpit: http://localhost:8025"'
alias mailpit-stop='pkill -f mailpit 2>/dev/null; echo "Mailpit stopped"'
alias mailpit-status='pgrep -af mailpit | head -3 || echo "Mailpit is stopped"'
alias mailpit-log='tail -f /tmp/mailpit.log'
alias mailpit-open='xdg-open http://localhost:8025'
```

Применить:

```bash
source ~/.bashrc
```

## Использование

```bash
mailpit-start      # запустить
mailpit-open       # открыть Web UI
mailpit-status     # проверить
mailpit-log        # логи (Ctrl+C для выхода)
mailpit-stop       # остановить
```

## Тест отправки письма

```bash
php artisan tinker
>>> Mail::raw('Test', fn($m) => $m->to('test@example.com')->subject('Test'));
>>> exit
```

Письмо появится в **http://localhost:8025**

## Диагностика

```bash
# Процесс запущен?
pgrep -af mailpit

# Порты слушаются?
ss -tlnp 2>/dev/null | grep -E '1025|8025'

# SMTP отвечает?
echo "EHLO test" | nc -w2 127.0.0.1 1025 | head -1
# → 220 mailpit ESMTP

# Логи
tail -f /tmp/mailpit.log

# Убить зависший процесс
pkill -f mailpit

# Перезапуск
mailpit-stop
mailpit-start
```

## TL;DR — ежедневный workflow

```bash
# Начал кодить
mailpit-start
mailpit-open

# Закончил
mailpit-stop
```

## Связка с другими сервисами

| Сервис | Порт | Когда нужен |
|--------|------|-------------|
| Redis | 6379 | Когда есть кэш/очереди |
| Mailpit SMTP | 1025 | Когда есть email-уведомления |
| Mailpit Web | 8025 | Когда нужно глянуть письма |
| Horizon Dashboard | 8000/horizon | Когда есть очереди |

## Полный снос (если нужно переустановить)

```bash
pkill -f mailpit 2>/dev/null
sudo rm /usr/local/bin/mailpit
rm -f /tmp/mailpit.log
```

После этого можешь поставить заново командами из раздела "Установка".
