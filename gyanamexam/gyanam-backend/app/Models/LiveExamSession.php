<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LiveExamSession extends Model
{
    protected $fillable = [
        'student_id', 'exam_config_id', 'exam_code', 'student_name', 'exam_title',
        'centre_name', 'duration_minutes', 'extra_minutes', 'question_ids',
        'attempt_number', 'started_at', 'last_seen_at',
    ];

    protected $casts = [
        'started_at'       => 'datetime',
        'last_seen_at'     => 'datetime',
        'extra_minutes'    => 'integer',
        'duration_minutes' => 'integer',
        'attempt_number'   => 'integer',
        'question_ids'     => 'array',
    ];

    public function toMonitorArray(): array
    {
        return [
            'studentId'       => $this->student_id,
            'studentName'     => $this->student_name,
            'examId'          => $this->exam_code,
            'examConfigId'    => $this->exam_config_id,
            'examTitle'       => $this->exam_title,
            'centreName'      => $this->centre_name,
            'startedAt'       => optional($this->started_at)->toISOString(),
            'lastSeen'        => optional($this->last_seen_at)->toISOString(),
            'extraMinutes'    => (int) $this->extra_minutes,
            'durationMinutes' => (int) $this->duration_minutes,
            'attemptNumber'   => (int) $this->attempt_number,
        ];
    }
}
