<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class UserAuthLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'ip_address',
        'user_agent',
        'device_os',
        'device_type',
        'is_successful',
    ];

    protected $casts = [
        'is_successful' => 'boolean',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    /**
     * Записать попытку входа в user_auth_logs.
     *
     * Используется как для успешных, так и для неудачных попыток.
     * Если email не существует в users (атака на выдуманный ящик) —
     * $userId передаём null, запись всё равно сохранится (поле nullable).
     *
     * @param int|null  $userId       ID юзера (null если email не найден)
     * @param string    $ip           IP-адрес с которого шла попытка
     * @param string    $userAgent    User-Agent браузера/бота
     * @param string|null $deviceOs   OS (если умеем парсить UA, иначе null)
     * @param string|null $deviceType Тип устройства (desktop/mobile/bot, иначе null)
     * @param bool      $isSuccessful true = успешный логин, false = неудачный
     */
    public static function log(?int $userId, string $ip, string $userAgent, ?string $deviceOs = null, ?string $deviceType = null, bool $isSuccessful = true): void
    {
        DB::table('user_auth_logs')->insert([
            'user_id'       => $userId,
            'ip_address'    => $ip,
            'user_agent'    => $userAgent,
            'device_os'     => $deviceOs,
            'device_type'   => $deviceType,
            'is_successful' => $isSuccessful,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    /**
     * Количество неудачных попыток с конкретного IP за последние N минут.
     *
     * Используется для детекции сканирования (один IP бьёт много разных email).
     * Использует существующий индекс [ip_address, is_successful, created_at].
     *
     * @param string $ip
     * @param int    $minutes  Окно в минутах (по умолчанию 15)
     */
    public static function countRecentFailuresByIp(string $ip, int $minutes = 15): int
    {
        return DB::table('user_auth_logs')
            ->where('ip_address', $ip)
            ->where('is_successful', false)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    /**
     * Количество неудачных попыток для конкретного юзера с конкретного IP
     * за последние N минут.
     *
     * Используется для классической детекции брутфорса (один IP + один email).
     * Использует существующий индекс [user_id, created_at].
     *
     * @param int    $userId
     * @param string $ip
     * @param int    $minutes
     */
    public static function countRecentFailuresByUserAndIp(int $userId, string $ip, int $minutes = 15): int
    {
        return DB::table('user_auth_logs')
            ->where('user_id', $userId)
            ->where('ip_address', $ip)
            ->where('is_successful', false)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }

    /**
     * Количество неудачных попыток для конкретного юзера со ВСЕХ IP
     * за последние N минут.
     *
     * Используется для детекции distributed brute force (атакующий
     * крутит много прокси, но бьёт один email). Existing throttle
     * admin-login-by-email (10/15min) считает ВСЕ попытки, а не только
     * неудачные — этот метод видит именно failures.
     *
     * @param int $userId
     * @param int $minutes
     */
    public static function countRecentFailuresByUser(int $userId, int $minutes = 15): int
    {
        return DB::table('user_auth_logs')
            ->where('user_id', $userId)
            ->where('is_successful', false)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }
}
