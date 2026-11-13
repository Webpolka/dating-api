<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class UserGift extends Model
{
    use HasFactory, SoftDeletes; 

    protected $fillable = [
        'sender_id', 'receiver_id', 'gift_id', 'snapshot_name', 'snapshot_image_url',
        'snapshot_price', 'message', 'is_private', 'is_read', 'read_at',
    ];

    protected $casts = [
        'snapshot_price' => 'integer',
        'is_private' => 'boolean',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    protected $appends = ['image_url'];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id')->withTrashed();
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'receiver_id')->withTrashed();
    }

    public function gift(): BelongsTo
    {
        return $this->belongsTo(Gift::class);
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopePublic($query)
    {
        return $query->where('is_private', false);
    }

    public function scopePrivate($query)
    {
        return $query->where('is_private', true);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeReceivedBy($query, int $userId)
    {
        return $query->where('receiver_id', $userId);
    }

    public function scopeSentBy($query, int $userId)
    {
        return $query->where('sender_id', $userId);
    }

    // ============================================
    // АКСЕССОРЫ
    // ============================================
    public function getImageUrlAttribute(): string
    {
        if (empty($this->snapshot_image_url)) {
            return $this->gift?->image_url ?? '';
        }

        if (filter_var($this->snapshot_image_url, FILTER_VALIDATE_URL)) {
            return $this->snapshot_image_url;
        }

        return Storage::url($this->snapshot_image_url);
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================
    public function markAsRead(): void
    {
        $updated = $this->newQuery()
            ->where('id', $this->id)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        if ($updated) {
            $this->is_read = true;
            $this->read_at = now();
        }
    }
}