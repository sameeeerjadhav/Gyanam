<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamConfig;
use App\Models\LiveExamSession;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ExamConfigController extends Controller
{
    public function index(Request $request)
    {
        $exams = ExamConfig::with(['questionBank', 'creator'])->get();
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
        return response()->json($exam->load('questionBank'), 201);
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

        return response()->json($exam->fresh()->load('questionBank'));
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
        return response()->json(['active' => $exam->active]);
    }

    /**
     * Get the single global practice exam (visible to all students).
     */
    public function getGlobalPractice(Request $request)
    {
        if (!$request->user()->isAdmin()) {
            abort(403, 'Admin only');
        }

        $exam = ExamConfig::with(['questionBank' => fn ($q) => $q->withCount('questions')])
            ->where('is_global_practice', true)
            ->orderByDesc('updated_at')
            ->first();

        return response()->json([
            'exam' => $exam,
            'configured' => (bool) $exam,
        ]);
    }

    /**
     * Create or update the global practice exam (one bank, all students).
     */
    public function saveGlobalPractice(Request $request)
    {
        if (!$request->user()->isAdmin()) {
            abort(403, 'Admin only');
        }

        $data = $request->validate([
            'title'            => 'required|string|max:255',
            'question_bank_id' => 'required|exists:question_banks,id',
            'duration'         => 'required|integer|min:1|max:300',
            'total_questions'  => 'required|integer|min:1|max:200',
            'passing_score'    => 'required|integer|min:1|max:100',
            'instructions'     => 'nullable|string|max:5000',
            'active'           => 'boolean',
            'randomize_questions' => 'boolean',
            'proctored'        => 'boolean',
        ]);

        $bank = QuestionBank::withCount('questions')->findOrFail($data['question_bank_id']);
        $bankCount = (int) ($bank->questions_count ?? 0);
        if ($bankCount < 1) {
            return response()->json(['message' => 'Selected question bank has no questions.'], 422);
        }
        if ((int) $data['total_questions'] > $bankCount) {
            return response()->json([
                'message' => "Questions to show ({$data['total_questions']}) cannot exceed bank size ({$bankCount}).",
            ], 422);
        }

        $exam = ExamConfig::where('is_global_practice', true)->orderByDesc('id')->first();
        $payload = [
            'title'               => $data['title'],
            'subject'             => 'All Courses — Practice Experience',
            'exam_type'           => 'demo',
            'duration'            => (int) $data['duration'],
            'total_questions'     => (int) $data['total_questions'],
            'passing_score'       => (int) $data['passing_score'],
            'question_bank_id'    => (int) $data['question_bank_id'],
            'instructions'        => $data['instructions'] ?? "Welcome to MCCE Demo exam portal.\n\n1) All questions are MCQ type.\n\n2) Total questions : 20, all questions are mandatory.\n\n3) Each question is of 1 marks. There is no penalty for incorrect answers.\n\n4) Exam time : 30 min.\n\n5) Your certification grade & percentage depend on this given examination.\n\n6) Once exam is finished, there is no option to make changes in answers. So be careful while answering to questions.\n\n7) Do not try to do any other activity on the computer other than attempting exam. ANY OTHER ACTIVITY DURING THE EXAM WILL TERMINATE THE EXAM AND THERE IS NO WAY TO GAIN ACCESS TO EXAM OTHER THAN RE-APPEAR.\n\n8) If you fail in exam OR terminated exam due to mishandling, you can reappear by paying (re-examination fees).\n\n9) Do not use mobile phones or any other electronic device during EXAM.\n\n10) Request provisional certificate to the centre head before leaving exam centre.\n\nALL THE BEST !!!",
            'active'              => array_key_exists('active', $data) ? (bool) $data['active'] : true,
            'randomize_questions' => array_key_exists('randomize_questions', $data) ? (bool) $data['randomize_questions'] : true,
            'proctored'           => array_key_exists('proctored', $data) ? (bool) $data['proctored'] : false,
            'proctoring_settings' => null,
            'is_global_practice'  => true,
        ];

        if ($exam) {
            $oldBankId = (int) $exam->question_bank_id;
            $exam->update($payload);
            Cache::forget("exam_bank_qs:{$exam->id}");
            Cache::forget("exam_bank_qs:{$exam->id}:bank:{$oldBankId}");
            Cache::forget("exam_bank_qs:{$exam->id}:bank:{$exam->question_bank_id}");
            if ($oldBankId !== (int) $exam->question_bank_id) {
                LiveExamSession::where('exam_config_id', $exam->id)->update(['question_ids' => null]);
            }
        } else {
            $payload['exam_id'] = 'exam_global_practice';
            $payload['created_by_user_id'] = $request->user()->id;
            // Avoid unique collision if a leftover row exists with that exam_id
            if (ExamConfig::where('exam_id', 'exam_global_practice')->exists()) {
                $payload['exam_id'] = 'exam_global_practice_' . Str::lower(Str::random(4));
            }
            $exam = ExamConfig::create($payload);
        }

        ExamConfig::clearOtherGlobalPracticeFlags((int) $exam->id);

        return response()->json([
            'exam' => $exam->fresh()->load(['questionBank' => fn ($q) => $q->withCount('questions')]),
            'message' => 'Global practice exam saved. It is visible to all students on their dashboard.',
        ]);
    }
}
