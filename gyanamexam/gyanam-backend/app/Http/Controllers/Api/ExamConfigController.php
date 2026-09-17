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
}
