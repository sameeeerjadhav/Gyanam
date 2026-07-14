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

        // Retrieve the cached question set (same shuffle the student saw)
        $cacheKey      = "exam_qs:{$this->examConfigId}:{$this->studentId}";
        $cachedQuestions = Cache::get($cacheKey, []);

        // Build a lookup of questionId => correctAnswer
        $correctMap = [];
        foreach ($cachedQuestions as $q) {
            $correctMap[$q['id']] = $q['correct_answer'];
        }

        // If cache expired, fall back to DB (less secure but graceful)
        if (empty($correctMap)) {
            $exam->questionBank->questions->each(function ($q) use (&$correctMap) {
                $correctMap[$q->id] = $q->correct_answer;
            });
        }

        $total   = count($correctMap);
        $correct = 0;
        $answerRows = [];

        foreach ($this->answers as $qId => $selected) {
            $isCorrect = isset($correctMap[$qId]) && $correctMap[$qId] === $selected;
            if ($isCorrect) $correct++;
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
