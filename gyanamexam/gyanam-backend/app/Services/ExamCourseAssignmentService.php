<?php

namespace App\Services;

use App\Models\ExamConfig;
use App\Models\Student;

/**
 * Auto-attach course exams to students whose registered course matches the exam subject.
 * Demo: attemptable immediately (unlimited). Main: visible but locked until ATC unlocks
 * (schedule / hall ticket → assignments API sets assigned_by_user_id).
 */
class ExamCourseAssignmentService
{
    public const DEMO_MAX_ATTEMPTS = 255;

    public static function normalizeCourse(?string $value): string
    {
        $v = trim((string) $value);
        if ($v === '') {
            return '';
        }
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;

        return mb_strtolower($v);
    }

    public static function coursesMatch(?string $a, ?string $b): bool
    {
        $na = self::normalizeCourse($a);
        $nb = self::normalizeCourse($b);

        return $na !== '' && $nb !== '' && $na === $nb;
    }

    public static function isDemoExam(ExamConfig $exam): bool
    {
        return in_array(strtolower((string) $exam->exam_type), ['demo', 'practice'], true);
    }

    /**
     * Attach an exam to every student on the matching course (does not re-lock unlocked mains).
     */
    public function assignExamToCourseStudents(ExamConfig $exam): int
    {
        if (!(bool) $exam->active || (bool) $exam->is_global_practice) {
            return 0;
        }

        $subject = trim((string) $exam->subject);
        if ($subject === '') {
            return 0;
        }

        $isDemo = self::isDemoExam($exam);
        $maxAttempts = $isDemo ? self::DEMO_MAX_ATTEMPTS : 1;
        $attached = 0;

        Student::query()
            ->whereNotNull('course')
            ->where('course', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($students) use ($exam, $subject, $isDemo, $maxAttempts, &$attached) {
                foreach ($students as $student) {
                    if (!self::coursesMatch($student->course, $subject)) {
                        continue;
                    }
                    if ($this->attachExamToStudent($student, $exam, $isDemo, $maxAttempts)) {
                        $attached++;
                    }
                }
            });

        return $attached;
    }

    /**
     * Attach all active course-matching exams to one student (e.g. after sync/create).
     */
    public function assignCourseExamsToStudent(Student $student): int
    {
        $course = trim((string) ($student->course ?? ''));
        if ($course === '') {
            return 0;
        }

        $attached = 0;
        ExamConfig::query()
            ->where('active', true)
            ->where('is_global_practice', false)
            ->whereNotNull('subject')
            ->where('subject', '!=', '')
            ->orderBy('id')
            ->chunkById(100, function ($exams) use ($student, $course, &$attached) {
                foreach ($exams as $exam) {
                    if (!self::coursesMatch($course, $exam->subject)) {
                        continue;
                    }
                    $isDemo = self::isDemoExam($exam);
                    $maxAttempts = $isDemo ? self::DEMO_MAX_ATTEMPTS : 1;
                    if ($this->attachExamToStudent($student, $exam, $isDemo, $maxAttempts)) {
                        $attached++;
                    }
                }
            });

        return $attached;
    }

    /**
     * @return bool true when a new pivot row was created (or demo max bumped)
     */
    private function attachExamToStudent(Student $student, ExamConfig $exam, bool $isDemo, int $maxAttempts): bool
    {
        $existing = $student->exams()
            ->where('exam_configs.id', $exam->id)
            ->withPivot(['max_attempts', 'assigned_by_user_id'])
            ->first();

        if ($existing) {
            // Never overwrite ATC unlock (assigned_by_user_id set)
            if ($existing->pivot->assigned_by_user_id) {
                if ($isDemo && (int) $existing->pivot->max_attempts < self::DEMO_MAX_ATTEMPTS) {
                    $student->exams()->updateExistingPivot($exam->id, [
                        'max_attempts' => self::DEMO_MAX_ATTEMPTS,
                    ]);
                    return true;
                }
                return false;
            }
            if ($isDemo && (int) $existing->pivot->max_attempts < self::DEMO_MAX_ATTEMPTS) {
                $student->exams()->updateExistingPivot($exam->id, [
                    'max_attempts' => self::DEMO_MAX_ATTEMPTS,
                ]);
                return true;
            }
            return false;
        }

        $student->exams()->attach($exam->id, [
            'max_attempts'        => $maxAttempts,
            'assigned_by_user_id' => null,
            'assigned_at'         => now(),
        ]);

        return true;
    }
}
