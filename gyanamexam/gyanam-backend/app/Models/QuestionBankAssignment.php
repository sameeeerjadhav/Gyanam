<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionBankAssignment extends Model
{
    protected $fillable = ['question_bank_id', 'centre_id'];

    public function bank()
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }
}
