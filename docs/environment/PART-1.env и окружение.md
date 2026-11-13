# DatingApp — Часть 1: Настройка окружения на MX Linux 23.5

Пошаговая инструкция для джуна. От чистой системы до полностью рабочего стека Laravel 13 + Next.js 15 с realtime, очередями, мониторингом и поиском.

## Что получится в конце ?

Сервис Порт Назначение

Laravel API 8000 Backend
Laravel Horizon 8000/horizon Дашборд очередей
Laravel Pulse 8000/pulse Мониторинг
Laravel Telescope 8000/telescope Дебаг
Laravel Reverb 8080 WebSocket (realtime)
Meilisearch 7700 Полнотекстовый поиск
Redis 6379 Cache + queue + broadcast
PostgreSQL 15 + PostGIS 5432 БД + геолокация
Next.js 3000 Frontend

Версии: PHP 8.5, Composer 2.10, Node.js 24 LTS, pnpm 12, Redis 7, PostgreSQL 15, PostGIS 3, Meilisearch latest
Важные замечания перед стартом

1. Используй обычный терминал MX, а не VS Code

Многие artisan-команды используют Laravel Prompts (интерактивные радио-кнопки). Терминал VS Code иногда не распознаётся как TTY — команды падают с NonInteractiveValidationException.

Решение: Открывай терминал из меню MX (System → Terminal) или по Ctrl+Alt+T. VS Code используй только для редактирования кода. 2. MX Linux использует sysVinit, не systemd

Команда systemctl НЕ работает. Вместо неё:

```bash
sudo service <name> start|stop|restart|status
sudo update-rc.d <name> enable
```

3. Все команды выполняются от обычного пользователя
   Не используй root. Только sudo для привилегированных операций.

## Шаг 1 — подготовка системы и репозитория Sury

Debian 12 (на котором основан MX 23.5) содержит PHP 8.2. Нам нужен PHP 8.5, поэтому подключаем репозиторий Ondřej Surý (мейнтейнер PHP для Debian/Ubuntu).

```bash
# Обновление системы
sudo apt update && sudo apt upgrade -y

# Базовые утилиты
sudo apt install -y ca-certificates lsb-release curl wget gnupg \
  apt-transport-https software-properties-common unzip git build-essential

# Добавляем ключ репозитория Sury
sudo curl -sSL https://packages.sury.org/php/apt.gpg \
  -o /usr/share/keyrings/deb.sury.org-php.gpg --compressed

# Добавляем сам репозиторий
echo "deb [arch=amd64 signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
  | sudo tee /etc/apt/sources.list.d/php.list

# Обновляем список пакетов
sudo apt update
```

Примечание: Если видишь предупреждение про i386 архитектуру — игнорируй, оно безобидное. Если хочешь убрать — добавь [arch=amd64] в строку репозитория (уже сделано в команде выше).

## Шаг 2 — установка PHP 8.5

Грабли: Пакета php8.5-opcache НЕ существует — opcache уже встроен в php8.5-common. Не включай его в список установки.

```bash
sudo apt install -y \
  php8.5-cli php8.5-fpm \
  php8.5-mbstring php8.5-xml php8.5-curl \
  php8.5-zip php8.5-gd php8.5-bcmath \
  php8.5-intl php8.5-pgsql php8.5-mysql \
  php8.5-sqlite3 php8.5-readline \
  php8.5-dev php-pear
```

## Шаг 3 — делаем PHP 8.5 дефолтным + включаем opcache для CLI

```bash
# Регистрируем и переключаем
sudo update-alternatives --install /usr/bin/php php /usr/bin/php8.5 85
sudo update-alternatives --set php /usr/bin/php8.5

# Проверяем версию
php -v
# Должно показать: PHP 8.5.x (cli)

# Включаем opcache для CLI
sudo sed -i 's/^;opcache.enable_cli=.*/opcache.enable_cli=1/' /etc/php/8.5/cli/php.ini

# Проверяем что opcache подцепился
php -m | grep -iE "opcache|pdo|mbstring|xml|curl|zip|gd|bcmath|intl|readline"
```

