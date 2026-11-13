<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Album extends Model
{
    use HasFactory, SoftDeletes;

    public const NAME_DEFAULT = 'Общие';
    public const DESC_DEFAULT = 'Основные фотографии';

    protected $fillable = [
        'user_id', 'name', 'description', 'is_default', 'is_private', 'photos_count',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'is_private' => 'boolean',
        'photos_count' => 'integer',
    ];

    // ============================================
    // СТАТИЧЕСКИЕ ХЕЛПЕРЫ
    // ============================================
    public static function createDefaultForUser(User $user): self
    {
        return self::create([
            'user_id' => $user->id,
            'name' => self::NAME_DEFAULT,
            'description' => self::DESC_DEFAULT,
            'is_default' => true,
            'is_private' => false,
        ]);
    }

    public static function getDefaultForUser(User $user): self
    {
        return self::firstOrCreate(
            ['user_id' => $user->id, 'is_default' => true],
            [
                'name' => self::NAME_DEFAULT, 
                'description' => self::DESC_DEFAULT, 
                'is_private' => false
            ]
        );
    }

    // ============================================
    // СВЯЗИ
    // ============================================
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class)
            ->orderByDesc('is_primary')
            ->orderBy('position');
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_private', false);
    }

    public function scopePrivate(Builder $query): Builder
    {
        return $query->where('is_private', true);
    }

    // ============================================
    // ХЕЛПЕРЫ ДЛЯ ДЕНОРМАЛИЗАЦИИ (СЧЕТЧИКИ)
    // ============================================
    public function incrementPhotosCount(): void
    {
        $this->newQuery()
            ->where('id', $this->id)
            ->increment('photos_count');
        
        $this->photos_count++;
    }

    public function decrementPhotosCount(): void
    {
        $this->newQuery()
            ->where('id', $this->id)
            ->where('photos_count', '>', 0)
            ->decrement('photos_count');
        
        if ($this->photos_count > 0) {
            $this->photos_count--;
        }
    }

    public function refreshPhotosCount(): void
    {
        $this->photos_count = $this->photos()->count();
        $this->save();
    }
}