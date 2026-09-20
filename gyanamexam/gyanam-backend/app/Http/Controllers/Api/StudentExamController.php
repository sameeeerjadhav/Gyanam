<?php

namespace App\Http\Controllers\Api;

use App\Events\StudentExamActivity;
use App\Http\Controllers\Controller;
use App\Models\ExamAnswerDraft;
use App\Models\ExamConfig;
use App\Models\ProctoringEvent;
use App\Models\Question;
use App\Models\QuestionBankAssignment;
use App\Models\Submission;
use App\Services\ExamCourseAssignmentService;
use App\Services\LiveSessionService;
use App\Services\ProctorMediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class StudentExamController extends Controller
{
    public function __construct(
        private LiveSessionService $liveSessions,
        private ProctorMediaService $proctorMedia,
    ) {}

    /**
     * Get exams for this student (course-matched only):
     * - Demo: visible + startable with unlimited attempts
     * - Main: visible but locked until ATC unlocks (hall ticket / schedule assignment)
     */
    public function myExams(Request $request)
    {
        $student = $request->user();

        // Ensure course exams exist on pivot (covers existing configs + newly synced students)
        app(ExamCourseAssignmentService::class)->assignCourseExamsToStudent($student);

        $exams = $student->exams()
            ->where('exam_configs.active', true)
            ->where('exam_configs.is_global_practice', false)
            ->withPivot(['max_attempts', 'assigned_at', 'assigned_by_user_id'])
            ->get([
                'exam_configs.id', 'exam_configs.exam_id', 'exam_configs.title',
                'exam_configs.subject', 'exam_configs.exam_type', 'exam_configs.duration',
                'exam_configs.total_questions', 'exam_configs.passing_score',
                'exam_configs.instructions', 'exam_configs.proctored',
                'exam_configs.proctoring_settings', 'exam_configs.is_global_practice',
                'exam_configs.question_bank_id',
            ])
            // Explicit ATC/admin assign always visible; auto-attached still need course match
            ->filter(function ($exam) use ($student) {
                if (!empty($exam->pivot->assigned_by_user_id)) {
                    return true;
                }
                return ExamCourseAssignmentService::coursesMatch($student->course, $exam->subject);
            })
            ->values();

        // Question bank must be assigned to student's ATC — except explicit unlocks
        $centre = trim((string) ($student->centre_name ?? ''));
        if ($centre !== '') {
            $exams = $exams->filter(function ($exam) use ($centre) {
                if (!empty($exam->pivot->assigned_by_user_id)) {
                    return true;
                }
                $bankId = (int) ($exam->question_bank_id ?? 0);
                if ($bankId <= 0) {
                    return false;
                }
                return QuestionBankAssignment::where('question_bank_id', $bankId)
                    ->where('centre_id', $centre)
                    ->exists();
            })->values();
        }

        $examIds = $exams->pluck('id')->all();
        $usedByExam = empty($examIds)
            ? collect()
            : $student->submissions()
                ->whereIn('exam_config_id', $examIds)
                ->selectRaw('exam_config_id, COUNT(*) as used')
                ->groupBy('exam_config_id')
                ->pluck('used', 'exam_config_id');

        $payload = $exams->map(function ($exam) use ($usedByExam) {
            $isDemo = ExamCourseAssignmentService::isDemoExam($exam);
            $unlocked = $isDemo || !empty($exam->pivot->assigned_by_user_id);
            $usedAttempts = (int) ($usedByExam[$exam->id] ?? 0);
            $maxAttempts = $isDemo
                ? ExamCourseAssignmentService::DEMO_MAX_ATTEMPTS
                : (int) ($exam->pivot->max_attempts ?? 1);

            if ($isDemo) {
                return array_merge($exam->only([
                    'id', 'exam_id', 'title', 'subject', 'exam_type',
                    'duration', 'total_questions', 'passing_score', 'instructions',
                ]), [
                    'proctored' => (bool) $exam->proctored,
                    'proctoring_settings' => $exam->proctored ? ($exam->proctoring_settings ?? []) : null,
                    'is_global_practice' => false,
                    'access_status' => 'demo_open',
                    'locked' => false,
                    'lock_reason' => null,
                    'attempt_info' => [
                        'max_attempts'  => null,
                        'used_attempts' => $usedAttempts,
                        'remaining'     => null,
                        'can_attempt'   => true,
                        'unlimited'     => true,
                    ],
                    'is_demo' => true,
                ]);
            }

            if (!$unlocked) {
                return array_merge($exam->only([
                    'id', 'exam_id', 'title', 'subject', 'exam_type',
                    'duration', 'total_questions', 'passing_score', 'instructions',
                ]), [
                    'proctored' => (bool) $exam->proctored,
                    'proctoring_settings' => $exam->proctored ? ($exam->proctoring_settings ?? []) : null,
                    'is_global_practice' => false,
                    'access_status' => 'awaiting_hall_ticket',
                    'locked' => true,
                    'lock_reason' => 'Locked until your ATC generates your hall ticket.',
                    'attempt_info' => [
                        'max_attempts'  => $maxAttempts,
                        'used_attempts' => $usedAttempts,
                        'remaining'     => 0,
                        'can_attempt'   => false,
                        'unlimited'     => false,
                    ],
                    'is_demo' => false,
                ]);
            }

            return array_merge($exam->only([
                'id', 'exam_id', 'title', 'subject', 'exam_type',
                'duration', 'total_questions', 'passing_score', 'instructions',
            ]), [
                'proctored' => (bool) $exam->proctored,
                'proctoring_settings' => $exam->proctored ? ($exam->proctoring_settings ?? []) : null,
                'is_global_practice' => false,
                'access_status' => 'assigned',
                'locked' => false,
                'lock_reason' => null,
                'attempt_info' => [
                    'max_attempts'  => $maxAttempts,
                    'used_attempts' => $usedAttempts,
                    'remaining'     => max(0, $maxAttempts - $usedAttempts),
                    'can_attempt'   => $usedAttempts < $maxAttempts,
                    'unlimited'     => false,
                ],
                'is_demo' => false,
            ]);
        })->values();

        return response()->json($payload);
    }

    /** Whether student may start a course demo without prior ATC unlock. */
    private function studentMayStartCourseDemo($student, ExamConfig $exam): bool
    {
        if (!(bool) $exam->active || (bool) $exam->is_global_practice) {
            return false;
        }
        if (!ExamCourseAssignmentService::isDemoExam($exam)) {
            return false;
        }
        if (!ExamCourseAssignmentService::coursesMatch($student->course ?? '', $exam->subject)) {
            return false;
        }
        $centre = trim((string) ($student->centre_name ?? ''));
        $bankId = (int) ($exam->question_bank_id ?? 0);
        if ($centre === '' || $bankId <= 0) {
            return false;
        }
        return QuestionBankAssignment::where('question_bank_id', $bankId)
            ->where('centre_id', $centre)
            ->exists();
    }

    /**
     * Get (shuffled/cached) questions for an exam session + resume draft + remaining time.
     */
    public function getQuestions(Request $request, $examId)
    {
        $student = $request->user();
        // Avoid eager-loading the full bank on every start — use shared bank cache below
        $exam = ExamConfig::findOrFail($examId);

        if ((bool) $exam->is_global_practice) {
            abort(403, 'Practice exams are no longer available.');
        }

        // Course-only gate
        if (!ExamCourseAssignmentService::coursesMatch($student->course ?? '', $exam->subject)) {
            abort(403, 'This exam is not available for your registered course.');
        }

        $pivot = $student->exams()
            ->where('exam_config_id', $examId)
            ->withPivot(['max_attempts', 'assigned_by_user_id'])
            ->first()?->pivot;

        $isDemo = ExamCourseAssignmentService::isDemoExam($exam);

        // Course demo: allow start without prior ATC unlock (auto-attach)
        if (!$pivot && $isDemo && $this->studentMayStartCourseDemo($student, $exam)) {
            $student->exams()->syncWithoutDetaching([
                (int) $examId => [
                    'max_attempts'        => ExamCourseAssignmentService::DEMO_MAX_ATTEMPTS,
                    'assigned_by_user_id' => null,
                    'assigned_at'         => now(),
                ],
            ]);
            $pivot = $student->exams()
                ->where('exam_config_id', $examId)
                ->withPivot(['max_attempts', 'assigned_by_user_id'])
                ->first()?->pivot;
        }

        if (!$pivot) {
            abort(403, 'This exam is locked until your ATC generates your hall ticket.');
        }

        // Main: require ATC unlock (assigned_by_user_id set via schedule / hall-ticket assign)
        if (!$isDemo && empty($pivot->assigned_by_user_id)) {
            abort(403, 'This exam is locked until your ATC generates your hall ticket.');
        }

        $maxAttempts = $isDemo
            ? ExamCourseAssignmentService::DEMO_MAX_ATTEMPTS
            : (int) ($pivot->max_attempts ?? 1);

        $usedAttempts = $student->submissions()->where('exam_config_id', $examId)->count();
        if (!$isDemo && $usedAttempts >= $maxAttempts) {
            abort(403, "No attempts remaining. You have used {$usedAttempts}/{$maxAttempts} attempt(s).");
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

        $bankId = (int) ($exam->question_bank_id ?? 0);
        if ($bankId <= 0) {
            abort(422, 'This exam has no question bank configured.');
        }

        // New attempts require an active exam; in-progress sessions may still finish
        if (!$exam->active && !$existing) {
            abort(403, 'This exam is not currently active.');
        }

        $cacheKey = "exam_qs:{$examId}:{$student->id}:{$attemptNumber}";
        $ttl = max(60, ((int) $exam->duration + 120) * 60);
        $needed = max(1, (int) $exam->total_questions);

        $questionIds = $this->resolveAttemptQuestionIds(
            $existing,
            $exam,
            (int) $examId,
            $bankId,
            $needed,
            $cacheKey,
            $legacyCacheKey,
            $ttl
        );

        if (empty($questionIds)) {
            abort(422, 'No questions available in this exam\'s question bank. Contact admin.');
        }

        $session = $this->liveSessions->startOrResume(
            (int) $student->id,
            (int) $examId,
            $meta,
            (int) $exam->duration,
            $attemptNumber,
            $questionIds
        );
        // Always persist the bank-validated set (fixes corrupt / cross-bank sessions)
        $this->liveSessions->forceSetQuestionIds($session, $questionIds);

        try {
            if (!in_array(config('broadcasting.default'), ['log', 'null', ''], true)) {
                broadcast(new StudentExamActivity($session->toMonitorArray(), 'started'));
            }
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

        // Drop draft answers that are not part of this attempt's bank-scoped paper
        if ($draft && is_array($draft->answers)) {
            $allowed = array_fill_keys(array_map('strval', $questionIds), true);
            $cleanAnswers = [];
            foreach ($draft->answers as $qid => $ans) {
                if (isset($allowed[(string) $qid])) {
                    $cleanAnswers[$qid] = $ans;
                }
            }
            if ($cleanAnswers !== $draft->answers) {
                $draft->answers = $cleanAnswers;
                $draft->save();
            }
        }

        $remaining = $this->liveSessions->remainingSeconds($session);
        $expired   = $remaining < 0;
        $pastLate  = $this->liveSessions->isPastLateWindow($session);

        // Hydrate from DB — strictly this exam's question bank only (never leak correct_answer)
        $fresh = Question::where('question_bank_id', $bankId)
            ->whereIn('id', $questionIds)
            ->get(['id', 'text', 'text_mr', 'options'])
            ->keyBy(fn ($q) => (string) $q->id);
        $safeQuestions = [];
        foreach ($questionIds as $qid) {
            $key = (string) $qid;
            if (!$fresh->has($key)) {
                continue;
            }
            $row = $fresh[$key];
            $safeQuestions[] = [
                'id'      => $row->id,
                'text'    => $row->text,
                'text_mr' => $row->text_mr,
                'options' => $this->normalizeOptionsForStudent($row->options),
            ];
        }

        if (empty($safeQuestions)) {
            abort(422, 'Could not load questions for this exam. Contact admin.');
        }

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

        // Only keep answers for questions on this attempt's bank-scoped paper
        $exam = ExamConfig::find($examId);
        $bankId = (int) ($exam?->question_bank_id ?? 0);
        $allowedIds = $this->filterQuestionIdsToBank($session?->question_ids ?? [], $bankId);
        if (!empty($allowedIds)) {
            $allowed = array_fill_keys(array_map('strval', $allowedIds), true);
            $answers = array_filter(
                $answers,
                static fn ($v, $k) => isset($allowed[(string) $k]),
                ARRAY_FILTER_USE_BOTH
            );
        } else {
            // No locked paper yet — reject draft writes that could poison the attempt
            $answers = [];
        }

        $marks = $data['marked_for_review'] ?? [];
        if (!empty($allowedIds) && is_array($marks)) {
            $allowed = array_fill_keys(array_map('strval', $allowedIds), true);
            $marks = array_values(array_filter(
                $marks,
                static fn ($id) => isset($allowed[(string) $id])
            ));
        } else {
            $marks = [];
        }

        $existing = ExamAnswerDraft::where('student_id', $student->id)
            ->where('exam_config_id', $examId)
            ->first();

        // Skip DB write when nothing changed (cuts shared-hosting write load)
        if ($existing
            && (int) $existing->attempt_number === (int) ($session?->attempt_number ?? $existing->attempt_number)
            && json_encode($existing->answers ?? []) === json_encode($answers)
            && json_encode($existing->marked_for_review ?? []) === json_encode($marks)
        ) {
            return response()->json([
                'ok'             => true,
                'updated_at'     => optional($existing->updated_at)->toISOString(),
                'attempt_number' => (int) $existing->attempt_number,
                'unchanged'      => true,
            ]);
        }

        $draft = ExamAnswerDraft::updateOrCreate(
            [
                'student_id'     => $student->id,
                'exam_config_id' => $examId,
            ],
            [
                'answers'           => $answers,
                'marked_for_review' => $marks,
                'attempt_number'    => $session?->attempt_number ?? 1,
            ]
        );

        // Heartbeat already covers liveness — skip extra touch() write here

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
        $type = (string) $data['event_type'];

        // Drop duplicate noisy events (devtools / copy spam) within a short window
        $dropTtl = match ($type) {
            'auto_submit', 'tab_switch_exceeded' => 0,
            'devtools' => 45,
            'copy_paste', 'right_click' => 20,
            'fullscreen_exit', 'fullscreen_required' => 20,
            default => 15,
        };
        if ($dropTtl > 0) {
            $key = "proc_evt:{$student->id}:{$examId}:{$type}";
            if (!Cache::add($key, 1, $dropTtl)) {
                return response()->json(['ok' => true, 'dropped' => true]);
            }
        }

        ProctoringEvent::create([
            'student_id'     => $student->id,
            'exam_config_id' => $examId,
            'event_type'     => $type,
            'message'        => $data['message'] ?? null,
            'meta'           => $data['meta'] ?? null,
            'created_at'     => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Upload one-time identity photo for the active live session (small JPEG only).
     */
    public function uploadProctorPhoto(Request $request, $examId)
    {
        $data = $request->validate([
            'photo' => 'required|string',
        ]);

        $student = $request->user();
        $session = $this->liveSessions->find((int) $student->id, (int) $examId);
        if (!$session) {
            abort(404, 'No active exam session. Start the exam first.');
        }

        $this->proctorMedia->savePhoto($session, $data['photo']);
        cache()->forget('live_active:all');
        if ($session->centre_name) {
            cache()->forget('live_active:' . $session->centre_name);
        }

        return response()->json(['ok' => true, 'has_photo' => true]);
    }

    /**
     * Mark camera availability and exchange WebRTC signaling (SDP/ICE only — no media).
     */
    public function proctorSignal(Request $request, $examId)
    {
        $data = $request->validate([
            'action'         => 'required|in:status,offer,answer,ice,clear',
            'camera_active'  => 'sometimes|boolean',
            'sdp'            => 'nullable|array',
            'candidate'      => 'nullable|array',
            'role'           => 'nullable|in:student,viewer',
        ]);

        $student = $request->user();
        $session = $this->liveSessions->find((int) $student->id, (int) $examId);
        if (!$session) {
            abort(404, 'No active exam session.');
        }

        if (array_key_exists('camera_active', $data)) {
            $session->camera_active = (bool) $data['camera_active'];
            $session->save();
            cache()->forget('live_active:all');
            if ($session->centre_name) {
                cache()->forget('live_active:' . $session->centre_name);
            }
        }

        $sid = (int) $student->id;
        $eid = (int) $examId;
        $signals = $this->proctorMedia->getSignals($sid, $eid);

        if ($data['action'] === 'clear') {
            $this->proctorMedia->clearSignals($sid, $eid);
            return response()->json(['ok' => true, 'signals' => $this->proctorMedia->getSignals($sid, $eid)]);
        }

        if ($data['action'] === 'offer' && !empty($data['sdp'])) {
            $signals['offer'] = $data['sdp'];
            $signals['answer'] = null;
            $signals['ice_student'] = [];
            $signals['ice_viewer'] = [];
            $this->proctorMedia->putSignals($sid, $eid, $signals);
        } elseif ($data['action'] === 'answer' && !empty($data['sdp'])) {
            $signals['answer'] = $data['sdp'];
            $this->proctorMedia->putSignals($sid, $eid, $signals);
        } elseif ($data['action'] === 'ice' && !empty($data['candidate'])) {
            $role = $data['role'] ?? 'student';
            $key = $role === 'viewer' ? 'ice_viewer' : 'ice_student';
            $list = $signals[$key] ?? [];
            $list[] = $data['candidate'];
            // keep last 30 candidates
            $signals[$key] = array_slice($list, -30);
            $this->proctorMedia->putSignals($sid, $eid, $signals);
        } else {
            // refresh TTL on status poll
            $this->proctorMedia->putSignals($sid, $eid, $signals);
        }

        return response()->json([
            'ok' => true,
            'camera_active' => (bool) $session->camera_active,
            'signals' => $this->proctorMedia->getSignals($sid, $eid),
        ]);
    }

    public function getProctorSignal(Request $request, $examId)
    {
        $student = $request->user();
        $session = $this->liveSessions->find((int) $student->id, (int) $examId);
        if (!$session) {
            abort(404, 'No active exam session.');
        }

        return response()->json([
            'ok' => true,
            'camera_active' => (bool) $session->camera_active,
            'signals' => $this->proctorMedia->getSignals((int) $student->id, (int) $examId),
        ]);
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
            $questionIds = array_map(
                static fn ($q) => is_array($q) ? ($q['id'] ?? null) : $q,
                $cachedQuestions
            );
            $questionIds = array_values(array_filter($questionIds, static fn ($id) => $id !== null && $id !== ''));
        }

        if (empty($questionIds)) {
            return response()->json([
                'message' => 'Cannot grade: question set for this attempt is missing. Contact admin.',
            ], 422);
        }

        $bankId = (int) ($exam->question_bank_id ?? 0);
        $questionIds = $this->filterQuestionIdsToBank($questionIds, $bankId);
        if (empty($questionIds)) {
            return response()->json([
                'message' => 'Cannot grade: no questions from this exam\'s question bank. Contact admin.',
            ], 422);
        }

        // Persist cleaned set so grading matches what student was shown
        if ($session) {
            $prev = array_map('strval', array_values($session->question_ids ?? []));
            $next = array_map('strval', array_values($questionIds));
            if ($prev !== $next) {
                $session->question_ids = array_values($questionIds);
                $session->save();
            }
        }

        $correctMap = Question::where('question_bank_id', $bankId)
            ->whereIn('id', $questionIds)
            ->pluck('correct_answer', 'id')
            ->mapWithKeys(static fn ($ans, $id) => [(string) $id => (string) $ans])
            ->all();

        // Normalize client answers to string keys (JSON often sends string ids)
        $answersById = [];
        foreach ($formattedAnswers as $qid => $ans) {
            $answersById[(string) $qid] = $ans;
        }

        $total      = count($questionIds);
        $correct    = 0;
        $answerRows = [];

        foreach ($questionIds as $qId) {
            $key = (string) $qId;
            $selected = array_key_exists($key, $answersById) ? $answersById[$key] : null;
            $isCorrect = $selected !== null
                && array_key_exists($key, $correctMap)
                && $correctMap[$key] === (string) $selected;
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

                $isDemo = ExamCourseAssignmentService::isDemoExam($exam);
                if (!$isDemo && empty($pivot->assigned_by_user_id)) {
                    abort(403, 'This exam is locked until your ATC generates your hall ticket.');
                }

                $usedAttempts = Submission::where('student_id', $student->id)
                    ->where('exam_config_id', $examId)
                    ->lockForUpdate()
                    ->count();

                $maxAttempts = $isDemo
                    ? ExamCourseAssignmentService::DEMO_MAX_ATTEMPTS
                    : (int) ($pivot->max_attempts ?? 1);
                if (!$isDemo && $usedAttempts >= $maxAttempts) {
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
            if (!in_array(config('broadcasting.default'), ['log', 'null', ''], true)) {
                broadcast(new StudentExamActivity($submittedSession, 'submitted'));
            }
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

    /**
     * Resolve the locked question set for this attempt from ONE bank only.
     * Prefer live-session IDs, then attempt cache, else pick a fresh subset.
     *
     * @return list<int>
     */
    private function resolveAttemptQuestionIds(
        $existing,
        ExamConfig $exam,
        int $examConfigId,
        int $bankId,
        int $needed,
        string $cacheKey,
        string $legacyCacheKey,
        int $ttl
    ): array {
        // 1) Live session set (stable for grading) — bank-filtered
        if ($existing && !empty($existing->question_ids)) {
            $ids = $this->filterQuestionIdsToBank($existing->question_ids, $bankId);
            $originalCount = count($existing->question_ids);
            // Any ID from outside this bank → discard and rebuild
            if (count($ids) === $originalCount && count($ids) > 0) {
                return $ids;
            }
            Cache::forget($cacheKey);
            Cache::forget($legacyCacheKey);
        }

        // 2) Attempt cache — bank-filtered; drop if tainted
        $cached = Cache::get($cacheKey) ?: Cache::get($legacyCacheKey);
        if (is_array($cached) && !empty($cached)) {
            $ids = $this->filterQuestionIdsToBank(
                array_map(static fn ($q) => is_array($q) ? ($q['id'] ?? null) : $q, $cached),
                $bankId
            );
            $rawCount = count($cached);
            if (count($ids) === $rawCount && count($ids) > 0) {
                return $ids;
            }
            Cache::forget($cacheKey);
            Cache::forget($legacyCacheKey);
        }

        // 3) Fresh pick from the linked bank only
        $picked = $this->pickQuestionsFromBank($exam, $examConfigId, $bankId, $needed);
        if (empty($picked)) {
            return [];
        }
        Cache::put($cacheKey, $picked, $ttl);

        return $this->filterQuestionIdsToBank(
            array_map(static fn ($q) => $q['id'] ?? null, $picked),
            $bankId
        );
    }

    /**
     * Random (or ordered) subset from exactly one question bank.
     *
     * @return list<array<string,mixed>>
     */
    private function pickQuestionsFromBank(ExamConfig $exam, int $examConfigId, int $bankId, int $needed): array
    {
        $bankQs = $this->loadBankQuestionsCached($examConfigId, $bankId);
        if (empty($bankQs)) {
            return [];
        }

        // Copy before shuffle so the shared bank cache is never mutated
        $qs = array_values($bankQs);
        if ($exam->randomize_questions) {
            shuffle($qs);
        }

        return array_slice($qs, 0, max(1, $needed));
    }

    /**
     * Ensure each option exposes id/text/text_mr for the student exam UI.
     *
     * @param  mixed  $options
     * @return list<array{id:mixed,text:string,text_mr:?string}>
     */
    private function normalizeOptionsForStudent(mixed $options): array
    {
        if (is_string($options)) {
            $decoded = json_decode($options, true);
            $options = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($options)) {
            return [];
        }

        $out = [];
        foreach ($options as $opt) {
            if (!is_array($opt)) {
                continue;
            }
            $mr = $opt['text_mr'] ?? $opt['textMr'] ?? $opt['mr'] ?? null;
            $mr = is_string($mr) ? trim($mr) : '';
            $out[] = [
                'id'      => $opt['id'] ?? null,
                'text'    => (string) ($opt['text'] ?? ''),
                'text_mr' => $mr !== '' ? $mr : null,
            ];
        }
        return $out;
    }

    /**
     * Load questions for one bank only (never pool across banks).
     * Cache key includes bank id so changing an exam's bank cannot serve stale papers.
     *
     * @return list<array<string,mixed>>
     */
    private function loadBankQuestionsCached(int $examConfigId, int $bankId): array
    {
        $cacheKey = "exam_bank_qs:{$examConfigId}:bank:{$bankId}";
        // Drop legacy key that did not include bank id
        Cache::forget("exam_bank_qs:{$examConfigId}");

        return Cache::remember($cacheKey, 300, function () use ($bankId) {
            return Question::where('question_bank_id', $bankId)
                ->orderBy('order')
                ->orderBy('id')
                ->get()
                ->map(static function ($q) {
                    // Never cache correct answers in shared bank payloads used for paper picks
                    return [
                        'id'      => $q->id,
                        'text'    => $q->text,
                        'text_mr' => $q->text_mr,
                        'options' => $q->options,
                        'order'   => $q->order,
                        'question_bank_id' => $q->question_bank_id,
                    ];
                })
                ->values()
                ->all();
        });
    }

    /**
     * Keep only question IDs that belong to the given bank, preserving order.
     *
     * @param  array<int|string|null>  $ids
     * @return list<int>
     */
    private function filterQuestionIdsToBank(array $ids, int $bankId): array
    {
        if ($bankId <= 0 || empty($ids)) {
            return [];
        }

        $ids = array_values(array_filter($ids, static fn ($id) => $id !== null && $id !== ''));
        if (empty($ids)) {
            return [];
        }

        $allowed = Question::where('question_bank_id', $bankId)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn ($id) => (string) $id)
            ->all();
        $allowedSet = array_fill_keys($allowed, true);

        $ordered = [];
        foreach ($ids as $id) {
            $key = (string) $id;
            if (isset($allowedSet[$key])) {
                $ordered[] = (int) $id;
            }
        }
        return $ordered;
    }
}