Должны увидеть в списке: Zend OPcache, PDO, pdo_pgsql, pdo_mysql, pdo_sqlite, mbstring, xml, curl, zip, gd, bcmath, intl, readline.

## Шаг 4 — установка расширения Redis для PHP

```bash
# Проверяем что пакет есть в репе
apt-cache search '^php8.5-redis'

# Ставим из пакета (быстро и надёжно)
sudo apt install -y php8.5-redis

# Проверяем
php -m | grep -i redis
php -i | grep -i "Redis Version"
```

Должно показать redis в списке и Redis Version => 6.x.x.

## Шаг 5 — установка Composer

```bash
curl -sS https://getcomposer.org/installer \
  | sudo php -- --install-dir=/usr/local/bin --filename=composer

composer --version
# Composer version 2.10.x
```

## Шаг 6 — установка Node.js через nvm

Грабли: В nvm 0.40+ изменился путь установки с ~/.nvm на ~/.config/nvm. Если использовать старый путь — nvm: command not found.

```bash
# Установка nvm
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.40.1/install.sh | bash

# ПРАВИЛЬНЫЙ путь для nvm 0.40+
export NVM_DIR="$HOME/.config/nvm"
[ -s "$NVM_DIR/nvm.sh" ] && \. "$NVM_DIR/nvm.sh"
[ -s "$NVM_DIR/bash_completion" ] && \. "$NVM_DIR/bash_completion"

# Проверяем что nvm подцепился
nvm --version
# 0.40.1

# Ставим последнюю LTS-версию Node.js
nvm install --lts
nvm use --lts
nvm alias default 'lts/*'

# Глобальные пакетные менеджеры
npm install -g pnpm yarn

# Проверяем
node -v    # v24.x.x
npm -v     # 11.x.x
pnpm -v    # 12.x.x
yarn -v    # 1.22.x
```

Проверка: После перезапуска терминала nvm должен подхватываться автоматически через .bashrc. Проверь: grep NVM_DIR ~/.bashrc — должен показать $HOME/.config/nvm.

## Шаг 7 — установка Redis сервера

```bash
sudo apt install -y redis-server

# Запускаем через service (sysVinit, не systemctl!)
sudo service redis-server start
sudo service redis-server status

# Автозапуск
sudo update-rc.d redis-server enable

# Тест
redis-cli ping
# PONG
```

## Шаг 8 — установка PostgreSQL + создание БД

```bash
# Ставим PostgreSQL 15
sudo apt install -y postgresql postgresql-contrib

# Запускаем (PostgreSQL обычно сам стартует после установки)
sudo service postgresql start
sudo service postgresql status

# Автозапуск
sudo update-rc.d postgresql enable

# Создаём базу и пользователя для проекта
sudo -u postgres psql -c "CREATE DATABASE dating_data;"
sudo -u postgres psql -c "CREATE USER devuser WITH PASSWORD 'devpass';"
sudo -u postgres psql -c "GRANT ALL PRIVILEGES ON DATABASE dating_data TO devuser;"
sudo -u postgres psql -c "ALTER USER devuser WITH SUPERUSER;"

# Проверка
PGPASSWORD=devpass psql -U devuser -d dating_data -h 127.0.0.1 -c "SELECT current_database(), current_user;"
```

Должно показать:

```text
current_database | current_user
-------------------+--------------
 dating_data       | devuser
```

## Шаг 9 — установка PostGIS и дополнительных расширений

Важно: Laravel-миграции упадут с ошибкой расширение "postgis" отсутствует если PostGIS не установлен. Ставим ДО запуска миграций!

