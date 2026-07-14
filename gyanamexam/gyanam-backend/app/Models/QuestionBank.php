<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class QuestionBank extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'subject', 'created_by_user_id'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function questions()
    {
        return $this->hasMany(Question::class)->orderBy('order');
    }

    public function assignments()
    {
        return $this->hasMany(QuestionBankAssignment::class);
    }

    public function examConfigs()
    {
        return $this->hasMany(ExamConfig::class);
    }

    /**
     * Get centre IDs this bank is assigned to.
     */
    public function assignedCentreIds(): array
    {
        return $this->assignments()->pluck('centre_id')->toArray();
    }

    /**
     * Scope: visible to a given centre_id (or all if null = admin).
     */
    public function scopeVisibleTo($query, ?string $centreId, string $username = '')
    {
        if (is_null($centreId)) return $query; // Admin sees all

        return $query->where(function ($q) use ($centreId, $username) {
            $q->whereHas('assignments', fn($a) => $a->where('centre_id', $centreId))
              ->orWhereHas('creator', fn($u) => $u->where('username', $username));
        });
    }
}
