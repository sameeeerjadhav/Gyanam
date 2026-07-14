<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Student extends Model
{
    use HasFactory, HasApiTokens;

    protected $fillable = [
        'identifier', 'name', 'centre_name', 'exam_slot', 'time_window', 'password',
    ];

    protected $hidden = ['password'];

    public function exams()
    {
        return $this->belongsToMany(ExamConfig::class, 'exam_student', 'student_id', 'exam_config_id');
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }
}
