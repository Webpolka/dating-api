<?php

namespace App\Http\Resources\Api\V1\Admin\System;

use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use App\Models\Chat;
use App\Models\PhotoComment;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Запись журнала аудита (AdminLog).
 *
 * Заменяет AdminLogController::formatLog().
 *
 * Computed-поля:
 *  - object_type — class_basename(loggable_type) или "Массовая операция" / "Система".
 *    Логика: bulk-операции (photo_comment.mass_*) без loggable_type → "Массовая операция".
 *    Если loggable_type=null → "Система".
 *  - is_system — bool, true если loggable_type in ['settings', null] или нет loggable_id.
 *  - is_deleted — bool, true если loggable должен быть, но связь null (объект удалён).
 *  - object_url — URL для перехода к объекту в админке. Строится по мапе роутов.
 *
 * admin — AdminUserBadgeResource::nullable(withRole: true) — в журнале важно видеть role.
 */
class AdminLogResource extends JsonResource
{
    public function toArray($request): array
    {
        $loggable = $this->loggable;

        // Определяем тип объекта для отображения.
        // Для bulk-операций без loggable — «Массовая операция» вместо «Система».
        $isBulkOperation = ! $this->loggable_type
            && ! $this->loggable_id
            && str_starts_with($this->action, 'photo_comment.mass_');

        $type = $isBulkOperation
            ? 'Массовая операция'
            : ($this->loggable_type ? class_basename($this->loggable_type) : 'Система');

        $isSystemAction = in_array($this->loggable_type, ['settings', null]) || ! $this->loggable_id;
        $isDeleted = ! $isSystemAction && is_null($loggable);

        return [
            'id'             => $this->id,
            'created_at'     => $this->created_at?->toIso8601String(),
            'action'         => $this->action,
            'ip_address'     => $this->ip_address,
            'before'         => $this->before,
            'after'          => $this->after,
            'object_type'    => $type,
            'object_id'      => $this->loggable_id,
            'is_deleted'     => $isDeleted,
            'is_system'      => $isSystemAction,
            'object_url'     => $this->getObjectUrl(),
            'admin'          => $this->whenLoaded('admin', fn () => $this->admin
                ? AdminUserBadgeResource::nullable($this->admin, withRole: true)
                : null),
        ];
    }

    /**
     * URL для перехода к объекту в админке.
     *
     * Спец-логика для Chat (различаем по action: support.* → support, иначе private chats).
     * Спец-логика для PhotoComment (одиночные actions → статус-таб, mass → 'all').
     * Остальное — по мапе роутов Next.js.
     */
    private function getObjectUrl(): ?string
    {
        $modelClass = $this->loggable_type;
        $id = $this->loggable_id;
        $action = $this->action;

        if (! $modelClass || ! $id) return null;

        // Спец-логика для Chat: различаем по action.
        if ($modelClass === Chat::class) {
            if (str_starts_with($action, 'support.')) {
                return "/communication/support?q={$id}";
            }
            return "/communication/chats?q={$id}";
        }

        // Спец-логика для PhotoComment: single actions → статус-таб.
        if ($modelClass === PhotoComment::class) {
            $status = match (true) {
                str_starts_with($action, 'photo_comment.approve')     => 'approved',
                str_starts_with($action, 'photo_comment.reject')      => 'rejected',
                str_starts_with($action, 'photo_comment.soft_delete') => 'karantine',
                str_starts_with($action, 'photo_comment.restore')     => 'pending',
                str_starts_with($action, 'photo_comment.destroy')     => 'all',
                default                                                  => 'all',
            };
            return "/moderation/photo-comments?status={$status}&q={$id}";
        }

        // Мапа роутов Next.js: [path, query-param-name?, extra-params?]
        $routeMap = [
            \App\Models\User::class                => ['/admin/users', 'q'],
            \App\Models\Page::class                => ['/system/pages', 'q'],
            \App\Models\BlogPost::class            => ['/system/blog/posts', 'q'],
            \App\Models\BlogCategory::class        => ['/system/blog/posts', 'q'],
            \App\Models\Media::class               => ['/system/media', 'q'],
            \App\Models\GeoIPLocation::class       => ['/system/geo', 'q'],
            \App\Models\Setting::class             => ['/system/settings', null],
            \App\Models\Broadcast::class           => ['/system/broadcasts', 'q'],
            \App\Models\StopWord::class            => ['/communication/stop-words', 'q'],
            \App\Models\SupportTemplate::class    => ['/communication/support-templates', 'q'],
            \App\Models\Gift::class                => ['/finance/gifts', 'q', ['tab' => 'catalog']],
            \App\Models\UserGift::class            => ['/finance/gifts', 'q', ['tab' => 'history']],
            \App\Models\Transaction::class         => ['/finance/transactions', 'q'],
            \App\Models\SubscriptionPlan::class    => ['/finance/subscription-plans', 'q'],
            \App\Models\Photo::class               => ['/moderation/photos', 'q'],
        ];

        if (! isset($routeMap[$modelClass])) {
            return null;
        }

         // В мапе записи могут быть 2 или 3 элемента: [path, paramName] или [path, paramName, extraParams].
        // List destructuring с 3 переменными падает на 2-элементных — поэтому читаем по индексам с ??.
        $entry       = $routeMap[$modelClass];
        $path        = $entry[0];
        $paramName   = $entry[1] ?? null;
        $extraParams = $entry[2] ?? [];

        if ($paramName === null) {
            return $path;
        }

        $params = array_merge($extraParams, [$paramName => $id]);
        return $path . '?' . http_build_query($params);
    }
} 