```bash
# Устанавливаем PostGIS для PostgreSQL 15
sudo apt install -y postgis postgresql-15-postgis-3 postgresql-15-postgis-3-scripts

# Включаем расширения в базе
sudo -u postgres psql -d dating_data -c "CREATE EXTENSION IF NOT EXISTS postgis;"
sudo -u postgres psql -d dating_data -c "CREATE EXTENSION IF NOT EXISTS postgis_topology;"

# Дополнительные полезные расширения
sudo -u postgres psql -d dating_data -c "CREATE EXTENSION IF NOT EXISTS postgis_raster;"
sudo -u postgres psql -d dating_data -c "CREATE EXTENSION IF NOT EXISTS pg_trgm;"
sudo -u postgres psql -d dating_data -c "CREATE EXTENSION IF NOT EXISTS unaccent;"
sudo -u postgres psql -d dating_data -c "CREATE EXTENSION IF NOT EXISTS \"uuid-ossp\";"
sudo -u postgres psql -d dating_data -c "CREATE EXTENSION IF NOT EXISTS pgcrypto;"

# Даём devuser права на использование PostGIS
sudo -u postgres psql -d dating_data -c "GRANT ALL ON SCHEMA public TO devuser;"
sudo -u postgres psql -d dating_data -c "GRANT ALL ON geography_columns TO devuser;"
sudo -u postgres psql -d dating_data -c "GRANT ALL ON geometry_columns TO devuser;"
sudo -u postgres psql -d dating_data -c "GRANT ALL ON spatial_ref_sys TO devuser;"

# Проверка
sudo -u postgres psql -d dating_data -c "SELECT postgis_full_version();"
```

Должно показать версию PostGIS 3.x.
Предупреждение: Варнинг could not change directory to "/home/..." при выполнении sudo -u postgres psql — безобидный. Не влияет на выполнение команд.

## Шаг 10 — тюнинг php.ini под Laravel

```bash
PHP_INI="/etc/php/8.5/cli/php.ini"

sudo sed -i 's/^memory_limit =.*/memory_limit = 512M/' $PHP_INI
sudo sed -i 's/^upload_max_filesize =.*/upload_max_filesize = 64M/' $PHP_INI
sudo sed -i 's/^post_max_size =.*/post_max_size = 64M/' $PHP_INI
sudo sed -i 's/^max_execution_time =.*/max_execution_time = 120/' $PHP_INI
sudo sed -i 's/^;date.timezone =.*/date.timezone = Europe\/Moscow/' $PHP_INI

# То же для FPM
FPM_INI="/etc/php/8.5/fpm/php.ini"
sudo sed -i 's/^memory_limit =.*/memory_limit = 512M/' $FPM_INI
sudo sed -i 's/^upload_max_filesize =.*/upload_max_filesize = 64M/' $FPM_INI
sudo sed -i 's/^post_max_size =.*/post_max_size = 64M/' $FPM_INI
sudo sed -i 's/^max_execution_time =.*/max_execution_time = 120/' $FPM_INI
sudo sed -i 's/^;date.timezone =.*/date.timezone = Europe\/Moscow/' $FPM_INI

# Перезапуск FPM
sudo service php8.5-fpm restart

# Проверка
php -i | grep -E "memory_limit|upload_max_filesize|post_max_size|max_execution_time|date.timezone"
```

## Шаг 11 — клонирование репозиториев

```bash
# Создаём папку для проектов
mkdir -p ~/projects
cd ~/projects

# Клонируем Laravel API
git clone https://github.com/ecomsys/dating-api.git

# Клонируем Next.js фронтенд
git clone https://github.com/ecomsys/dating-web.git

# Проверяем
ls -la ~/projects/
```

## Шаг 12 — установка зависимостей Laravel

```bash
cd ~/projects/dating-api

# Установка PHP-зависимостей
composer install

# Создаём .env из примера
cp .env.example .env

# Генерируем APP_KEY
php artisan key:generate
```

## Шаг 13 — настройка .env (полный конфиг)

