<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    use HasFactory;

    protected $fillable = [
        'submission_id', 'student_id', 'exam_config_id', 'exam_title',
        'student_name', 'centre_name', 'score', 'correct_answers',
        'total_questions', 'result', 'duration_taken', 'submitted_at',
    ];

    protected $casts = ['submitted_at' => 'datetime'];

    public function student()  { return $this->belongsTo(Student::class); }
    public function exam()     { return $this->belongsTo(ExamConfig::class, 'exam_config_id'); }
    public function answers()  { return $this->hasMany(SubmissionAnswer::class); }

    /** Scope scoped to a centre */
    public function scopeForCentre($query, ?string $centreId)
    {
        if (is_null($centreId)) return $query;
        return $query->where('centre_name', $centreId);
    }
}
