<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamConfig extends Model
{
    use HasFactory;

    protected $fillable = [
        'exam_id', 'title', 'subject', 'exam_type', 'duration',
        'total_questions', 'passing_score', 'question_bank_id',
        'created_by_user_id', 'instructions', 'active', 'randomize_questions',
        'proctored', 'proctoring_settings',
    ];

    protected $casts = [
        'active' => 'boolean',
        'randomize_questions' => 'boolean',
        'proctored' => 'boolean',
        'proctoring_settings' => 'array',
    ];

    public function questionBank()
    {
        return $this->belongsTo(QuestionBank::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function students()
    {
        return $this->belongsToMany(Student::class, 'exam_student', 'exam_config_id', 'student_id');
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }
}