Грабли: Базовый .env от Laravel не подходит для дейтинг-проекта. Нужны правильные драйверы (Redis, Reverb), включённое шифрование сессий, и обязательно APP_MAINTENANCE_DRIVER=file (иначе Horizon падает с Driver [database] not supported).

Записываем полный .env (замени всё содержимое файла):

```bash
cat > ~/projects/dating-api/.env << 'ENVEOF'
REVERB_APP_KEY=local-key
REVERB_APP_SECRET=local-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http
REVERB_TLS_HOST=
REVERB_TLS_PORT=8080
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"

# ============================================
# REDIS
# ============================================
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
REDIS_CACHE_DB=1
REDIS_QUEUE_DB=2
REDIS_BROADCAST_DB=3

# ============================================
# FILESYSTEM
# ============================================
FILESYSTEM_DISK=local
FILESYSTEM_DRIVER=local

# ============================================
# MAIL (позже настроим Mailpit)
# ============================================
MAIL_MAILER=log
MAIL_SCHEME=null
MAIL_HOST=127.0.0.1
MAIL_PORT=2525
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="no-reply@yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"

# ============================================
# SEARCH — Meilisearch
# ============================================
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_KEY=
SCOUT_QUEUE=true

# ============================================
# TELESCOPE / HORIZON
# ============================================
TELESCOPE_ENABLED=true
HORIZON_DASHBOARD_ROUTE=horizon

# ============================================
# THROTTLE & LIMITS
# ============================================
MESSAGES_PER_DAY_LIMIT=50
LIKES_PER_DAY_LIMIT=100
PROFILE_VIEWS_CACHE_TTL=300

# ============================================
# FRONTEND (Vite)
# ============================================
VITE_APP_NAME="${APP_NAME}"
VITE_APP_URL="${APP_URL}"
VITE_FRONTEND_URL="${FRONTEND_URL}"
ENVEOF
```

После записи обязательно сгенерируй новый APP_KEY:

```bash
cd ~/projects/dating-api
php artisan key:generate
```

## Шаг 14 — миграции и сиды

```bash
cd ~/projects/dating-api

# Миграции (создание таблиц)
php artisan migrate

# Сиды (заполнение тестовыми данными)
php artisan db:seed

# Symlink для storage
php artisan storage:link

# Очистка кэша
php artisan optimize:clear
```

Проверка: Если миграции упали с ошибкой расширение "postgis" отсутствует — вернись к Шагу 9 и установи PostGIS.

## Шаг 15 — установка dev-пакетов (Reverb, Horizon, Pulse, Telescope, Scout)

```bash
cd ~/projects/dating-api

# Установка всех пакетов сразу
composer require laravel/reverb
composer require laravel/horizon
composer require laravel/pulse
composer require laravel/telescope --dev
composer require laravel/scout meilisearch/meilisearch-php http-interop/http-factory-guzzle

# Очистка кэша
php artisan optimize:clear
```

Проверка что всё встало:

```bash
composer show | grep -E "reverb|horizon|pulse|telescope|scout|meilisearch"
```

Должны увидеть все 6 пакетов.

## Шаг 16 — инициализация Reverb

Грабли: Команда reverb:install может упасть с NonInteractiveValidationException если запущена в терминале VS Code. Используй обычный терминал MX. Если уже упала — не страшно, всё нужное уже создалось, надо только проверить провайдер.

```bash
cd ~/projects/dating-api

# Установка (может спросить про broadcasting — отвечай Yes)
php artisan reverb:install

# Проверка
ls -la config/reverb.php
cat bootstrap/providers.php
```

Если команда упала, но config/reverb.php создался — добавь провайдер вручную:

```bash
# Проверяем что ReverbServiceProvider в bootstrap/providers.php
grep -q "ReverbServiceProvider" bootstrap/providers.php
```

Если нет — открой bootstrap/providers.php в редакторе и добавь Laravel\Reverb\ReverbServiceProvider::class в массив.

