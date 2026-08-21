<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubmissionAnswer extends Model
{
    protected $fillable = ['submission_id', 'question_id', 'selected_answer', 'is_correct'];
    protected $casts = ['is_correct' => 'boolean'];

    public function submission() { return $this->belongsTo(Submission::class); }
    public function question()   { return $this->belongsTo(Question::class); }
}
