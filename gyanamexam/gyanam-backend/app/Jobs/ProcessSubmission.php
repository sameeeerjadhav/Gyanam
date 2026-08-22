<?php

namespace App\Jobs;

use App\Models\ExamConfig;
use App\Models\Question;
use App\Models\Student;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

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
        $exam    = ExamConfig::with('questionBank.questions')->findOrFail($this->examConfigId);

        // Prefer live-session question set; never grade against the full bank
        $session = \App\Models\LiveExamSession::where('student_id', $this->studentId)
            ->where('exam_config_id', $this->examConfigId)
            ->first();

        $questionIds = $session?->question_ids ?: [];
        if (empty($questionIds)) {
            $attempt = (int) ($session?->attempt_number ?: 1);
            $cached = Cache::get("exam_qs:{$this->examConfigId}:{$this->studentId}:{$attempt}")
                ?: Cache::get("exam_qs:{$this->examConfigId}:{$this->studentId}", []);
            $questionIds = array_map(fn ($q) => $q['id'], $cached);
        }
        if (empty($questionIds)) {
            $questionIds = array_keys($this->answers);
        }

        $correctMap = Question::whereIn('id', $questionIds)->pluck('correct_answer', 'id')->all();
        $total = count($questionIds);
        $correct = 0;
        $answerRows = [];

        foreach ($questionIds as $qId) {
            $selected = $this->answers[$qId] ?? null;
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

        // Create submission record
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

        // Save per-question answers
        foreach ($answerRows as $row) {
            $submission->answers()->create($row);
        }

        // Clean up Redis
        Cache::forget($cacheKey);
        Cache::forget("live:{$this->studentId}:{$this->examConfigId}");
    }
}
