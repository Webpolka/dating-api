<?php

namespace App\Support\V1;

use App\Models\AdminLog;

/**
 * Метаданные для отображения записи AdminLog в UI.
 *
 * Заменяет отсутствующий blade-эталон `\App\Support\AdminLogMeta::get($action)`,
 * который возвращает {title, icon, iconColor, badge:{variant,label}} по action.
 *
 * Используется:
 *   - UserListingsController::adminLogs() — прикрепляет `meta` к каждой строке
 *     per-user таба «Логи админов» (как status_badge у транзакций).
 *
 * Принцип маппинга — по префиксу action (всё до первой точки):
 *   user.*       → пользовательские действия (ban/unban/restore/delete/...)
 *   photo.*      → модерация фото (approve/reject)
 *   photo_comment.* → модерация комментариев
 *   diary.* / diary_comment.* → модерация контента
 *   transaction.* → возвраты/операции
 *   report.*     → работа с жалобами
 *   auth.*       → аутентификация
 *   setting.*    → настройки
 *   broadcast.*  → рассылки
 *   support.*    → support-чаты
 *
 * `icon` — имя иконки из lucide-react (frontend рендерит через динамический
 * компонент по этому имени). `iconColor` — tailwind-класс цвета круга-иконки.
 */
class AdminLogMeta
{
    /**
     * Метаданные по action.
     *
     * @return array{title: string, icon: string, iconColor: string, badge: array{variant: string, label: string}}
     */
    public static function get(string $action): array
    {
        $category = explode('.', $action, 2)[0];
        $tail = explode('.', $action, 2)[1] ?? '';

        // Сначала точные совпадения по полному action (более специффичные),
        // затем по категории (префиксу).
        $exact = self::exactMap()[$action] ?? null;
        if ($exact) {
            return $exact;
        }

        return self::categoryMap()[$category]
            ?? [
                'title'     => self::titleFromAction($action, $tail),
                'icon'      => 'circle',
                'iconColor' => 'bg-muted text-muted-foreground',
                'badge'     => ['variant' => 'secondary', 'label' => self::categoryLabel($category)],
            ];
    }

    /**
     * Точная мапа по полному action — переопределяет категорию.
     *
     * @return array<string, array{title: string, icon: string, iconColor: string, badge: array{variant: string, label: string}}>
     */
    private static function exactMap(): array
    {
        return [
            // === User: баны ===
            AdminLog::ACTION_USER_BAN => [
                'title'     => 'Бан пользователя',
                'icon'      => 'ban',
                'iconColor' => 'bg-red-500/15 text-red-500',
                'badge'     => ['variant' => 'destructive', 'label' => 'Бан'],
            ],
            AdminLog::ACTION_USER_UNBAN => [
                'title'     => 'Разбан пользователя',
                'icon'      => 'unlock',
                'iconColor' => 'bg-green-500/15 text-green-500',
                'badge'     => ['variant' => 'success', 'label' => 'Разбан'],
            ],

            // === Photo ===
            AdminLog::ACTION_PHOTO_APPROVE => [
                'title'     => 'Одобрение фото',
                'icon'      => 'check-circle',
                'iconColor' => 'bg-green-500/15 text-green-500',
                'badge'     => ['variant' => 'success', 'label' => 'Одобрено'],
            ],
            AdminLog::ACTION_PHOTO_REJECT => [
                'title'     => 'Отклонение фото',
                'icon'      => 'x-circle',
                'iconColor' => 'bg-red-500/15 text-red-500',
                'badge'     => ['variant' => 'destructive', 'label' => 'Отклонено'],
            ],

            // === Transaction ===
            AdminLog::ACTION_TRANSACTION_REFUND => [
                'title'     => 'Возврат средств',
                'icon'      => 'undo-2',
                'iconColor' => 'bg-blue-500/15 text-blue-500',
                'badge'     => ['variant' => 'secondary', 'label' => 'Возврат'],
            ],

            // === Auth ===
            AdminLog::ACTION_AUTH_LOGIN => [
                'title'     => 'Вход в систему',
                'icon'      => 'log-in',
                'iconColor' => 'bg-green-500/15 text-green-500',
                'badge'     => ['variant' => 'success', 'label' => 'Вход'],
            ],
            AdminLog::ACTION_AUTH_LOGOUT => [
                'title'     => 'Выход из системы',
                'icon'      => 'log-out',
                'iconColor' => 'bg-muted text-muted-foreground',
                'badge'     => ['variant' => 'secondary', 'label' => 'Выход'],
            ],
            AdminLog::ACTION_AUTH_LOGIN_FAILED => [
                'title'     => 'Неудачный вход',
                'icon'      => 'alert-triangle',
                'iconColor' => 'bg-yellow-500/15 text-yellow-500',
                'badge'     => ['variant' => 'warning', 'label' => 'Ошибка'],
            ],
            AdminLog::ACTION_AUTH_BRUTE_FORCE => [
                'title'     => 'Подбор пароля',
                'icon'      => 'shield-alert',
                'iconColor' => 'bg-red-500/15 text-red-500',
                'badge'     => ['variant' => 'destructive', 'label' => 'Брутфорс'],
            ],
            AdminLog::ACTION_AUTH_LOGOUT_EVERYWHERE => [
                'title'     => 'Завершение всех сессий',
                'icon'      => 'log-out',
                'iconColor' => 'bg-yellow-500/15 text-yellow-500',
                'badge'     => ['variant' => 'warning', 'label' => 'Logout all'],
            ],

            // === Setting ===
            AdminLog::ACTION_SETTING_UPDATE => [
                'title'     => 'Изменение настройки',
                'icon'      => 'settings',
                'iconColor' => 'bg-blue-500/15 text-blue-500',
                'badge'     => ['variant' => 'secondary', 'label' => 'Настройка'],
            ],
        ];
    }

