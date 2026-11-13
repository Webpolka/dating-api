<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileView extends Model
{
    use HasFactory;

    public const CREATED_AT = null;
    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'viewer_id', 'viewed_id',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function viewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'viewer_id');
    }

    public function viewed(): BelongsTo
    {
        return $this->belongsTo(User::class, 'viewed_id');
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================
    public static function recordView(int $viewerId, int $viewedId): void
    {
        if ($viewerId === $viewedId) return;

        static::updateOrCreate(
            ['viewer_id' => $viewerId, 'viewed_id' => $viewedId],
            []
        );
    }
}