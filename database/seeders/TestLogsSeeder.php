<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TestLogsSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🧪 Генерируем тестовые логи в файл...');

        $userIds = User::where('role', 'user')->pluck('id')->toArray();

        if (empty($userIds)) {
            $this->command->warn('⚠️ Нет пользователей для генерации логов.');
            return;
        }

        $this->command->info("👥 Найдено " . count($userIds) . " пользователей");

        $levels = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
        
        $messages = [
            'Пользователь вошел в систему', 'Успешная регистрация', 'Подписка активирована',
            'Фото загружено', 'Комментарий добавлен', 'Настройки обновлены', 'Кеш очищен',
            'Новый пользователь зарегистрировался', 'Профиль обновлен', 'Уведомление отправлено',
            'Пароль успешно изменен', 'Email подтвержден', 'Пользователь вышел из системы',
            'Неверный пароль', 'Попытка входа с неверным email', 'Сессия истекла',
            'Подозрительная активность', 'Медленный запрос к БД',
            'Ошибка при загрузке файла', 'База данных недоступна', 'Ошибка отправки письма',
            'Ошибка валидации данных', 'Ошибка при обработке платежа',
            'Пользователь забанен', 'Жалоба решена', 'Критическая ошибка в системе', 'Потеря соединения с БД',
        ];

        $bar = $this->command->getOutput()->createProgressBar(100);
        $bar->start();

        $logCount = 0;

        for ($i = 0; $i < 100; $i++) {
            $level = $levels[array_rand($levels)];
            $message = $messages[array_rand($messages)];
            
            $userId = $userIds[array_rand($userIds)];
            $ip = '192.168.' . rand(1, 255) . '.' . rand(1, 255);
            $timestamp = now()->subDays(rand(0, 30))->subMinutes(rand(0, 1440));
            
            $context = [
                'user_id' => $userId,
                'ip' => $ip,
                'user_agent' => $this->getRandomUserAgent(),
                'iteration' => $i + 1,
                'timestamp' => $timestamp->toIso8601String(),
                'session_id' => 'test_session_' . Str::random(16), 
                'random' => rand(1, 1000),
            ];

            if (in_array($level, ['error', 'critical', 'alert'])) {
                $context['stack_trace'] = "Error in file: /app/Http/Controllers/TestController.php line " . rand(10, 200);
                $context['error_code'] = rand(100, 599);
            }

            if ($level === 'warning') {
                $context['warning_type'] = ['performance', 'security', 'deprecation'][array_rand(['performance', 'security', 'deprecation'])];
            }

            if ($message === 'Пользователь забанен' || $message === 'Жалоба решена') {
                $context['moderator_id'] = $userIds[array_rand($userIds)];
                $context['reason'] = 'Нарушение правил сообщества';
            }

            Log::channel('daily')->$level($message, $context);
            
            $logCount++;
            $bar->advance();
        }

        $bar->finish();
        $this->command->newLine(2);
        $this->command->info('✅ Сгенерировано ' . $logCount . ' тестовых логов в файл!');
    }

    private function getRandomUserAgent(): string
    {
        $agents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/121.0',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 13; SM-G998B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Edge/120.0.0.0 Safari/537.36',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ];

        return $agents[array_rand($agents)];
    }
}