<?php

namespace App\Http\Controllers\Api;

use App\Events\StudentExamActivity;
use App\Http\Controllers\Controller;
use App\Models\ExamAnswerDraft;
use App\Models\ExamConfig;
use App\Models\ProctoringEvent;
use App\Models\Question;
use App\Models\Submission;
use App\Services\LiveSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StudentExamController extends Controller
{
    public function __construct(private LiveSessionService $liveSessions) {}

    /**
     * Get exams assigned to this student (attempt counts via single grouped query).
     */
    public function myExams(Request $request)
    {
        $student = $request->user();

        $exams = $student->exams()
            ->where('exam_configs.active', true)
            ->withPivot(['max_attempts', 'assigned_at', 'assigned_by_user_id'])
            ->get([
                'exam_configs.id', 'exam_configs.exam_id', 'exam_configs.title',
                'exam_configs.subject', 'exam_configs.exam_type', 'exam_configs.duration',
                'exam_configs.total_questions', 'exam_configs.passing_score',
                'exam_configs.instructions', 'exam_configs.proctored',
            ]);

        $examIds = $exams->pluck('id')->all();
        $usedByExam = empty($examIds)
            ? collect()
            : $student->submissions()
                ->whereIn('exam_config_id', $examIds)
                ->selectRaw('exam_config_id, COUNT(*) as used')
                ->groupBy('exam_config_id')
                ->pluck('used', 'exam_config_id');

        $payload = $exams->map(function ($exam) use ($usedByExam) {
            $maxAttempts  = $exam->pivot->max_attempts ?? 1;
            $usedAttempts = (int) ($usedByExam[$exam->id] ?? 0);

            return array_merge($exam->only([
                'id', 'exam_id', 'title', 'subject', 'exam_type',
                'duration', 'total_questions', 'passing_score', 'instructions',
            ]), [
                'proctored' => (bool) $exam->proctored,
                'attempt_info' => [
                    'max_attempts'  => $maxAttempts,
                    'used_attempts' => $usedAttempts,
                    'remaining'     => max(0, $maxAttempts - $usedAttempts),
                    'can_attempt'   => $usedAttempts < $maxAttempts,
                ],
            ]);
        });

        return response()->json($payload);
    }

    /**
     * Get (shuffled/cached) questions for an exam session + resume draft + remaining time.
     */
    public function getQuestions(Request $request, $examId)
    {
        $student = $request->user();
        $exam    = ExamConfig::with('questionBank.questions')->findOrFail($examId);

        $pivot = $student->exams()
            ->where('exam_config_id', $examId)
            ->withPivot(['max_attempts'])
            ->first()?->pivot;

        if (!$pivot) {
            abort(403, 'You are not assigned to this exam.');
        }

        $usedAttempts = $student->submissions()->where('exam_config_id', $examId)->count();
        if ($usedAttempts >= $pivot->max_attempts) {
            abort(403, "No attempts remaining. You have used {$usedAttempts}/{$pivot->max_attempts} attempt(s).");
        }

        $legacyCacheKey = "exam_qs:{$examId}:{$student->id}";
        $meta = [
            'studentName' => $student->name,
            'examId'      => $exam->exam_id,
            'examTitle'   => $exam->title,
            'centreName'  => $student->centre_name,
        ];

        // Abandon sessions that are past the late-submit window so a fresh attempt can start
        $existing = $this->liveSessions->find((int) $student->id, (int) $examId);
        if ($existing && $this->liveSessions->isPastLateWindow($existing)) {
            $oldAttempt = (int) ($existing->attempt_number ?: 1);
            Cache::forget("exam_qs:{$examId}:{$student->id}:{$oldAttempt}");
            Cache::forget($legacyCacheKey);
            ExamAnswerDraft::where('student_id', $student->id)->where('exam_config_id', $examId)->delete();
            $this->liveSessions->end((int) $student->id, (int) $examId);
            $existing = null;
        }

        $attemptNumber = $existing
            ? (int) ($existing->attempt_number ?: ($usedAttempts + 1))
            : ($usedAttempts + 1);

        $cacheKey = "exam_qs:{$examId}:{$student->id}:{$attemptNumber}";
        $ttl = max(60, ((int) $exam->duration + 120) * 60);

        // Prefer questions already bound to this attempt (stable set for grading)
        if ($existing && !empty($existing->question_ids)) {
            $questions = Cache::remember($cacheKey, $ttl, function () use ($existing) {
                $rows = Question::whereIn('id', $existing->question_ids)->get()->keyBy('id');
                $ordered = [];
                foreach ($existing->question_ids as $qid) {
                    if ($rows->has($qid)) {
                        $ordered[] = $rows[$qid]->toArray();
                    }
                }
                return $ordered;
            });
        } else {
            $questions = Cache::remember($cacheKey, $ttl, function () use ($exam) {
                $qs = $exam->questionBank->questions->toArray();
                if ($exam->randomize_questions) {
                    shuffle($qs);
                }
                return array_slice($qs, 0, $exam->total_questions);
            });
        }

        $questionIds = array_map(fn ($q) => $q['id'], $questions);

        $session = $this->liveSessions->startOrResume(
            (int) $student->id,
            (int) $examId,
            $meta,
            (int) $exam->duration,
            $attemptNumber,
            $questionIds
        );
        $this->liveSessions->setQuestionIds($session, $questionIds);

        try {
            broadcast(new StudentExamActivity($session->toMonitorArray(), 'started'));
        } catch (\Throwable $e) {
            \Log::warning('Reverb broadcast failed (session start): ' . $e->getMessage());
        }

        $draft = ExamAnswerDraft::where('student_id', $student->id)
            ->where('exam_config_id', $examId)
            ->first();

        // Ignore draft from a different attempt
        if ($draft && (int) $draft->attempt_number !== (int) $session->attempt_number) {
            $draft->delete();
            $draft = null;
        }

        $remaining = $this->liveSessions->remainingSeconds($session);
        $expired   = $remaining < 0;
        $pastLate  = $this->liveSessions->isPastLateWindow($session);

        $safeQuestions = array_map(fn ($q) => [
            'id'      => $q['id'],
            'text'    => $q['text'],
            'options' => $q['options'],
        ], $questions);

        return response()->json([
            'exam' => [
                'id'                  => $exam->id,
                'exam_id'             => $exam->exam_id,
                'title'               => $exam->title,
                'exam_type'           => $exam->exam_type,
                'duration'            => $exam->duration,
                'total_questions'     => count($safeQuestions),
                'passing_score'       => $exam->passing_score,
                'proctored'           => (bool) $exam->proctored,
                'proctoring_settings' => $exam->proctored ? ($exam->proctoring_settings ?? []) : [],
                'started_at'          => optional($session->started_at)->toISOString(),
                'extra_minutes'       => (int) $session->extra_minutes,
                'remaining_seconds'   => max(0, $remaining - LiveSessionService::GRACE_SECONDS),
                'server_time'         => now()->toISOString(),
                'attempt_number'      => (int) $session->attempt_number,
                'expired'             => $expired,
                'must_submit'         => $expired && !$pastLate,
            ],
            'questions' => $safeQuestions,
            'draft' => [
                'answers'           => $draft?->answers ?? [],
                'marked_for_review' => $draft?->marked_for_review ?? [],
                'updated_at'        => optional($draft?->updated_at)->toISOString(),
                'attempt_number'    => (int) ($draft?->attempt_number ?? $session->attempt_number),
            ],
        ]);
    }

    /**
     * Heartbeat — touch live session. No WebSocket broadcast (admin polls).
     */
    public function heartbeat(Request $request, $examId)
    {
        $student = $request->user();
        $session = $this->liveSessions->touch((int) $student->id, (int) $examId);

        return response()->json([
            'ok'                => true,
            'extraMinutes'      => $session?->extra_minutes ?? 0,
            'remaining_seconds' => $session ? max(0, $this->liveSessions->remainingSeconds($session) - LiveSessionService::GRACE_SECONDS) : null,
            'server_time'       => now()->toISOString(),
        ]);
    }

    /**
     * Upsert sparse draft answers (questionId => option). Debounced from client.
     */
    public function saveAnswers(Request $request, $examId)
    {
        $data = $request->validate([
            'answers'           => 'nullable|array',
            'marked_for_review' => 'nullable|array',
        ]);

        $student = $request->user();

        if (!$student->exams()->where('exam_config_id', $examId)->exists()) {
            abort(403, 'You are not assigned to this exam.');
        }

        $session = $this->liveSessions->find((int) $student->id, (int) $examId);

        // Cap payload size
        $answers = $data['answers'] ?? [];
        if (count($answers) > 500) {
            return response()->json(['message' => 'Too many answers in draft.'], 422);
        }

        $draft = ExamAnswerDraft::updateOrCreate(
            [
                'student_id'     => $student->id,
                'exam_config_id' => $examId,
            ],
            [
                'answers'           => $answers,
                'marked_for_review' => $data['marked_for_review'] ?? [],
                'attempt_number'    => $session?->attempt_number ?? 1,
            ]
        );

        if ($session) {
            $this->liveSessions->touch((int) $student->id, (int) $examId);
        }

        return response()->json([
            'ok'             => true,
            'updated_at'     => optional($draft->updated_at)->toISOString(),
            'attempt_number' => (int) $draft->attempt_number,
        ]);
    }

    /**
     * Log a client-side proctoring event (audit only; not trusted as proof).
     */
    public function logProctoringEvent(Request $request, $examId)
    {
        $data = $request->validate([
            'event_type' => 'required|string|max:64',
            'message'    => 'nullable|string|max:500',
            'meta'       => 'nullable|array',
        ]);

        $student = $request->user();

        ProctoringEvent::create([
            'student_id'     => $student->id,
            'exam_config_id' => $examId,
            'event_type'     => $data['event_type'],
            'message'        => $data['message'] ?? null,
            'meta'           => $data['meta'] ?? null,
            'created_at'     => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Submit exam answers — graded synchronously with attempt lock + deadline check.
     */
    public function submit(Request $request, $examId)
    {
        $request->validate([
            'answers'               => 'required|array',
            'client_submission_id'  => 'nullable|string|max:64',
        ]);

        $student = $request->user();
        $exam    = ExamConfig::findOrFail($examId);
        $clientSubmissionId = $request->input('client_submission_id');

        // Idempotent replay
        if ($clientSubmissionId) {
            $existing = Submission::where('student_id', $student->id)
                ->where('exam_config_id', $examId)
                ->where('client_submission_id', $clientSubmissionId)
                ->first();
            if ($existing) {
                return response()->json([
                    'submission_id' => $existing->submission_id,
                    'score'         => $existing->score,
                    'result'        => $existing->result,
                    'correct'       => $existing->correct_answers,
                    'total'         => $existing->total_questions,
                    'exam_title'    => $existing->exam_title,
                    'passing_score' => $exam->passing_score,
                    'message'       => 'Already submitted.',
                    'idempotent'    => true,
                ]);
            }
        }

        $session = $this->liveSessions->find((int) $student->id, (int) $examId);
        $late = false;

        if ($session && $this->liveSessions->isPastLateWindow($session)) {
            return response()->json([
                'message' => 'Exam session expired beyond the late-submit window. Please start a new attempt if available.',
            ], 422);
        }

        if ($session && $this->liveSessions->isPastDeadline($session)) {
            // Accept late submit within 24h window so students are not stuck
            $late = true;
        }

        if (!$session) {
            return response()->json([
                'message' => 'No active exam session. Please reopen the exam and submit again.',
            ], 422);
        }

        $submissionId = Str::uuid()->toString();

        $formattedAnswers = [];
        foreach ($request->input('answers', []) as $ans) {
            if (!isset($ans['question_id'])) {
                continue;
            }
            $formattedAnswers[$ans['question_id']] = $ans['answer'] ?? null;
        }

        $attemptNumber = (int) ($session->attempt_number ?: 1);
        $cacheKey = "exam_qs:{$examId}:{$student->id}:{$attemptNumber}";
        $legacyCacheKey = "exam_qs:{$examId}:{$student->id}";

        // Resolve the exact question set served for this attempt — never the full bank
        $questionIds = $session->question_ids ?: [];
        if (empty($questionIds)) {
            $cachedQuestions = Cache::get($cacheKey) ?: Cache::get($legacyCacheKey, []);
            $questionIds = array_map(fn ($q) => $q['id'], $cachedQuestions);
        }

        if (empty($questionIds)) {
            return response()->json([
                'message' => 'Cannot grade: question set for this attempt is missing. Contact admin.',
            ], 422);
        }

        $correctMap = Question::whereIn('id', $questionIds)->pluck('correct_answer', 'id')->all();

        $total      = count($questionIds);
        $correct    = 0;
        $answerRows = [];

        foreach ($questionIds as $qId) {
            $selected  = $formattedAnswers[$qId] ?? null;
            $isCorrect = isset($correctMap[$qId]) && $selected !== null
                && (string) $correctMap[$qId] === (string) $selected;
            if ($isCorrect) {
                $correct++;
            }
            $answerRows[] = [
                'question_id'     => $qId,
                'selected_answer' => $selected,
                'is_correct'      => $isCorrect,
            ];
        }

        $score  = $total > 0 ? round($correct / $total * 100) : 0;
        $result = $score >= $exam->passing_score ? 'pass' : 'fail';

        $durationTaken = 0;
        if ($session->started_at) {
            $durationTaken = (int) now()->diffInSeconds($session->started_at);
        }

        try {
            $payload = DB::transaction(function () use (
                $submissionId, $student, $examId, $exam, $score, $correct, $total,
                $result, $durationTaken, $answerRows, $cacheKey, $legacyCacheKey,
                $clientSubmissionId, $late
            ) {
                // Lock pivot row to prevent double-submit races
                $pivot = DB::table('exam_student')
                    ->where('student_id', $student->id)
                    ->where('exam_config_id', $examId)
                    ->lockForUpdate()
                    ->first();

                if (!$pivot) {
                    abort(403, 'You are not assigned to this exam.');
                }

                $usedAttempts = Submission::where('student_id', $student->id)
                    ->where('exam_config_id', $examId)
                    ->lockForUpdate()
                    ->count();

                $maxAttempts = (int) ($pivot->max_attempts ?? 1);
                if ($usedAttempts >= $maxAttempts) {
                    abort(403, "No attempts remaining. You have used {$usedAttempts}/{$maxAttempts} attempt(s).");
                }

                $submission = Submission::create([
                    'submission_id'        => $submissionId,
                    'client_submission_id' => $clientSubmissionId,
                    'student_id'           => $student->id,
                    'exam_config_id'       => $examId,
                    'exam_title'           => $exam->title,
                    'student_name'         => $student->name,
                    'centre_name'          => $student->centre_name,
                    'score'                => $score,
                    'correct_answers'      => $correct,
                    'total_questions'      => $total,
                    'result'               => $result,
                    'duration_taken'       => $durationTaken,
                    'submitted_at'         => now(),
                ]);

                $submission->answers()->createMany($answerRows);

                Cache::forget($cacheKey);
                Cache::forget($legacyCacheKey);
                ExamAnswerDraft::where('student_id', $student->id)
                    ->where('exam_config_id', $examId)
                    ->delete();
                $this->liveSessions->end((int) $student->id, (int) $examId);

                return [
                    'submission_id' => $submissionId,
                    'score'         => $score,
                    'result'        => $result,
                    'correct'       => $correct,
                    'total'         => $total,
                    'exam_title'    => $exam->title,
                    'passing_score' => $exam->passing_score,
                    'late'          => $late,
                    'message'       => $late ? 'Submitted late (accepted).' : 'Submitted successfully.',
                ];
            });
        } catch (\Illuminate\Database\QueryException $e) {
            // Unique client_submission_id race — return existing
            if ($clientSubmissionId && str_contains($e->getMessage(), 'submissions_client_idempotent')) {
                $existing = Submission::where('client_submission_id', $clientSubmissionId)
                    ->where('student_id', $student->id)
                    ->first();
                if ($existing) {
                    return response()->json([
                        'submission_id' => $existing->submission_id,
                        'score'         => $existing->score,
                        'result'        => $existing->result,
                        'correct'       => $existing->correct_answers,
                        'total'         => $existing->total_questions,
                        'exam_title'    => $existing->exam_title,
                        'passing_score' => $exam->passing_score,
                        'message'       => 'Already submitted.',
                        'idempotent'    => true,
                    ]);
                }
            }
            throw $e;
        }

        $submittedSession = [
            'studentId'   => $student->id,
            'studentName' => $student->name,
            'examId'      => $exam->exam_id,
            'examTitle'   => $exam->title,
            'centreName'  => $student->centre_name,
            'score'       => $score,
            'result'      => $result,
            'submittedAt' => now()->toISOString(),
        ];
        try {
            broadcast(new StudentExamActivity($submittedSession, 'submitted'));
        } catch (\Throwable $e) {
            \Log::warning('Reverb broadcast failed (submit): ' . $e->getMessage());
        }

        return response()->json($payload);
    }

    public function submissionResult(Request $request, $submissionId)
    {
        $sub = Submission::with(['exam', 'answers.question'])->where('submission_id', $submissionId)->first();

        if (!$sub) {
            return response()->json(['status' => 'not_found', 'message' => 'Submission not found.'], 404);
        }

        if ((int) $sub->student_id !== (int) $request->user()->id) {
            abort(403, 'Not your submission.');
        }

        $answers = $sub->answers->map(fn ($a) => [
            'question_id'     => $a->question_id,
            'question_text'   => $a->question?->text,
            'options'         => $a->question?->options,
            'selected_answer' => $a->selected_answer,
            'correct_answer'  => $a->question?->correct_answer,
            'is_correct'      => $a->is_correct,
        ]);

        return response()->json([
            'status' => 'done',
            'submission' => [
                'submission_id'    => $sub->submission_id,
                'submission_db_id' => $sub->id,
                'score'            => $sub->score,
                'result'           => $sub->result,
                'correct_answers'  => $sub->correct_answers,
                'total_questions'  => $sub->total_questions,
                'exam_title'       => $sub->exam_title,
                'passing_score'    => $sub->exam?->passing_score,
                'submitted_at'     => $sub->submitted_at,
                'student_name'     => $sub->student_name,
                'answers'          => $answers,
            ],
        ]);
    }

    public function myHistory(Request $request)
    {
        $student = $request->user();
        $subs    = Submission::where('student_id', $student->id)
            ->latest('submitted_at')
            ->get([
                'submission_id', 'exam_title', 'score',
                'correct_answers', 'total_questions', 'result', 'submitted_at',
            ]);

        return response()->json($subs);
    }
}
