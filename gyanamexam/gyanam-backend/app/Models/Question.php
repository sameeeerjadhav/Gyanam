<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    use HasFactory;

    protected $fillable = ['question_bank_id', 'text', 'options', 'correct_answer', 'order'];

    protected $casts = ['options' => 'array'];

    public function bank()
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }

    public function submissionAnswers()
    {
        return $this->hasMany(SubmissionAnswer::class);
    }
}
