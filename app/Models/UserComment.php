<?php

namespace App\Models;

use App\Traits\Likeable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserComment extends Model
{
    use HasFactory, Likeable;

    protected $fillable = [
        'user_id',
        'commentable_id',
        'commentable_type',
        'comment',
        'visibility',
        'status',
        'likes_count',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function commentable()
    {
        return $this->morphTo();
    }

    public function likes()
    {
        return $this->hasMany(UserCommentLike::class, 'comment_id');
    }
}

