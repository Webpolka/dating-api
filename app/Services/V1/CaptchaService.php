<?php

namespace App\Services\V1;

use Illuminate\Support\Facades\Session;

class CaptchaService
{
    /**
     * Генерирует картинку капчи и сохраняет код в сессию.
     */
       /**
     * Генерирует картинку капчи и сохраняет код в сессию.
     */
    public function generate(string $sessionKey = 'captcha'): string
    {
        $characters = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; 
        $code = '';
        for ($i = 0; $i < 5; $i++) {
            $code .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        Session::put($sessionKey, $code);

        $width = 350;
        $height = 70;
        $image = imagecreatetruecolor($width, $height);
        
        // Универсальный серый фон (хорошо смотрится и на светлой, и на темной теме)
        $bg_r = 220; $bg_g = 222; $bg_b = 228;
        $bg_color = imagecolorallocate($image, $bg_r, $bg_g, $bg_b);
        imagefilledrectangle($image, 0, 0, $width, $height, $bg_color);
        
        // Фоновые линии (серые, чуть темнее фона)
        for ($i = 0; $i < 6; $i++) {
            $line_color = imagecolorallocate($image, random_int(180, 200), random_int(180, 200), random_int(190, 210));
            imageline($image, 0, random_int(0, $height), $width, random_int(0, $height), $line_color);
        }

        $x = 15;
        for ($i = 0; $i < strlen($code); $i++) {
            // Темно-серые/черные буквы для максимального контраста
            $r = random_int(20, 80);
            $g = random_int(20, 80);
            $b = random_int(20, 80);
            $text_color = imagecolorallocate($image, $r, $g, $b);
            
            // Мини-холст заливаем цветом фона
            $char_img = imagecreatetruecolor(30, 40);
            $char_bg = imagecolorallocate($char_img, $bg_r, $bg_g, $bg_b);
            imagefill($char_img, 0, 0, $char_bg);
            
            // Пишем букву
            imagestring($char_img, 5, 8, 12, $code[$i], $text_color);
            
            // Масштабируем
            $scale_factor = mt_rand(140, 170) / 100;
            $new_width = (int)(30 * $scale_factor);
            $new_height = (int)(40 * $scale_factor);
            $scaled = imagescale($char_img, $new_width, $new_height);
            
            // Поворот
            $angle = random_int(-25, 25);
            $rotated = imagerotate($scaled, $angle, $char_bg);
            
            // Смещение по вертикали
            $y = random_int(-4,4);
            
            // Вклеиваем букву
            $rotated_width = imagesx($rotated);
            imagecopy($image, $rotated, $x, $y, 0, 0, $rotated_width, imagesy($rotated));
            
            $x += $rotated_width + random_int(10, 14);
            
            imagedestroy($char_img);
            imagedestroy($scaled);
            imagedestroy($rotated);
        }

        // Темные точки (шум)
        for ($i = 0; $i < 300; $i++) {
            $noise_color = imagecolorallocate($image, random_int(50, 150), random_int(50, 150), random_int(50, 150));
            imagesetpixel($image, random_int(0, $width), random_int(0, $height), $noise_color);
        }
        
        ob_start();
        imagepng($image);
        $imageData = ob_get_clean();
        imagedestroy($image);
        
        return 'data:image/png;base64,' . base64_encode($imageData);
    }

    /**
     * Проверяет введенный код против кода в сессии.
     * ОДНОРАЗОВО: удаляет код из сессии сразу после проверки!
     */
    public function validate(string $sessionKey, string $input): bool
    {
        $storedCode = Session::get($sessionKey);

        // ВРЕМЕННЫЙ ЛОГ ДЛЯ ПРОВЕРКИ
    \Log::info('Проверка капчи', [
        'stored' => $storedCode, 
        'input' => $input
    ]);

    
        Session::forget($sessionKey); // Капча сгорает сразу!
        
        if (!$storedCode) {
            return false;
        }
        
        return strtolower($input) === strtolower($storedCode);
    }
}