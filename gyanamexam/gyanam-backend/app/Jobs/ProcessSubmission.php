<?php

namespace App\Jobs;

use App\Models\ExamConfig;
use App\Models\LiveExamSession;
use App\Models\Question;
use App\Models\Student;
use App\Models\Submission;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Legacy async grader — keep bank-safe if ever re-enabled.
 * Live path grades synchronously in StudentExamController::submit.
 */
class ProcessSubmission implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $submissionId,
        public int    $studentId,
        public int    $examConfigId,
        public array  $answers,   // ['questionId' => 'selectedOptionId', ...]
    ) {}

    public function handle(): void
    {
        $student = Student::findOrFail($this->studentId);
        $exam    = ExamConfig::findOrFail($this->examConfigId);
        $bankId  = (int) ($exam->question_bank_id ?? 0);

        $session = LiveExamSession::where('student_id', $this->studentId)
            ->where('exam_config_id', $this->examConfigId)
            ->first();

        $questionIds = $session?->question_ids ?: [];
        if (empty($questionIds)) {
            $attempt = (int) ($session?->attempt_number ?: 1);
            $cacheKey = "exam_qs:{$this->examConfigId}:{$this->studentId}:{$attempt}";
            $legacyCacheKey = "exam_qs:{$this->examConfigId}:{$this->studentId}";
            $cached = Cache::get($cacheKey) ?: Cache::get($legacyCacheKey, []);
            $questionIds = array_map(
                static fn ($q) => is_array($q) ? ($q['id'] ?? null) : $q,
                is_array($cached) ? $cached : []
            );
        }

        // Strict: only grade IDs that belong to this exam's question bank.
        // Never fall back to client-supplied answer keys (could span other banks).
        $questionIds = array_values(array_filter($questionIds, static fn ($id) => $id !== null && $id !== ''));
        if ($bankId > 0 && !empty($questionIds)) {
            $allowed = Question::where('question_bank_id', $bankId)
                ->whereIn('id', $questionIds)
                ->pluck('id')
                ->map(static fn ($id) => (string) $id)
                ->all();
            $allowedSet = array_fill_keys($allowed, true);
            $questionIds = array_values(array_filter(
                $questionIds,
                static fn ($id) => isset($allowedSet[(string) $id])
            ));
        } else {
            $questionIds = [];
        }

        if (empty($questionIds)) {
            throw new \RuntimeException('Cannot grade: no bank-scoped question set for this attempt.');
        }

        $correctMap = Question::where('question_bank_id', $bankId)
            ->whereIn('id', $questionIds)
            ->pluck('correct_answer', 'id')
            ->mapWithKeys(static fn ($ans, $id) => [(string) $id => (string) $ans])
            ->all();

        $answersById = [];
        foreach ($this->answers as $qid => $ans) {
            $answersById[(string) $qid] = $ans;
        }

        $total = count($questionIds);
        $correct = 0;
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

        $submission = Submission::create([
            'submission_id'  => $this->submissionId,
            'student_id'     => $this->studentId,
            'exam_config_id' => $this->examConfigId,
            'exam_title'     => $exam->title,
            'student_name'   => $student->name,
            'centre_name'    => $student->centre_name,
            'score'          => $score,
            'correct_answers'=> $correct,
            'total_questions'=> $total,
            'result'         => $result,
            'submitted_at'   => now(),
        ]);

        foreach ($answerRows as $row) {
            $submission->answers()->create($row);
        }

        $attempt = (int) ($session?->attempt_number ?: 1);
        Cache::forget("exam_qs:{$this->examConfigId}:{$this->studentId}:{$attempt}");
        Cache::forget("exam_qs:{$this->examConfigId}:{$this->studentId}");
        Cache::forget("live:{$this->studentId}:{$this->examConfigId}");
    }
}
