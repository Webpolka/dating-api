<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiaryCommentLike extends Model
{
    use HasFactory;

    protected $fillable = ['diary_comment_id', 'user_id'];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(DiaryComment::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}