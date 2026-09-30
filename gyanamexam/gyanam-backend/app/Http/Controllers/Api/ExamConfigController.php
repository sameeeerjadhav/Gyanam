<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamConfig;
use App\Models\LiveExamSession;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Student;
use App\Services\ExamCourseAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ExamConfigController extends Controller
{
    public function index(Request $request)
    {
        // Retire leftover global practice exams (feature removed)
        ExamConfig::where('is_global_practice', true)
            ->where('active', true)
            ->update(['active' => false]);

        $exams = ExamConfig::with(['questionBank', 'creator'])
            ->where('is_global_practice', false)
            ->orderByDesc('updated_at')
            ->get();
        return response()->json($exams);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'                => 'required|string',
            'subject'              => 'required|string',
            'exam_type'            => 'in:demo,main,practice',
            'duration'             => 'required|integer|min:1',
            'total_questions'      => 'required|integer|min:1',
            'passing_score'        => 'required|integer|min:1|max:100',
            'question_bank_id'     => 'required|exists:question_banks,id',
            'instructions'         => 'nullable|string',
            'active'               => 'boolean',
            'randomize_questions'  => 'boolean',
            'proctored'            => 'boolean',
            'proctoring_settings'  => 'nullable|array',
            'proctoring_settings.camera'           => 'boolean',
            'proctoring_settings.microphone'       => 'boolean',
            'proctoring_settings.copy_paste_block' => 'boolean',
            'proctoring_settings.right_click_block'=> 'boolean',
            'proctoring_settings.tab_switch_limit' => 'integer|min:0|max:10',
            'proctoring_settings.fullscreen_enforce' => 'boolean',
            'proctoring_settings.devtools_detect'  => 'boolean',
            'proctoring_settings.text_select_block'=> 'boolean',
        ]);

        $data['exam_id']             = 'exam_' . Str::random(8);
        $data['created_by_user_id']  = $request->user()->id;

        $bankCount = Question::where('question_bank_id', $data['question_bank_id'])->count();
        if ($bankCount < 1) {
            return response()->json(['message' => 'Selected question bank has no questions.'], 422);
        }
        if ((int) $data['total_questions'] > $bankCount) {
            return response()->json([
                'message' => "Questions to show ({$data['total_questions']}) cannot exceed bank size ({$bankCount}).",
            ], 422);
        }

        $exam = ExamConfig::create($data);

        // Auto-assign to every student registered for this course (demo open; main locked until ATC unlock)
        app(ExamCourseAssignmentService::class)->assignExamToCourseStudents($exam);

        return response()->json($exam->load('questionBank'), 201);
    }

    /**
     * Create one demo or main exam for each selected course that does not already have that type.
     * POST /api/v1/exam-configs/bulk-from-banks  { kind: demo|main, subjects: string[] }
     */
    public function bulkFromBanks(Request $request)
    {
        if (!$request->user()->isAdmin()) {
            return response()->json(['message' => 'Only an admin can bulk-create exams.'], 403);
        }

        $data = $request->validate([
            'kind' => 'required|in:demo,main',
            'subjects' => 'required|array|min:1|max:200',
            'subjects.*' => 'string|max:255',
        ]);
        $kind = $data['kind'];
        $wanted = [];
        foreach ($data['subjects'] as $name) {
            $label = trim((string) $name);
            $key = ExamCourseAssignmentService::normalizeCourse($label);
            if ($key !== '') {
                $wanted[$key] = $label;
            }
        }
        if ($wanted === []) {
            return response()->json(['message' => 'Select at least one course.'], 422);
        }

        set_time_limit(180);

        $proctored = $kind === 'main';
        $suffix = $kind === 'demo' ? 'DEMO' : 'MAIN';
        $proctoring = $proctored ? [
            'camera' => false,
            'microphone' => false,
            'copy_paste_block' => true,
            'right_click_block' => true,
            'tab_switch_limit' => 3,
            'fullscreen_enforce' => true,
            'devtools_detect' => true,
            'text_select_block' => true,
        ] : null;

        $existingBankIds = ExamConfig::query()
            ->where('exam_type', $kind)
            ->where('is_global_practice', false)
            ->pluck('question_bank_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $created = [];
        $skipped = [];
        $failed = [];
        $matched = [];

        $banks = QuestionBank::withCount('questions')->orderBy('subject')->orderBy('title')->get();
        foreach ($banks as $bank) {
            $course = trim((string) $bank->subject);
            $key = ExamCourseAssignmentService::normalizeCourse($course);
            if ($key === '' || !isset($wanted[$key])) {
                continue;
            }
            $matched[$key] = true;
            $label = $course !== '' ? $course : trim((string) $bank->title);
            $count = (int) $bank->questions_count;

            if (in_array((int) $bank->id, $existingBankIds, true)) {
                $skipped[] = ['bank' => $label, 'reason' => 'Already has a ' . $kind . ' exam'];
                continue;
            }
            if ($course === '') {
                $failed[] = ['bank' => $label !== '' ? $label : ('Bank #' . $bank->id), 'reason' => 'No course / subject on the question bank'];
                continue;
            }
            if ($count < 1) {
                $failed[] = ['bank' => $course, 'reason' => 'Question bank has no questions'];
                continue;
            }

            $show = min(40, $count);
            try {
                $exam = ExamConfig::create([
                    'exam_id' => 'exam_' . Str::random(8),
                    'title' => $course . ' ' . $suffix,
                    'subject' => $course,
                    'exam_type' => $kind,
                    'duration' => 60,
                    'total_questions' => $show,
                    'passing_score' => 60,
                    'question_bank_id' => $bank->id,
                    'created_by_user_id' => $request->user()->id,
                    'instructions' => null,
                    'active' => true,
                    'randomize_questions' => true,
                    'proctored' => $proctored,
                    'proctoring_settings' => $proctoring,
                    'is_global_practice' => false,
                ]);
                app(ExamCourseAssignmentService::class)->assignExamToCourseStudents($exam);
                $created[] = [
                    'id' => $exam->id,
                    'title' => $exam->title,
                    'questions' => $show,
                    'bank_questions' => $count,
                    'capped' => $show < 40,
                ];
                $existingBankIds[] = (int) $bank->id;
            } catch (\Throwable $e) {
                $failed[] = ['bank' => $course, 'reason' => 'Could not create the exam'];
            }
        }

        foreach ($wanted as $key => $label) {
            if (!isset($matched[$key])) {
                $failed[] = ['bank' => $label, 'reason' => 'No question bank for this course'];
            }
        }

        return response()->json([
            'kind' => $kind,
            'created' => $created,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);
    }

    public function show($id)
    {
        return response()->json(ExamConfig::with(['questionBank','students'])->findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $exam = ExamConfig::findOrFail($id);
        $oldBankId = (int) $exam->question_bank_id;

        $data = $request->validate([
            'title'               => 'sometimes|string',
            'subject'             => 'sometimes|string',
            'exam_type'           => 'sometimes|in:demo,main,practice',
            'duration'            => 'sometimes|integer',
            'total_questions'     => 'sometimes|integer',
            'passing_score'       => 'sometimes|integer',
            'question_bank_id'    => 'sometimes|exists:question_banks,id',
            'instructions'        => 'nullable|string',
            'active'              => 'sometimes|boolean',
            'randomize_questions' => 'sometimes|boolean',
            'proctored'           => 'sometimes|boolean',
            'proctoring_settings' => 'nullable|array',
            'proctoring_settings.camera'           => 'boolean',
            'proctoring_settings.microphone'       => 'boolean',
            'proctoring_settings.copy_paste_block' => 'boolean',
            'proctoring_settings.right_click_block'=> 'boolean',
            'proctoring_settings.tab_switch_limit' => 'integer|min:0|max:10',
            'proctoring_settings.fullscreen_enforce' => 'boolean',
            'proctoring_settings.devtools_detect'  => 'boolean',
            'proctoring_settings.text_select_block'=> 'boolean',
        ]);

        $oldType = strtolower((string) $exam->exam_type);
        $newBankId = (int) ($data['question_bank_id'] ?? $exam->question_bank_id);
        $newTotal  = (int) ($data['total_questions'] ?? $exam->total_questions);
        $bankChanged = $oldBankId !== $newBankId;

        if ($bankChanged || array_key_exists('total_questions', $data) || array_key_exists('question_bank_id', $data)) {
            $bankCount = Question::where('question_bank_id', $newBankId)->count();
            if ($bankCount < 1) {
                return response()->json(['message' => 'Selected question bank has no questions.'], 422);
            }
            if ($newTotal > $bankCount) {
                return response()->json([
                    'message' => "Questions to show ({$newTotal}) cannot exceed bank size ({$bankCount}).",
                ], 422);
            }
        }

        $exam->update($data);

        // Drop cached papers when bank or size changes so students never see a pooled set
        Cache::forget("exam_bank_qs:{$exam->id}");
        Cache::forget("exam_bank_qs:{$exam->id}:bank:{$oldBankId}");
        Cache::forget("exam_bank_qs:{$exam->id}:bank:{$newBankId}");

        // If bank changed, clear locked papers on live sessions so next load rebuilds from new bank only
        if ($bankChanged) {
            LiveExamSession::where('exam_config_id', $exam->id)->update(['question_ids' => null]);
        }

        $exam = $exam->fresh();
        $newType = strtolower((string) ($exam->exam_type ?? ''));

        // Switching to demo → unlimited attempts for all current assignments
        if ($exam && in_array($newType, ['demo', 'practice'], true) && $oldType !== $newType) {
            \Illuminate\Support\Facades\DB::table('exam_student')
                ->where('exam_config_id', $exam->id)
                ->update([
                    'max_attempts' => ExamCourseAssignmentService::DEMO_MAX_ATTEMPTS,
                ]);
        }

        // Re-sync course students when subject/active/type changes (or anytime after update while active)
        if ($exam && $exam->active) {
            app(ExamCourseAssignmentService::class)->assignExamToCourseStudents($exam);
        }

        return response()->json($exam->load('questionBank'));
    }

    public function destroy($id)
    {
        ExamConfig::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function toggleActive($id)
    {
        $exam = ExamConfig::findOrFail($id);
        $exam->update(['active' => !$exam->active]);
        if ($exam->active) {
            app(ExamCourseAssignmentService::class)->assignExamToCourseStudents($exam->fresh());
        }
        return response()->json(['active' => $exam->active]);
    }
}
