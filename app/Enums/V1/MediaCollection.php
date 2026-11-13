<?php

namespace App\Enums\V1;

enum MediaCollection: string
{
    case Default = 'default';
    case Gifts = 'gift';
    case Post = 'post';
    case Notifications = 'notifications';
    case BannerDesktop = 'banner_desktop';
    case BannerMobile = 'banner_mobile';    
 
    public function label(): string
    {
        return match ($this) {
            self::Default => 'Общие',
            self::Gifts => 'Подарки',
            self::Post => 'Блог',
            self::Notifications => 'Уведомления (Email)',
            self::BannerDesktop => 'Баннеры (Десктоп)',
            self::BannerMobile => 'Баннеры (Мобайл)',          
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Default => 'bg-secondary text-secondary-foreground',
            self::Gifts => 'bg-secondary text-secondary-foreground',
            self::Post => 'bg-secondary text-secondary-foreground',
            self::Notifications => 'bg-secondary text-secondary-foreground',
            self::BannerDesktop => 'bg-secondary text-secondary-foreground',
            self::BannerMobile => 'bg-secondary text-secondary-foreground',            
        };
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn ($case) => [
            $case->value => $case->label()
        ])->toArray();
    }

          /**
     * Формирует текстовую подсказку с правилами обработки для UI.
     */
    public function dimensionsHint(): string
    {
        $config = config("media.collections.{$this->value}");
        
        // Если это видео, подсказка не нужна
        if (!isset($config['variants'])) {
            return 'Видео файл';
        }

        $firstVariant = reset($config['variants']);
        $size = $firstVariant['size'] ?? 'original';
        $fit = $firstVariant['fit'] ?? 'inside';

        // Если размер просто ширина (1200w)
        if (str_ends_with($size, 'w')) {
            return "Правило: ширина до " . rtrim($size, 'w') . "px";
        }

        // Если квадрат
        $parts = explode('x', $size);
        if (isset($parts[1]) && $parts[0] === $parts[1]) {
            return "Правило: {$size}px (Квадрат)";
        }

        // Если прямоугольник (cover/contain)
        $fitLabel = $fit === 'cover' ? 'Жесткий кроп' : 'Вписать';
        return "Правило: {$size}px ({$fitLabel})";
    }
}