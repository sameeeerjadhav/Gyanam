<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExamAnswerDraft extends Model
{
    protected $fillable = [
        'student_id', 'exam_config_id', 'attempt_number', 'answers', 'marked_for_review',
    ];

    protected $casts = [
        'answers'           => 'array',
        'marked_for_review' => 'array',
        'attempt_number'    => 'integer',
    ];
}
