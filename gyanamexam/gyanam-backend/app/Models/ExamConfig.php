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
        'proctored', 'proctoring_settings', 'is_global_practice',
    ];

    protected $casts = [
        'active' => 'boolean',
        'randomize_questions' => 'boolean',
        'proctored' => 'boolean',
        'proctoring_settings' => 'array',
        'is_global_practice' => 'boolean',
    ];

    /** Max attempts for the all-students practice experience (no per-course assign). */
    public const GLOBAL_PRACTICE_MAX_ATTEMPTS = 50;

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

    public function scopeActiveGlobalPractice($query)
    {
        return $query->where('is_global_practice', true)->where('active', true);
    }

    /** Ensure only one exam is marked as the global practice paper. */
    public static function clearOtherGlobalPracticeFlags(?int $keepId = null): void
    {
        $q = static::where('is_global_practice', true);
        if ($keepId) {
            $q->where('id', '!=', $keepId);
        }
        $q->update(['is_global_practice' => false]);
    }
}
