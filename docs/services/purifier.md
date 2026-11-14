Настройка прав доступа (Permissions) для Laravel 11 + Next.js

Эта инструкция решает 99% проблем с Permission denied, Failed to open stream и ошибками кэширования (HTML Purifier, Filesystem, Sessions).
🖥️ Локальная разработка (Local Dev)

Если ты запускаешь проект через php artisan serve (от своего юзера, например webpolka):
bash

# 1. Перейди в папку проекта

cd /path/to/your/dating-api

# 2. Назначь себя владельцем всех файлов

sudo chown -R $USER:$USER .

# 3. Дай полные права на папки storage и bootstrap/cache

# (На локалке 777 — это нормально и удобно)

# Создай директорию для кэша Purifier и дай права
mkdir -p storage/app/purifier
chmod -R 775 storage/app/purifier

sudo chmod -R 777 storage
sudo chmod -R 777 bootstrap/cache

# 4. Удали старый кэш Purifier (если он создался с кривыми правами)

rm -rf storage/app/purifier

# 5. Сбрось кэши Laravel

php artisan optimize:clear

# 1. Переходим в папку проекта

cd /home/webpolka/projects/dating-api

# 2. Жёстко удаляем проблемную папку purifier (используем sudo, т.к. владелец может быть www-data)

sudo rm -rf storage/app/purifier

# 3. Создаём нужную структуру папок заново

mkdir -p storage/app/purifier/HTML

# 4. Назначаем ТЕКУЩЕГО пользователя владельцем этой папки (это важно!)

sudo chown -R $USER:$USER storage/app/purifier

# 5. Даём полные права (777 для локалки - норма)

sudo chmod -R 777 storage/app/purifier

# 6. Сбрасываем кэш Laravel

php artisan optimize:clear

🚀 Продакшен (Production)

На проде (Ubuntu/Debian + Nginx + PHP-FPM) веб-сервер работает от юзера www-data. Нужно сделать так, чтобы и твой юзер (для деплоя), и www-data имели права на запись.
bash

# 1. Перейди в папку проекта

cd /var/www/dating-api # (замени на свой путь)

# 2. Назначь владельцем файлов своего юзера (например, webpolka),

# а группу установи как www-data (чтобы веб-сервер мог читать/писать)

sudo chown -R webpolka:www-data .

# 3. Установи правильные права:

# 755 для папок (владелец может всё, остальные читать/заходить)

sudo find . -type d -exec chmod 755 {} \;

# 644 для файлов (владелец может писать, остальные читать)

sudo find . -type f -exec chmod 644 {} \;

# 4. Дай веб-серверу права на запись в storage и bootstrap/cache

# 775 означает: владелец и группа могут писать, остальные читать

sudo chmod -R 775 storage
sudo chmod -R 775 bootstrap/cache

# 5. Убедись, что папка для сессий и кэша Purifier существует и доступна

mkdir -p storage/app/purifier
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/framework/cache/data
sudo chown -R webpolka:www-data storage
sudo chmod -R 775 storage

# 6. Сбрось кэши Laravel

php artisan optimize:clear
php artisan config:cache
php artisan route:cache

⚠️ Если ошибка с Purifier (Failed to open stream) всё ещё вылезает

Иногда Laravel кэширует путь к папке кэша. Сделай хардкорный сброс:
bash

# 1. Удали папку purifier целиком

rm -rf storage/app/purifier

# 2. Создай её заново с нужными правами

mkdir -p storage/app/purifier
sudo chown -R $USER:$USER storage/app/purifier # для локалки

# или

sudo chown -R webpolka:www-data storage/app/purifier # для прода
sudo chmod -R 777 storage/app/purifier

# 3. Сбрось кэш конфигов (именно конфигов, а не весь кэш)

php artisan config:clear

Копируй это в свой README.md или DEPLOY.md, и при каждом деплое или возникновении ошибок прав просто прогоняй нужный блок команд.
