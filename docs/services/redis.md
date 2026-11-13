# Redis на Linux Mint 23.5 (SysV init) — шпаргалка

> Система: Linux Mint 23.5 с init (PID 1 = `init`), без systemd.
> Поэтому `systemctl` НЕ работает. Используем `service` и `/etc/init.d/`.
> GUI: Another Redis Desktop Manager (ARDM) — AppImage, без Docker.

## Базовыве команды если алиасы сохранены в bashrc:
```bash
redis-start        # запускает Redis
redis-stop         # стоп Redis
redis-cli ping     # → PONG
redis-gui          # открывает ARDM
redis-status       # статус сервиса
redis-info         # инфо по всем БД
```

---

## 1. Установка Redis

```bash
sudo apt update
sudo apt install redis-server redis-tools
```

Проверка:

```bash
redis-cli --version
redis-server --version
```

---

## 2. Запуск / остановка Redis

### Основной способ (через `service`)

```bash
sudo service redis-server start
sudo service redis-server stop
sudo service redis-server restart
sudo service redis-server status
```

### Альтернатива (через `/etc/init.d/`)

```bash
sudo /etc/init.d/redis-server start
sudo /etc/init.d/redis-server stop
sudo /etc/init.d/redis-server restart
sudo /etc/init.d/redis-server status
```

### Прямой запуск (если сервис не пашет)

```bash
# Запустить в фоне
redis-server --daemonize yes

# Остановить
redis-cli shutdown nosave
# или жёстко
pkill redis-server
```

---

## 3. Проверка работы

```bash
redis-cli ping
# → PONG
```

Если `PONG` — Redis работает. Если `Could not connect` — не запущен.

---

## 4. Установка GUI — Another Redis Desktop Manager (ARDM)

> phpRedisAdmin снесли — он старый и капризный.
> ARDM — современный, красивый, работает из коробки.

### Скачать и установить

```bash
# Создать папку для приложений
mkdir -p ~/Applications

# Скачать актуальную версию 1.7.4
curl -L -o ~/Applications/ardm.AppImage \
  https://github.com/qishibo/AnotherRedisDesktopManager/releases/download/v1.7.4/Another-Redis-Desktop-Manager-linux-1.7.4-x86_64.AppImage

# Сделать исполняемым
chmod +x ~/Applications/ardm.AppImage

# Проверить размер (~150 MB)
ls -lh ~/Applications/ardm.AppImage
```

### Запуск

```bash
~/Applications/ardm.AppImage &>/dev/null & disown
```

### Если AppImage не запускается

Поставить FUSE:

```bash
sudo apt install libfuse2
```

И попробовать снова.

### Настройка подключения в ARDM

1. Откроется окно ARDM
2. Нажми **"New Connection"**
3. Заполни:
   - **Name**: `Local Redis`
   - **Host**: `127.0.0.1`
   - **Port**: `6379`
   - **Auth**: оставить пустым
4. Нажми **"Test Connection"** → должно показать `OK`
5. Нажми **"Save"**
6. Кликни на подключение → увидишь DB 0-15 (только непустые)

---

## 5. Базовые команды redis-cli

### Интерактивный режим

```bash
redis-cli
127.0.0.1:6379> ping
127.0.0.1:6379> DBSIZE
127.0.0.1:6379> KEYS *
127.0.0.1:6379> exit
```

### Подключение к конкретной БД

```bash
redis-cli -n 1
# → 127.0.0.1:6379[1]>
```

### Полезные команды

```bash
# Размер конкретной БД
redis-cli -n 1 DBSIZE

# Все ключи в БД 1
redis-cli -n 1 KEYS "*"

# Найти по паттерну
redis-cli -n 1 KEYS "feed_search:*"

# Значение ключа
redis-cli -n 1 GET "имя_ключа"

# TTL ключа (сколько секунд осталось)
redis-cli -n 1 TTL "имя_ключа"

# Удалить ключ
redis-cli -n 1 DEL "имя_ключа"

# Удалить все ключи по паттерну
redis-cli -n 1 --scan --pattern "feed_search:*" | xargs -r redis-cli -n 1 DEL

# Очистить ВСЮ БД (ОСТОРОЖНО!)
redis-cli -n 1 FLUSHDB

# Мониторинг команд в реальном времени
redis-cli MONITOR

# Инфо о памяти
redis-cli INFO memory | grep used_memory_human

# Инфо обо всех БД
redis-cli INFO keyspace
```

---

## 6. Структура БД (твой проект)

| DB | Назначение | Что искать |
|----|-----------|-----------|
| 0 | Сессии, кэш Laravel по умолчанию | `laravel_session:*`, `illuminate:*` |
| 1 | CACHE_STORE (кэш приложения) | `feed_search:*`, `pulse:*` |
| 2 | Horizon очереди | `queues:*`, `horizon:*` |
| 3 | Reverb broadcasting | `reverb:*`, `presence:*` |

---

## 7. Алиасы для `~/.bashrc`

