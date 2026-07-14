<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuestionBank;
use App\Models\QuestionBankAssignment;
use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class QuestionBankController extends Controller
{
    public function index(Request $request)
    {
        $user  = $request->user();
        $banks = QuestionBank::with(['assignments', 'creator'])
            ->withCount('questions')
            ->visibleTo($user->centre_id, $user->username)
            ->get()
            ->map(fn($b) => $this->format($b));

        return response()->json($banks);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'       => 'required|string|max:255',
            'subject'     => 'required|string|max:255',
            'assigned_to' => 'nullable|array',
            'assigned_to.*' => 'string',
        ]);

        $user = $request->user();
        $bank = QuestionBank::create([
            'title'              => $data['title'],
            'subject'            => $data['subject'],
            'created_by_user_id' => $user->id,
        ]);

        // ATC/DLC: auto-assign to their own centre
        $centres = $user->isAdmin()
            ? ($data['assigned_to'] ?? [])
            : [$user->centre_id];

        foreach ($centres as $c) {
            QuestionBankAssignment::firstOrCreate([
                'question_bank_id' => $bank->id,
                'centre_id'        => $c,
            ]);
        }

        return response()->json($this->format($bank->fresh(['questions','assignments','creator'])), 201);
    }

    public function show(Request $request, $id)
    {
        $bank = $this->findVisible($request, $id);
        return response()->json($this->format($bank->load(['questions','assignments','creator'])));
    }

    public function update(Request $request, $id)
    {
        $bank = $this->findVisible($request, $id);
        $data = $request->validate([
            'title'   => 'sometimes|string|max:255',
            'subject' => 'sometimes|string|max:255',
        ]);
        $bank->update($data);
        return response()->json($this->format($bank->fresh(['questions','assignments','creator'])));
    }

    public function destroy(Request $request, $id)
    {
        $bank = $this->findVisible($request, $id);
        $bank->delete();
        return response()->json(['message' => 'Deleted']);
    }

    /**
     * Admin assigns bank to centres
     */
    public function assign(Request $request, $id)
    {
        $this->authorizeAdmin($request);
        $bank    = QuestionBank::findOrFail($id);
        $centres = $request->validate(['centres' => 'required|array', 'centres.*' => 'string'])['centres'];

        $bank->assignments()->delete();
        foreach ($centres as $c) {
            QuestionBankAssignment::create(['question_bank_id' => $bank->id, 'centre_id' => $c]);
        }

        return response()->json(['assigned_to' => $centres, 'message' => 'Assigned successfully']);
    }

    // ─── Questions ───────────────────────────────────────────────────────────

    public function storeQuestion(Request $request, $bankId)
    {
        $bank = $this->findVisible($request, $bankId);
        $data = $request->validate([
            'text'           => 'required|string',
            'options'        => 'required|array|min:2',
            'options.*.id'   => 'required|string',
            'options.*.text' => 'required|string',
            'correct_answer' => 'required|string',
        ]);

        $q = $bank->questions()->create([
            'text'           => $data['text'],
            'options'        => $data['options'],
            'correct_answer' => $data['correct_answer'],
            'order'          => $bank->questions()->count(),
        ]);

        return response()->json($q, 201);
    }

    public function updateQuestion(Request $request, $bankId, $questionId)
    {
        $this->findVisible($request, $bankId);
        $q    = Question::where('question_bank_id', $bankId)->findOrFail($questionId);
        $data = $request->validate([
            'text'           => 'sometimes|string',
            'options'        => 'sometimes|array',
            'correct_answer' => 'sometimes|string',
        ]);
        $q->update($data);
        return response()->json($q);
    }

    public function destroyQuestion(Request $request, $bankId, $questionId)
    {
        $this->findVisible($request, $bankId);
        Question::where('question_bank_id', $bankId)->findOrFail($questionId)->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function questions(Request $request, $id)
    {
        $bank = $this->findVisible($request, $id);
        return response()->json($bank->questions()->orderBy('order')->get());
    }

    public function importQuestions(Request $request, $bankId)
    {
        $bank = $this->findVisible($request, $bankId);
        $request->validate(['csv' => 'required|string']);

        $lines  = array_filter(explode("\n", trim($request->csv)));
        $added  = 0;
        $errors = [];

        foreach ($lines as $i => $line) {
            $cols = str_getcsv($line);
            // Expected: Question,OptionA,OptionB,OptionC,OptionD,CorrectLetter(A/B/C/D)
            if (count($cols) < 6) { $errors[] = "Row " . ($i+1) . ": need 6 columns"; continue; }

            [$text, $a, $b, $c, $d, $correct] = $cols;
            $correctId = strtolower(trim($correct)); // a/b/c/d
            if (!in_array($correctId, ['a','b','c','d'])) { $errors[] = "Row " . ($i+1) . ": correct must be a/b/c/d"; continue; }

            $bank->questions()->create([
                'text'           => trim($text),
                'options'        => [
                    ['id'=>'a','text'=>trim($a)],
                    ['id'=>'b','text'=>trim($b)],
                    ['id'=>'c','text'=>trim($c)],
                    ['id'=>'d','text'=>trim($d)],
                ],
                'correct_answer' => $correctId,
                'order'          => $bank->questions()->count(),
            ]);
            $added++;
        }

        return response()->json(['added' => $added, 'errors' => $errors]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function findVisible(Request $request, $id): QuestionBank
    {
        $user = $request->user();
        return QuestionBank::visibleTo($user->centre_id, $user->username)->findOrFail($id);
    }

    private function authorizeAdmin(Request $request): void
    {
        if (!$request->user()->isAdmin()) abort(403, 'Admin only');
    }

    private function format(QuestionBank $b): array
    {
        return [
            'id'              => $b->id,
            'title'           => $b->title,
            'subject'         => $b->subject,
            'created_by'      => $b->creator?->username,
            'assigned_to'     => $b->assignments->pluck('centre_id'),
            'questions'       => $b->relationLoaded('questions') ? $b->questions : [],
            'questions_count' => $b->questions_count ?? $b->questions()->count(),
            'created_at'      => $b->created_at,
        ];
    }
}
