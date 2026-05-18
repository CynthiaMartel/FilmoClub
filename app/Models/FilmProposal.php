<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FilmProposal extends Model
{
    protected $fillable = [
        'user_id',
        'tmdb_id',
        'status',
        'tmdb_snapshot',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'tmdb_snapshot' => 'array',
        'reviewed_at'   => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
