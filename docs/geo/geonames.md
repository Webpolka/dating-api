# база GEONAMES

Скачать через curl базу стран, регинов, городов на русском языке
```bash
php artisan migrate:fresh

# для линукс скчиваем через курл
# 1. Перейди в корень проекта
cd projects/dating-api

# 2. Скачай именно в storage/app/
curl -4 -L --progress-bar -o storage/app/alternateNames.zip \
  http://download.geonames.org/export/dump/alternateNames.zip

# 3. Распакуй именно в storage/app/
cd storage/app
unzip alternateNames.zip

# 4. Проверь, что файл появился
ls -lh alternateNames.txt

php artisan geo:import

php artisan db:seed
```