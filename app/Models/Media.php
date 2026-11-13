<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use HasFactory;

    public const TYPE_IMAGE = 'image';
    public const TYPE_VIDEO = 'video';
    public const TYPE_DOCUMENT = 'document';

    protected $fillable = [
        'collection', 'file_name', 'disk_path', 'variants',
        'url', 'type', 'mime_type', 'size', 'uploaded_by',
    ];

    protected $casts = [
        'size' => 'integer',
        'variants' => 'array',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopeOfCollection(Builder $query, string $collection): Builder
    {
        return $query->where('collection', $collection);
    }

    public function scopeImages(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_IMAGE);
    }

    public function scopeVideos(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_VIDEO);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================
    public function safeDelete(): bool
    {
        $disk = Storage::disk('public');

        if ($this->disk_path && !str_starts_with($this->disk_path, 'media/temp/') && $disk->exists($this->disk_path)) {
            $disk->delete($this->disk_path);
        }

        if (!empty($this->variants)) {
            foreach ($this->variants as $variantPath) {
                if ($disk->exists($variantPath)) {
                    $disk->delete($variantPath);
                }
            }
        }

        return $this->delete();
    }  

    public function getVariantUrl(?string $key = null): string
    {
        $variants = $this->variants ?? [];
        
        if ($key && isset($variants[$key])) {
            return Storage::url($variants[$key]);
        }
        
        if (in_array($key, ['orig', 'lg']) && !empty($variants)) {
            return Storage::url(end($variants));
        }
        
        if (in_array($key, ['thumb', 'sm']) && !empty($variants)) {
            return Storage::url(reset($variants));
        }
        
        return $this->url;
    }
}