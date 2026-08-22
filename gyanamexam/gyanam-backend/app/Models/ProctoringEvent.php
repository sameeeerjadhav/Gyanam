<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProctoringEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'student_id', 'exam_config_id', 'event_type', 'message', 'meta', 'created_at',
    ];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];
}