##$ Шаг 17 — инициализация Horizon

```bash
cd ~/projects/dating-api

php artisan horizon:install

# Проверка
ls -la config/horizon.php
cat bootstrap/providers.php
```

В bootstrap/providers.php должен добавиться App\Providers\HorizonServiceProvider.

## Шаг 18 — инициализация Pulse

Грабли: В новых версиях Pulse НЕТ команды pulse:install. Делаем через vendor:publish.

```bash
cd ~/projects/dating-api

# Регистрируем PulseServiceProvider
grep -q "PulseServiceProvider" bootstrap/providers.php || \
  sed -i 's|Laravel\\Reverb\\ReverbServiceProvider::class,|Laravel\\Reverb\\ReverbServiceProvider::class,\n    Laravel\\Pulse\\PulseServiceProvider::class,|' bootstrap/providers.php

# Публикуем конфиг
php artisan vendor:publish --tag=pulse-config

# Публикуем миграции
php artisan vendor:publish --tag=pulse-migrations

# Чистим кэш и обнаруживаем провайдер
php artisan optimize:clear
php artisan package:discover

# Запускаем миграции (создадутся таблицы pulse_*)
php artisan migrate

# Проверка
ls -la config/pulse.php
php artisan list | grep pulse
```

Должны быть команды: pulse:check, pulse:clear, pulse:purge, pulse:restart, pulse:work.

## Шаг 19 — инициализация Telescope

```bash

cd ~/projects/dating-api

php artisan telescope:install
php artisan migrate

# Проверка
ls -la config/telescope.php
cat bootstrap/providers.php
php artisan list | grep telescope
```

Должны быть команды: telescope:clear, telescope:install, telescope:list, telescope:pause, telescope:prune, telescope:publish, telescope:resume, telescope:show.

## Шаг 20 — публикация конфига Scout

Грабли: Тег scout-config не работает. Копируем файл вручную.

```bash
cd ~/projects/dating-api

# Проверяем что конфиг в вендоре есть
ls -la vendor/laravel/scout/config/scout.php

# Копируем вручную
cp vendor/laravel/scout/config/scout.php config/scout.php

# Проверка
ls -la config/scout.php
head -30 config/scout.php

# Чистим кэш
php artisan optimize:clear
```

## Шаг 21 — проверка ключевых настроек .env

Reverb при установке может перезаписать ключи в .env на свои. Проверяем и поправляем:

```bash
cd ~/projects/dating-api

# Возвращаем наши ключи Reverb
sed -i 's/^REVERB_APP_ID=.*/REVERB_APP_ID=local-app/' .env
sed -i 's/^REVERB_APP_KEY=.*/REVERB_APP_KEY=local-key/' .env
sed -i 's/^REVERB_APP_SECRET=.*/REVERB_APP_SECRET=local-secret/' .env
sed -i 's/^REVERB_HOST=.*/REVERB_HOST=localhost/' .env
sed -i 's/^REVERB_PORT=.*/REVERB_PORT=8080/' .env
sed -i 's/^REVERB_SCHEME=.*/REVERB_SCHEME=http/' .env

# ⚠️ КРИТИЧНО: maintenance driver должен быть file
sed -i 's/^APP_MAINTENANCE_DRIVER=.*/APP_MAINTENANCE_DRIVER=file/' .env
sed -i 's/^APP_MAINTENANCE_STORE=.*/APP_MAINTENANCE_STORE=/' .env

# Чистим кэш
php artisan optimize:clear

# Финальная проверка
grep -E "^APP_NAME|^APP_MAINTENANCE|^BROADCAST_CONNECTION|^CACHE_STORE|^QUEUE_CONNECTION|^SESSION_DRIVER|^SESSION_ENCRYPT|^SESSION_DOMAIN|^SANCTUM_STATEFUL|^REVERB_APP|^SCOUT_DRIVER|^MEILISEARCH" .env
```