> Все алиасы проверены и работают на init-системе.

Открой `~/.bashrc`:

```bash
nano ~/.bashrc
```

Добавь в конец файла:

```bash
# ============================================
# REDIS
# ============================================

# Управление Redis-сервером (через service — работает на init)
alias redis-start='sudo service redis-server start && echo "Redis started"'
alias redis-stop='sudo service redis-server stop && echo "Redis stopped"'
alias redis-restart='sudo service redis-server restart && echo "Redis restarted"'
alias redis-status='sudo service redis-server status'
alias redis-log='sudo tail -f /var/log/redis/redis-server.log'
alias redis-config='sudo nano /etc/redis/redis.conf'

# GUI — Another Redis Desktop Manager (AppImage)
alias redis-gui='~/Applications/ardm.AppImage &>/dev/null & disown && echo "ARDM started"'
alias redis-gui-stop='pkill -f "ardm.AppImage" && echo "ARDM stopped"'

# Быстрые команды redis-cli
alias redis-cli='redis-cli'
alias redis-mon='redis-cli MONITOR'
alias redis-keys='redis-cli -n 1 KEYS'
alias redis-size='redis-cli -n 1 DBSIZE'
alias redis-info='redis-cli INFO keyspace'
alias redis-flush='redis-cli -n 1 FLUSHDB && echo "DB 1 flushed"'
alias redis-memory='redis-cli INFO memory | grep used_memory_human'

# Удобное очищение кэша поиска (DB 1)
alias redis-clear-feed='redis-cli -n 1 --scan --pattern "feed_search:*" | xargs -r redis-cli -n 1 DEL && echo "feed_search cache cleared"'
```

Применить:

```bash
source ~/.bashrc
```

### Проверка алиасов

```bash
# Должны работать:
redis-start        # запускает Redis
redis-cli ping     # → PONG
redis-gui          # открывает ARDM
redis-status       # статус сервиса
redis-info         # инфо по всем БД
```

---

## 8. Аварийные ситуации

### Redis завис и не останавливается

```bash
# Найти PID процесса
ps aux | grep redis-server | grep -v grep
# Видишь PID, например 12345

# Мягкая остановка
sudo kill 12345

# Если через 5 сек не остановился — жёстко
sudo kill -9 12345

# Запустить заново
sudo service redis-server start
```

### Логи Redis

```bash
# Через journalctl (работает даже на init)
sudo journalctl -u redis-server -n 50 --no-pager

# Через файл логов
sudo tail -f /var/log/redis/redis-server.log
```

### Конфиг Redis

```bash
sudo nano /etc/redis/redis.conf
```

После изменения конфига:

```bash
sudo service redis-server restart
```

### Очистить весь Redis (ОСТОРОЖНО — удалит ВСЁ)

```bash
redis-cli FLUSHALL
```

---

## 9. Автозапуск Redis при загрузке системы

Redis ставится через `apt`, поэтому init-скрипт уже создан. Проверь:

```bash
ls -la /etc/init.d/redis-server
# → должен существовать

# Включить автозапуск
sudo update-rc.d redis-server defaults

# Выключить автозапуск
sudo update-rc.d redis-server remove
```

---

## 10. TL;DR — типичный workflow

### Утром (если нет автозапуска)

```bash
redis-start           # запускаем Redis
redis-cli ping        # проверяем → PONG
redis-gui             # открываем ARDM
```

### В течение дня

```bash
redis-info            # глянуть какие БД заполнены
redis-clear-feed      # очистить кэш поиска анкет
redis-mon             # мониторинг команд (Ctrl+C для выхода)
```

### Вечером

```bash
redis-gui-stop        # закрыть ARDM
redis-stop            # остановить Redis
```

---

## 11. Чек-лист после установки

- [ ] Redis установлен: `redis-server --version`
- [ ] Redis запускается: `redis-cli ping` → `PONG`
- [ ] ARDM скачан: `ls -lh ~/Applications/ardm.AppImage`
- [ ] ARDM запускается: `~/Applications/ardm.AppImage &`
- [ ] Алиасы в `~/.bashrc` добавлены
- [ ] `source ~/.bashrc` выполнен
- [ ] `redis-start` работает
- [ ] `redis-gui` открывает ARDM
- [ ] В ARDM подключение к `127.0.0.1:6379` работает
- [ ] Видны ключи в DB 1 (после запросов к API)

---

## 12. Полезные команды одной строкой

```bash
# Размер всех БД
for i in 0 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do echo "DB $i: $(redis-cli -n $i DBSIZE) keys"; done

# Найти самые большие ключи
redis-cli --bigkeys

# Статистика в реальном времени
redis-cli --stat

# Найти все ключи feed_search в DB 1
redis-cli -n 1 KEYS "feed_search:*"

# Удалить все ключи feed_search в DB 1
redis-cli -n 1 --scan --pattern "feed_search:*" | xargs -r redis-cli -n 1 DEL

# Сколько памяти занимает Redis
redis-cli INFO memory | grep used_memory_human
```
