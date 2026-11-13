<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

class AdminLog extends Model
{
    use HasFactory;

    public const ACTION_USER_BAN = 'user.ban';
    public const ACTION_USER_UNBAN = 'user.unban';
    public const ACTION_PHOTO_APPROVE = 'photo.approve';
    public const ACTION_PHOTO_REJECT = 'photo.reject';
    public const ACTION_TRANSACTION_REFUND = 'transaction.refund';
    public const ACTION_SETTING_UPDATE = 'setting.update';

    // === AUTH EVENTS ===
    // Категория auth.* — видна в audit-логе рядом с другими событиями.
    // Группируется по префиксу до первой точки (см. AdminLogController::getStats).
    public const ACTION_AUTH_LOGIN              = 'auth.login';
    public const ACTION_AUTH_LOGOUT             = 'auth.logout';
    public const ACTION_AUTH_LOGIN_FAILED       = 'auth.login_failed';
    public const ACTION_AUTH_BRUTE_FORCE        = 'auth.brute_force_detected';
    public const ACTION_AUTH_LOGOUT_EVERYWHERE  = 'auth.logout_everywhere';

    protected $fillable = [
        'admin_id',
        'action',
        'loggable_type',
        'loggable_id',
        'before',
        'after',
        'ip_address',
        'user_agent',
        'participants',
    ];

    protected $casts = [
        'before' => 'array',
        'after' => 'array',
        'participants' => 'array',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopeByAdmin(Builder $query, int $adminId): Builder
    {
        return $query->where('admin_id', $adminId);
    }

    public function scopeForAction(Builder $query, string $action): Builder
    {
        return $query->where('action', $action);
    }

    public function scopeForEntity(Builder $query, string $type, int $id): Builder
    {
        return $query->where('loggable_type', $type)->where('loggable_id', $id);
    }

    // ============================================
    // ХЕЛПЕР: ВЫЧИСЛЕНИЕ ЧИСТОГО ДИФФА
    // ============================================
    private static function calculateDiff(array $before, array $after): array
    {
        $cleanBefore = [];
        $cleanAfter = [];
        $ignoreFields = ['updated_at', 'created_at', 'last_seen', 'last_login_at', 'remember_token'];

        foreach ($after as $key => $value) {
            if (in_array($key, $ignoreFields)) continue;

            if (!array_key_exists($key, $before) || $before[$key] !== $value) {
                $cleanBefore[$key] = $before[$key] ?? null;
                $cleanAfter[$key] = $value;
            }
        }

        foreach ($before as $key => $value) {
            if (in_array($key, $ignoreFields)) continue;

            if (!array_key_exists($key, $after)) {
                $cleanBefore[$key] = $value;
                $cleanAfter[$key] = null;
            }
        }

        return [$cleanBefore, $cleanAfter];
    }

    // ============================================
    // СТАТИЧЕСКИЙ ХЕЛПЕР ДЛЯ ЗАПИСИ ЛОГОВ
    // ============================================
    public static function record(string $action, ?Model $model = null, ?User $admin = null, ?array $before = null, ?array $after = null, array $participants = []): ?int
    {
        $admin = $admin ?? auth()->user();

        if (is_array($before) && is_array($after)) {
            [$before, $after] = self::calculateDiff($before, $after);

            if (empty($before) && empty($after) && empty($participants)) {
                return null;
            }
        }

        return DB::table('admin_logs')->insertGetId([
            'admin_id'      => $admin?->id,
            'action'        => $action,
            'loggable_type' => $model ? get_class($model) : null,
            'loggable_id'   => $model?->id,
            'before'        => $before ? json_encode($before) : null,
            'after'         => $after ? json_encode($after) : null,
            'ip_address'    => app()->runningInConsole() ? '127.0.0.1' : Request::ip(),
            'user_agent'    => app()->runningInConsole() ? 'Console/Job' : Request::userAgent(),
            'participants'  => !empty($participants) ? json_encode($participants) : null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }
}
