<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserReport extends Model
{
    protected $table = 'user_reports';

    protected $fillable = [
        'reporter_id',
        'reported_user_id',
        'category',
        'reason',
        'status',
        'low_confidence',
        'admin_note',
    ];

    protected $casts = [
        'low_confidence' => 'boolean',
    ];

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function reportedUser()
    {
        return $this->belongsTo(User::class, 'reported_user_id');
    }
}
