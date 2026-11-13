<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class UserEvent extends Model
{
    use HasFactory;

    protected $table = 'user_events'; 

    protected $fillable = [
        'user_id', 'type', 'properties',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ============================================
    // ХЕЛПЕР ДЛЯ СОЗДАНИЯ СОБЫТИЯ
    // ============================================
    public static function log(User $user, string $type, array $properties = []): void
    {
        DB::table('user_events')->insert([
            'user_id'    => $user->id,
            'type'       => $type,
            'properties' => json_encode($properties),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}