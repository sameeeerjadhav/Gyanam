<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamConfig;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Events\StudentExamActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class StudentExamController extends Controller
{
    /**
     * Get exams assigned to this student by ATC/DLC/Admin.
     * Returns attempt info so the frontend can show how many attempts remain.
     */
    public function myExams(Request $request)
    {
        $student = $request->user();

        // Load assigned exams via pivot — includes assignment metadata
        $exams = $student->exams()
            ->where('exam_configs.active', true)
            ->withPivot(['max_attempts', 'assigned_at', 'assigned_by_user_id'])
            ->get([
                'exam_configs.id', 'exam_configs.exam_id', 'exam_configs.title',
                'exam_configs.subject', 'exam_configs.exam_type', 'exam_configs.duration',
                'exam_configs.total_questions', 'exam_configs.passing_score',
                'exam_configs.instructions',
            ])
            ->map(function ($exam) use ($student) {
                $maxAttempts  = $exam->pivot->max_attempts ?? 1;
                $usedAttempts = $student->submissions()
                    ->where('exam_config_id', $exam->id)
                    ->count();

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

        return response()->json($exams);
    }


    /**
     * Get (shuffled/cached) questions for an exam session.
     */
    public function getQuestions(Request $request, $examId)
    {
        $student = $request->user();
        $exam    = ExamConfig::with('questionBank.questions')->findOrFail($examId);

        // Verify student is assigned this exam and has attempts remaining
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

        $cacheKey = "exam_qs:{$examId}:{$student->id}";
        $ttl      = $exam->duration * 60;

        $questions = Cache::remember($cacheKey, $ttl, function () use ($exam) {
            $qs = $exam->questionBank->questions->toArray();
            if ($exam->randomize_questions) shuffle($qs);
            return array_slice($qs, 0, $exam->total_questions);
        });

        // Register live session (file cache so no Redis needed)
        $sessionKey = "live:{$student->id}:{$examId}";
        Cache::put($sessionKey, [
            'studentId'   => $student->id,
            'studentName' => $student->name,
            'examId'      => $exam->exam_id,
            'examTitle'   => $exam->title,
            'centreName'  => $student->centre_name,
            'startedAt'   => now()->toISOString(),
            'lastSeen'    => now()->toISOString(),
        ], 7200); // 2 hour TTL

        // Update central index of live sessions
        $liveIndex = Cache::get('live_session_index', []);
        if (!in_array($sessionKey, $liveIndex)) {
            $liveIndex[] = $sessionKey;
            Cache::put('live_session_index', $liveIndex, 7200);
        }

        // Broadcast session start to admin live-monitoring channel
        $sessionData = Cache::get($sessionKey);
        try {
            broadcast(new StudentExamActivity($sessionData, 'started'));
        } catch (\Throwable $e) {
            \Log::warning('Reverb broadcast failed (session start): ' . $e->getMessage());
        }

        // Strip correct_answer from response
        $safeQuestions = array_map(fn($q) => [
            'id'      => $q['id'],
            'text'    => $q['text'],
            'options' => $q['options'],
        ], $questions);

        return response()->json([
            'exam'      => [
                'id'              => $exam->id,
                'exam_id'         => $exam->exam_id,
                'title'           => $exam->title,
                'exam_type'       => $exam->exam_type,
                'duration'        => $exam->duration,
                'total_questions' => count($safeQuestions),
                'passing_score'   => $exam->passing_score,
                'proctored'       => (bool) $exam->proctored,
                'proctoring_settings' => $exam->proctored ? ($exam->proctoring_settings ?? []) : [],
            ],
            'questions' => $safeQuestions,
        ]);
    }

    /**
     * Heartbeat — keep live session alive.
     */
    public function heartbeat(Request $request, $examId)
    {
        $student    = $request->user();
        $sessionKey = "live:{$student->id}:{$examId}";
        $session    = Cache::get($sessionKey);

        if ($session) {
            $session['lastSeen'] = now()->toISOString();
            Cache::put($sessionKey, $session, 7200);

            // Broadcast heartbeat over WebSocket to admin
            try {
                broadcast(new StudentExamActivity($session, 'heartbeat'));
            } catch (\Throwable $e) {
                \Log::warning('Reverb broadcast failed (heartbeat): ' . $e->getMessage());
            }
        }

        return response()->json([
            'ok'          => true,
            'extraMinutes'=> $session['extraMinutes'] ?? 0,
        ]);
    }

    /**
     * Submit exam answers — graded SYNCHRONOUSLY, result returned immediately.
     * No queue worker needed.
     */
    public function submit(Request $request, $examId)
    {
        $request->validate(['answers' => 'required|array']);

        $student = $request->user();
        $exam    = ExamConfig::findOrFail($examId);

        $submissionId = Str::uuid()->toString();

        // Format answers: [qId => answer]
        $formattedAnswers = [];
        foreach ($request->input('answers', []) as $ans) {
            $formattedAnswers[$ans['question_id']] = $ans['answer'];
        }

        // Get cached questions (the shuffle the student saw) for grading
        $cacheKey        = "exam_qs:{$examId}:{$student->id}";
        $cachedQuestions = Cache::get($cacheKey, []);

        $correctMap = [];
        foreach ($cachedQuestions as $q) {
            $correctMap[$q['id']] = $q['correct_answer'];
        }

        // Fall back to DB if cache expired
        if (empty($correctMap)) {
            $exam->load('questionBank.questions');
            foreach ($exam->questionBank->questions as $q) {
                $correctMap[$q->id] = $q->correct_answer;
            }
        }

        $total      = count($correctMap);
        $correct    = 0;
        $answerRows = [];

        foreach ($formattedAnswers as $qId => $selected) {
            $isCorrect = isset($correctMap[$qId]) && (string)$correctMap[$qId] === (string)$selected;
            if ($isCorrect) $correct++;
            $answerRows[] = [
                'question_id'     => $qId,
                'selected_answer' => $selected,
                'is_correct'      => $isCorrect,
            ];
        }

        $score  = $total > 0 ? round($correct / $total * 100) : 0;
        $result = $score >= $exam->passing_score ? 'pass' : 'fail';

        // Calculate duration taken
        $sessionData   = Cache::get("live:{$student->id}:{$examId}");
        $durationTaken = 0;
        if ($sessionData && isset($sessionData['startedAt'])) {
            $durationTaken = now()->diffInSeconds(\Carbon\Carbon::parse($sessionData['startedAt']));
        }

        return \DB::transaction(function () use ($submissionId, $student, $examId, $exam, $score, $correct, $total, $result, $durationTaken, $answerRows, $cacheKey) {
            // Create submission immediately (synchronous)
            $submission = Submission::create([
                'submission_id'   => $submissionId,
                'student_id'      => $student->id,
                'exam_config_id'  => $examId,
                'exam_title'      => $exam->title,
                'student_name'    => $student->name,
                'centre_name'     => $student->centre_name,
                'score'           => $score,
                'correct_answers' => $correct,
                'total_questions' => $total,
                'result'          => $result,
                'duration_taken'  => $durationTaken,
                'submitted_at'    => now(),
            ]);

            // Save answers
            $submission->answers()->createMany($answerRows);

            // Clean up caches
            Cache::forget($cacheKey);
            Cache::forget("live:{$student->id}:{$examId}");
            
            // Remove from live index
            $liveIndex = Cache::get('live_session_index', []);
            $index = array_search("live:{$student->id}:{$examId}", $liveIndex);
            if ($index !== false) {
                unset($liveIndex[$index]);
                Cache::put('live_session_index', array_values($liveIndex), 7200);
            }

            // Broadcast submission event to admin live-monitoring channel
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

            // Return full result for the response closure
            return [
                'submission_id' => $submissionId,
                'score'         => $score,
                'result'        => $result,
                'correct'       => $correct,
                'total'         => $total,
                'exam_title'    => $exam->title,
                'passing_score' => $exam->passing_score,
                'message'       => 'Submitted successfully.',
            ];
        });
    }

    /**
     * Get submission result — now returns immediately since grading is synchronous.
     */
    public function submissionResult(Request $request, $submissionId)
    {
        $sub = Submission::with(['exam', 'answers.question'])->where('submission_id', $submissionId)->first();

        if (!$sub) {
            return response()->json(['status' => 'not_found', 'message' => 'Submission not found.'], 404);
        }

        $answers = $sub->answers->map(fn($a) => [
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
                'submission_id'   => $sub->submission_id,
                'submission_db_id'=> $sub->id,
                'score'           => $sub->score,
                'result'          => $sub->result,
                'correct_answers' => $sub->correct_answers,
                'total_questions' => $sub->total_questions,
                'exam_title'      => $sub->exam_title,
                'passing_score'   => $sub->exam?->passing_score,
                'submitted_at'    => $sub->submitted_at,
                'student_name'    => $sub->student_name,
                'answers'         => $answers,
            ],
        ]);
    }

    /**
     * Get a student's submission history.
     */
    public function myHistory(Request $request)
    {
        $student = $request->user();
        $subs    = Submission::where('student_id', $student->id)
            ->latest('submitted_at')
            ->get([
                'submission_id', 'exam_title', 'score',
                'correct_answers', 'total_questions', 'result', 'submitted_at'
            ]);

        return response()->json($subs);
    }
}