Должно показать:

```text
APP_NAME="DatingApp"
APP_MAINTENANCE_DRIVER=file
APP_MAINTENANCE_STORE=
BROADCAST_CONNECTION=reverb
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=cookie
SESSION_ENCRYPT=true
SESSION_DOMAIN=localhost
SANCTUM_STATEFUL_DOMAINS=localhost:3000,127.0.0.1:3000
REVERB_APP_ID=local-app
REVERB_APP_KEY=local-key
REVERB_APP_SECRET=local-secret
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://127.0.0.1:7700
```

## Шаг 22 — создание структуры папок для медиа

```bash
cd ~/projects/dating-api

# Создаём папки
mkdir -p storage/app/public/avatars/{original,medium,thumb}
mkdir -p storage/app/public/photos/{original,medium,thumb,private}
mkdir -p storage/app/public/messages/{images,voice,videos}
mkdir -p storage/app/public/reports
mkdir -p storage/app/public/verification/{photos,videos}
mkdir -p storage/app/public/{temp,private,banners,uploads}

# Пересоздаём symlink
rm -f public/storage
php artisan storage:link

# Права доступа
sudo chown -R $USER:www-data storage/app/public
sudo chmod -R 775 storage/app/public

# Проверка
ls -la public/storage
# должно показать симлинк: public/storage -> /home/.../storage/app/public
```

## Шаг 23 — установка Meilisearch

```bash
# Скачиваем
curl -L https://install.meilisearch.com | bash

# Перемещаем бинарник
sudo mv meilisearch /usr/local/bin/
sudo chmod +x /usr/local/bin/meilisearch

# Папка для данных
sudo mkdir -p /var/lib/meilisearch
sudo chown -R $USER:$USER /var/lib/meilisearch

# Проверка
meilisearch --version
```

## Шаг 24 — установка VS Code (опционально, для редактирования)

```bash
# Зависимости
sudo apt install -y wget gpg apt-transport-https

# Ключ Microsoft
wget -qO- https://packages.microsoft.com/keys/microsoft.asc | gpg --dearmor > packages.microsoft.gpg
sudo install -D -o root -g root -m 644 packages.microsoft.gpg /etc/apt/keyrings/packages.microsoft.gpg
rm -f packages.microsoft.gpg

# Репозиторий
echo "deb [arch=amd64,arm64,armhf signed-by=/etc/apt/keyrings/packages.microsoft.gpg] https://packages.microsoft.com/repos/code stable main" \
  | sudo tee /etc/apt/sources.list.d/vscode.list

# Установка
sudo apt update
sudo apt install -y code

# Проверка
code --version
```

Напомним: VS Code — только для редактирования кода. Artisan-команды с интерактивными промптами запускай в обычном терминале MX.

## Шаг 25 — настройка CORS в Laravel для Next.js

Грабли: Без правильного CORS браузер блокирует запросы с localhost:3000 на localhost:8000.

```bash
cd ~/projects/dating-api

# Если config/cors.php не существует — публикуем
[ ! -f config/cors.php ] && php artisan config:publish cors

# Пишем правильный конфиг
cat > config/cors.php << 'EOF'
<?php

return [

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login',
        'logout',
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
EOF

# Очистка кэша
php artisan optimize:clear
```

## Шаг 26 — настройка .env.local для Next.js

```bash
cd ~/projects/dating-web

# Создаём .env.local
cat > .env.local << 'EOF'
NEXT_PUBLIC_API_URL=http://localhost:8000/api
NEXT_PUBLIC_API_BASE_URL=http://localhost:8000/api
NEXT_PUBLIC_SANCTUM_URL=http://localhost:8000
NEXT_PUBLIC_APP_URL=http://localhost:3000
EOF

# Установка зависимостей
pnpm install
```

Критично: В NEXT_PUBLIC_API_URL должен быть /api в конце. Иначе запросы идут на корень Laravel и возвращают 404.

