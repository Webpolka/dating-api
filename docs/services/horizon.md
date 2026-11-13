# Horizon — шпаргалка

> Менеджер очередей Laravel. Ставится **только внутри проекта**.
> Запускаешь когда работаешь — останавливаешь когда закончил.
> Автозапуск не нужен (зачем память жрать, когда не кодишь).

## Использование если есть алиасы в bashrc:

```bash
horizon-start      # запустить
horizon-open       # открыть дашборд
horizon-status     # проверить
horizon-log        # логи (Ctrl+C для выхода)
horizon-stop       # остановить
```

## Установка (один раз)

```bash
cd ~/projects/dating-api
composer require laravel/horizon
php artisan horizon:install
php artisan optimize:clear
```

## Настройка доступа к дашборду

`app/Providers/HorizonServiceProvider.php`, метод `gate()`:

```php
protected function gate(): void
{
    Gate::define('viewHorizon', function ($user = null) {
        if (app()->environment('local')) {
            return true;
        }
        return $user && in_array($user->role, [
            \App\Models\User::ROLE_ADMIN,
        ]);
    });
}
```

## Регистрация провайдера

`bootstrap/providers.php`:

```php
return [
    App\Providers\AppServiceProvider::class,
    App\Providers\HorizonServiceProvider::class,
];
```

## Запуск / остановка

```bash
# Запустить в фоне (когда начал кодить)
cd ~/projects/dating-api
nohup php artisan horizon > /tmp/horizon.log 2>&1 & disown

# Проверить что работает
ps aux | grep horizon | grep -v grep

# Остановить (когда закончил)
cd ~/projects/dating-api && php artisan horizon:terminate
# или жёстко
pkill -f "artisan horizon"
```

## Дашборд

```
http://localhost:8000/horizon
```

## artisan команды

```bash
php artisan horizon              # запустить foreground
php artisan horizon:terminate    # мягко остановить
php artisan horizon:pause        # пауза
php artisan horizon:continue     # продолжить
php artisan horizon:clear        # очистить метрики
php artisan horizon:purge        # удалить старые метрики
```

## Алиасы для `~/.bashrc`

```bash
# Horizon
alias horizon-start='cd ~/projects/dating-api && nohup php artisan horizon > /tmp/horizon.log 2>&1 & disown && echo "Horizon: http://localhost:8000/horizon"'
alias horizon-stop='cd ~/projects/dating-api && php artisan horizon:terminate 2>/dev/null; pkill -f "artisan horizon" 2>/dev/null; echo "Horizon stopped"'
alias horizon-status='pgrep -af "artisan horizon" | head -5 || echo "Horizon is stopped"'
alias horizon-log='tail -f /tmp/horizon.log'
alias horizon-open='xdg-open http://localhost:8000/horizon'
```

Применить:

```bash
source ~/.bashrc
```

## Использование

```bash
horizon-start      # запустить
horizon-open       # открыть дашборд
horizon-status     # проверить
horizon-log        # логи (Ctrl+C для выхода)
horizon-stop       # остановить
```

## Перевод verification email в очередь

### 1. Создать уведомление

```bash
php artisan make:notification VerifyEmail
```

### 2. `app/Notifications/VerifyEmail.php`

```php
<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;

class VerifyEmail extends BaseVerifyEmail implements ShouldQueue
{
    use Queueable;

    public $tries = 3;
    public $backoff = [10, 30, 60];
    public $queue = 'default';

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        return (new MailMessage())
            ->subject('Подтверждение email — ' . config('app.name'))
            ->greeting('Здравствуйте, ' . $notifiable->name . '!')
            ->line('Пожалуйста, подтвердите ваш email-адрес, нажав кнопку ниже.')
            ->action('Подтвердить email', $verificationUrl)
            ->line('Если вы не создавали аккаунт, просто проигнорируйте это письмо.')
            ->salutation('С уважением, команда ' . config('app.name'));
    }

    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            Carbon::now()->addMinutes(Config::get('auth.verification.expire', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }
}
```

### 3. В `app/Models/User.php`

```php
use App\Notifications\VerifyEmail;

public function sendEmailVerificationNotification(): void
{
    $this->notify(new VerifyEmail());
}
```

### 4. Очистить кэш

```bash
php artisan optimize:clear
```

## Диагностика

```bash
# Не запускается?
redis-cli ping                    # Redis точно работает?
tail -f /tmp/horizon.log          # Что в логах Horizon?
tail -f storage/logs/laravel.log  # Что в логах Laravel?

# Jobs зависли?
redis-cli -n 2 FLUSHDB            # Очистить очередь
pkill -f "artisan horizon"        # Убить зависшие процессы
horizon-start                     # Запустить заново
```

## TL;DR — ежедневный workflow

```bash
# Начал кодить
redis-start
horizon-start
horizon-open

# Закончил
horizon-stop
redis-stop
```
