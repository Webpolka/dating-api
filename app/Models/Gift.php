<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Gift extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'image_url', 'price', 'category', 'is_active', 'type',
    ];

    protected $casts = [
        'price' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = ['image_url'];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function userGifts(): HasMany
    { 
        return $this->hasMany(UserGift::class);
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    // ============================================
    // АКСЕССОРЫ
    // ============================================
    public function getImageUrlAttribute(): string
    {
        $path = $this->attributes['image_url'] ?? null;

        if (empty($path)) return ''; 

        if (filter_var($path, FILTER_VALIDATE_URL)) return $path;

        return Storage::url($path);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================
    public function isActive(): bool
    {
        return $this->is_active;
    }
}