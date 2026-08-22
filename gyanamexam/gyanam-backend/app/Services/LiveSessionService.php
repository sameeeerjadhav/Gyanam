<?php

namespace App\Services;

use App\Models\LiveExamSession;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Concurrent-safe live exam session registry (DB upserts — no unlocked cache RMW).
 */
class LiveSessionService
{
    public const STALE_MINUTES = 10;
    public const GRACE_SECONDS = 30;
    /** After deadline+grace, still accept submit for this long (hours). */
    public const LATE_SUBMIT_HOURS = 24;

    public function startOrResume(
        int $studentId,
        int $examConfigId,
        array $meta,
        int $durationMinutes,
        int $attemptNumber = 1,
        ?array $questionIds = null
    ): LiveExamSession {
        $now = now();

        $existing = LiveExamSession::where('student_id', $studentId)
            ->where('exam_config_id', $examConfigId)
            ->first();

        if ($existing) {
            $existing->fill([
                'student_name' => $meta['studentName'] ?? $existing->student_name,
                'exam_title'   => $meta['examTitle'] ?? $existing->exam_title,
                'centre_name'  => $meta['centreName'] ?? $existing->centre_name,
                'exam_code'    => $meta['examId'] ?? $existing->exam_code,
                'last_seen_at' => $now,
            ]);
            if ($questionIds && empty($existing->question_ids)) {
                $existing->question_ids = array_values($questionIds);
            }
            $existing->save();
            return $existing;
        }

        return LiveExamSession::create([
            'student_id'       => $studentId,
            'exam_config_id'   => $examConfigId,
            'exam_code'        => $meta['examId'] ?? null,
            'student_name'     => $meta['studentName'] ?? null,
            'exam_title'       => $meta['examTitle'] ?? null,
            'centre_name'      => $meta['centreName'] ?? null,
            'duration_minutes' => $durationMinutes,
            'extra_minutes'    => 0,
            'question_ids'     => $questionIds ? array_values($questionIds) : null,
            'attempt_number'   => $attemptNumber,
            'started_at'       => $now,
            'last_seen_at'     => $now,
        ]);
    }

    public function setQuestionIds(LiveExamSession $session, array $questionIds): void
    {
        if (empty($session->question_ids)) {
            $session->question_ids = array_values($questionIds);
            $session->save();
        }
    }

    public function touch(int $studentId, int $examConfigId): ?LiveExamSession
    {
        $session = LiveExamSession::where('student_id', $studentId)
            ->where('exam_config_id', $examConfigId)
            ->first();

        if (!$session) {
            return null;
        }

        $session->last_seen_at = now();
        $session->save();

        return $session;
    }

    public function find(int $studentId, int $examConfigId): ?LiveExamSession
    {
        return LiveExamSession::where('student_id', $studentId)
            ->where('exam_config_id', $examConfigId)
            ->first();
    }

    public function extend(int $studentId, int $examConfigId, int $extraMinutes): ?LiveExamSession
    {
        $session = $this->find($studentId, $examConfigId);
        if (!$session) {
            return null;
        }
        $session->extra_minutes = (int) $session->extra_minutes + $extraMinutes;
        $session->last_seen_at = now();
        $session->save();
        return $session;
    }

    public function end(int $studentId, int $examConfigId): void
    {
        LiveExamSession::where('student_id', $studentId)
            ->where('exam_config_id', $examConfigId)
            ->delete();
    }

    public function activeSessions(?string $centreName = null): Collection
    {
        $cutoff = now()->subMinutes(self::STALE_MINUTES);

        $q = LiveExamSession::query()->where('last_seen_at', '>=', $cutoff);
        if ($centreName !== null && $centreName !== '') {
            $q->where('centre_name', $centreName);
        }

        return $q->orderByDesc('last_seen_at')->get()->map(fn (LiveExamSession $s) => $s->toMonitorArray());
    }

    public function activeCount(?string $centreName = null): int
    {
        $cutoff = now()->subMinutes(self::STALE_MINUTES);
        $q = LiveExamSession::query()->where('last_seen_at', '>=', $cutoff);
        if ($centreName !== null && $centreName !== '') {
            $q->where('centre_name', $centreName);
        }
        return $q->count();
    }

    /**
     * Seconds remaining until server deadline (+ grace). Negative if expired.
     */
    public function remainingSeconds(LiveExamSession $session): int
    {
        $deadline = Carbon::parse($session->started_at)
            ->addMinutes((int) $session->duration_minutes + (int) $session->extra_minutes)
            ->addSeconds(self::GRACE_SECONDS);

        return (int) ($deadline->getTimestamp() - now()->getTimestamp());
    }

    public function isPastDeadline(LiveExamSession $session): bool
    {
        return $this->remainingSeconds($session) < 0;
    }

    /** Past late-submit window — session should be abandoned / retake if attempts remain. */
    public function isPastLateWindow(LiveExamSession $session): bool
    {
        $deadline = Carbon::parse($session->started_at)
            ->addMinutes((int) $session->duration_minutes + (int) $session->extra_minutes)
            ->addSeconds(self::GRACE_SECONDS)
            ->addHours(self::LATE_SUBMIT_HOURS);

        return now()->greaterThan($deadline);
    }
}
