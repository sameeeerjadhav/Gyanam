<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionFlag extends Model
{
    protected $fillable = [
        'submission_id', 'question_id', 'student_id',
        'centre_name', 'reason', 'status', 'admin_note',
    ];

    public function submission() { return $this->belongsTo(Submission::class); }
    public function question()   { return $this->belongsTo(Question::class); }
    public function student()    { return $this->belongsTo(Student::class); }

    /** Scope to centre for ATC users */
    public function scopeForCentre($query, ?string $centreId)
    {
        if (is_null($centreId)) return $query;
        return $query->where('centre_name', $centreId);
    }
}