    /**
     * Мапа по категории (префиксу action) — fallback.
     *
     * @return array<string, array{title: string, icon: string, iconColor: string, badge: array{variant: string, label: string}}>
     */
    private static function categoryMap(): array
    {
        return [
            'user' => [
                'title'     => 'Действие с пользователем',
                'icon'      => 'user-cog',
                'iconColor' => 'bg-blue-500/15 text-blue-500',
                'badge'     => ['variant' => 'secondary', 'label' => 'Пользователь'],
            ],
            'photo' => [
                'title'     => 'Модерация фото',
                'icon'      => 'image',
                'iconColor' => 'bg-purple-500/15 text-purple-500',
                'badge'     => ['variant' => 'outline', 'label' => 'Фото'],
            ],
            'photo_comment' => [
                'title'     => 'Модерация комментариев',
                'icon'      => 'message-square',
                'iconColor' => 'bg-purple-500/15 text-purple-500',
                'badge'     => ['variant' => 'outline', 'label' => 'Комментарий'],
            ],
            'diary' => [
                'title'     => 'Модерация дневников',
                'icon'      => 'book-open',
                'iconColor' => 'bg-purple-500/15 text-purple-500',
                'badge'     => ['variant' => 'outline', 'label' => 'Дневник'],
            ],
            'diary_comment' => [
                'title'     => 'Модерация комментариев',
                'icon'      => 'message-square',
                'iconColor' => 'bg-purple-500/15 text-purple-500',
                'badge'     => ['variant' => 'outline', 'label' => 'Комментарий'],
            ],
            'transaction' => [
                'title'     => 'Финансовая операция',
                'icon'      => 'dollar-sign',
                'iconColor' => 'bg-green-500/15 text-green-500',
                'badge'     => ['variant' => 'secondary', 'label' => 'Финансы'],
            ],
            'report' => [
                'title'     => 'Обработка жалобы',
                'icon'      => 'flag',
                'iconColor' => 'bg-yellow-500/15 text-yellow-500',
                'badge'     => ['variant' => 'warning', 'label' => 'Жалоба'],
            ],
            'auth' => [
                'title'     => 'Событие аутентификации',
                'icon'      => 'shield',
                'iconColor' => 'bg-blue-500/15 text-blue-500',
                'badge'     => ['variant' => 'secondary', 'label' => 'Авторизация'],
            ],
            'setting' => [
                'title'     => 'Изменение настройки',
                'icon'      => 'settings',
                'iconColor' => 'bg-blue-500/15 text-blue-500',
                'badge'     => ['variant' => 'secondary', 'label' => 'Настройка'],
            ],
            'broadcast' => [
                'title'     => 'Рассылка',
                'icon'      => 'megaphone',
                'iconColor' => 'bg-blue-500/15 text-blue-500',
                'badge'     => ['variant' => 'secondary', 'label' => 'Рассылка'],
            ],
            'support' => [
                'title'     => 'Действие в support-чате',
                'icon'      => 'headphones',
                'iconColor' => 'bg-blue-500/15 text-blue-500',
                'badge'     => ['variant' => 'secondary', 'label' => 'Support'],
            ],
        ];
    }

    /** Человекочитаемый заголовок, если action не распознан. */
    private static function titleFromAction(string $action, string $tail): string
    {
        if ($tail === '') {
            return $action;
        }
        // 'transaction.refund' → 'Возврат' (просто хвост) — лучше, чем сырой action.
        $tail = str_replace(['_', '-'], ' ', $tail);
        return mb_convert_case($tail, MB_CASE_TITLE, 'UTF-8');
    }

    /** Метка категории для неизвестных action. */
    private static function categoryLabel(string $category): string
    {
        $labels = [
            'user'           => 'Пользователь',
            'photo'          => 'Фото',
            'photo_comment'  => 'Комментарий',
            'diary'          => 'Дневник',
            'diary_comment'  => 'Комментарий',
            'transaction'    => 'Финансы',
            'report'         => 'Жалоба',
            'auth'           => 'Авторизация',
            'setting'        => 'Настройка',
            'broadcast'      => 'Рассылка',
            'support'        => 'Support',
        ];
        return $labels[$category] ?? ucfirst($category);
    }
}