## Шаг 27 — финальный запуск всех сервисов

Нужно 4 терминала (вкладки в терминале MX, не VS Code).
Терминал 1 — Laravel сервер

```bash
 cd ~/projects/dating-api
php artisan serve
```

Должно показать:

```text
INFO  Server running on [http://127.0.0.1:8000].
```

Терминал 2 — Horizon (очереди)

```bash
cd ~/projects/dating-api
php artisan horizon
```

Должно показать:

```text
INFO  Horizon started successfully.
```

Если Horizon падает с Driver [database] not supported — проверь что в .env стоит APP_MAINTENANCE_DRIVER=file (не database).

Терминал 3 — Reverb (WebSocket)

```bash
cd ~/projects/dating-api
php artisan reverb:start --debug
```

Должно показать:

```text
INFO  Server running on 0.0.0.0:8080.
```

Терминал 4 — Meilisearch (поиск)

```bash
cd ~/projects/dating-api
meilisearch --http-addr 127.0.0.1:7700 --db-path /var/lib/meilisearch/data.ms
```

Должно показать ASCII-арт и статус "available".

Терминал 5 — Next.js (опционально, для фронтенда)

```bash
cd ~/projects/dating-web
pnpm dev
```

Должно показать:

```text
▲ Next.js 15.x.x
- Local: http://localhost:3000
```

## Шаг 28 — проверка что всё работает

В отдельном терминале:

```bash
echo "=== DatingApp — Проверка всех сервисов ==="
echo ""
echo "📡 Laravel:        $(curl -s -o /dev/null -w '%{http_code}' http://localhost:8000/up) (200 = OK)"
echo "📡 Horizon:        $(curl -s -o /dev/null -w '%{http_code}' http://localhost:8000/horizon) (200/302 = OK)"
echo "📡 Pulse:          $(curl -s -o /dev/null -w '%{http_code}' http://localhost:8000/pulse) (200/302 = OK)"
echo "📡 Telescope:      $(curl -s -o /dev/null -w '%{http_code}' http://localhost:8000/telescope) (200/302 = OK)"
echo "📡 Reverb (8080):  $(curl -s -o /dev/null -w '%{http_code}' http://localhost:8080/) (200/404 = OK)"
echo "📡 Meilisearch:    $(curl -s http://127.0.0.1:7700/health)"
echo "📡 Redis:          $(redis-cli ping)"
echo "📡 PostgreSQL:     $(sudo service postgresql status | head -1)"
echo ""
```

Ожидаемый результат:

```text
=== DatingApp — Проверка всех сервисов ===

📡 Laravel:        200 (200 = OK)
📡 Horizon:        200 (200/302 = OK)
📡 Pulse:          200 (200/302 = OK)
📡 Telescope:      200 (200/302 = OK)
📡 Reverb (8080):  404 (200/404 = OK)
📡 Meilisearch:    {"status":"available"}
📡 Redis:          PONG
📡 PostgreSQL:     15/main (port 5432): online
```

## Полезные алиасы для .bashrc

Добавь в конец ~/.bashrc:

```bash
cat >> ~/.bashrc << 'EOF'

# === DatingApp — алиасы для разработки ===
alias killphp='pkill -f "artisan serve" 2>/dev/null; pkill -f "artisan horizon" 2>/dev/null; pkill -f "artisan reverb" 2>/dev/null; echo "🛑 Все Laravel процессы остановлены"'
alias killmeilisearch='pkill -f "meilisearch" 2>/dev/null; echo "🛑 Meilisearch остановлен"'

alias datingapp-up='cd ~/projects/dating-api && php artisan serve'
alias horizon-up='cd ~/projects/dating-api && php artisan horizon'
alias reverb-up='cd ~/projects/dating-api && php artisan reverb:start --debug'
alias meili-up='meilisearch --http-addr 127.0.0.1:7700 --db-path /var/lib/meilisearch/data.ms'
alias nextjs-up='cd ~/projects/dating-web && pnpm dev'

alias tinker='cd ~/projects/dating-api && php artisan tinker'
alias mfs='cd ~/projects/dating-api && php artisan migrate:fresh --seed'
alias cc='cd ~/projects/dating-api && php artisan optimize:clear'
alias routes='cd ~/projects/dating-api && php artisan route:list --path=api'
EOF

source ~/.bashrc
```

