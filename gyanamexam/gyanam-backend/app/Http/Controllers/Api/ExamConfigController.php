<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamConfig;
use App\Models\Student;
use Illuminate\Http\Request;
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
        $exam->update($request->validate([
            'title'               => 'sometimes|string',
            'subject'             => 'sometimes|string',
            'duration'            => 'sometimes|integer',
            'total_questions'     => 'sometimes|integer',
            'passing_score'       => 'sometimes|integer',
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
        ]));
        return response()->json($exam);
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