Команды:

     killphp — остановить все Laravel-процессы
     datingapp-up / horizon-up / reverb-up / meili-up — запустить сервисы
     nextjs-up — запустить Next.js
     tinker — войти в tinker
     mfs — свежая миграция с сидами (⚠️ удаляет все данные)
     cc — очистить весь кэш
     routes — список API-роутов

## Типичные проблемы и решения (шпаргалка)

Проблема Решение

php8.5-opcache not found Не ставь этот пакет, opcache уже в php8.5-common
nvm: command not found после установки Используй export NVM_DIR="$HOME/.config/nvm" (не ~/.nvm)
расширение "postgis" отсутствует sudo apt install postgis + CREATE EXTENSION postgis; в БД
NonInteractiveValidationException Запускай artisan в обычном терминале MX, не в VS Code
pulse:install not defined Используй vendor:publish --tag=pulse-config и --tag=pulse-migrations
No publishable resources for tag [scout-config] Скопируй vendor/laravel/scout/config/scout.php в config/scout.php вручную
CORS blocked Настрой config/cors.php с allowed_origins: ['http://localhost:3000']
404 на /me В NEXT_PUBLIC_API_URL должен быть /api в конце
Driver [database] not supported в Horizon В .env должно быть APP_MAINTENANCE_DRIVER=file (не database)
systemctl not working MX Linux использует sysVinit, используй service вместо systemctl

✅ Часть 1 завершена!

У тебя полностью рабочее окружение для разработки DatingApp:

     ✅ Backend (Laravel 12 + PHP 8.5)
     ✅ Frontend (Next.js 15 + Node.js 24)
     ✅ БД (PostgreSQL 15 + PostGIS 3)
     ✅ Кэш/очереди (Redis 7)
     ✅ Realtime (Laravel Reverb)
     ✅ Поиск (Meilisearch)
     ✅ Дашборды (Horizon, Pulse, Telescope)

## Инструменты отладки

Mailpit — есть ли письма?
Открой в браузере: http://localhost:8025

Horizon — completed jobs?
Открой: http://localhost:8000/horizon

БД — Notification записался?

```bash
PGPASSWORD=devpass psql -U devuser -d dating_data -h 127.0.0.1 -c "SELECT id, type, notifiable_id, data->>'title' as title, data->>'message' as message FROM notifications ORDER BY created_at DESC LIMIT 5;"
```

## Текущая архитектура DatingApp

┌─────────────────────────────────────────────────────────────┐
│ DATINGAPP BACKEND │
├─────────────────────────────────────────────────────────────┤
│ Laravel 13 + PHP 8.5 │
│ ├── Realtime (Reverb) ─── :8080 ─── WebSocket server │
│ ├── Queues (Horizon) ──── Redis ──── Job processing │
│ ├── Cache (Redis) ─────── :6379 ──── Fast key-value store │
│ ├── Mail (Mailpit) ────── :1025 ──── Email testing │
│ ├── DB (PostgreSQL 15) ── :5432 ──── + PostGIS for geo │
│ ├── Monitoring: │
│ │ ├── Pulse ──────────── /pulse ──── App metrics │
│ │ ├── Telescope ──────── /telescope ─ Debug bar │
│ │ └── Horizon ────────── /horizon ── Queue dashboard │
│ └── Notifications (3 channels): │
│ ├── database (колокольчик) │
│ ├── mail (email) │
│ └── broadcast (push via Reverb) │
└─────────────────────────────────────────────────�система Notifica
