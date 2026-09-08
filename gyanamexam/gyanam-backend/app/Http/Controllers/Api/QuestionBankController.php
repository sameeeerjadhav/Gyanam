<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuestionBank;
use App\Models\QuestionBankAssignment;
use App\Models\Question;
use App\Support\PortalAtcCentres;
use Illuminate\Http\Request;

class QuestionBankController extends Controller
{
    /**
     * Banks assigned to a specific ATC centre (for Gyanam India ATC portal).
     * Admin service token must pass ?centre_id=ATC_CODE.
     */
    public function forCentre(Request $request)
    {
        $centreId = $this->resolveCentreId($request);

        $banks = QuestionBank::withCount('questions')
            ->whereHas('assignments', fn ($a) => $a->where('centre_id', $centreId))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($b) => [
                'id'              => $b->id,
                'title'           => $b->title,
                'subject'         => $b->subject,
                'questions_count' => (int) ($b->questions_count ?? 0),
                'updated_at'      => $b->updated_at,
                'created_at'      => $b->created_at,
            ]);

        return response()->json([
            'centre_id' => $centreId,
            'banks'     => $banks,
            'count'     => $banks->count(),
        ]);
    }

    /**
     * Export bank questions for PDF generation (ATC must be assigned).
     * Omit answer keys with ?include_answers=0
     */
    public function exportForCentre(Request $request, $id)
    {
        $centreId = $this->resolveCentreId($request);
        $includeAnswers = $request->boolean('include_answers', true);

        $bank = QuestionBank::with(['questions' => fn ($q) => $q->orderBy('order')->orderBy('id')])
            ->whereHas('assignments', fn ($a) => $a->where('centre_id', $centreId))
            ->findOrFail($id);

        $questions = $bank->questions->values()->map(function ($q, $idx) use ($includeAnswers) {
            $row = [
                'number'  => $idx + 1,
                'text'    => $q->text,
                'options' => $q->options,
            ];
            if ($includeAnswers) {
                $row['correct_answer'] = $q->correct_answer;
            }
            return $row;
        });

        return response()->json([
            'id'               => $bank->id,
            'title'            => $bank->title,
            'subject'          => $bank->subject,
            'centre_id'        => $centreId,
            'include_answers'  => $includeAnswers,
            'questions_count'  => $questions->count(),
            'questions'        => $questions,
        ]);
    }

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
     * Admin assigns bank to centres.
     *
     * Modes:
     * - specific (default): use centres[]
     * - all: every synced Active ATC from portal_atcs.json
     * - all_of_type: every synced ATC matching centre_type (Abacus / Vedic Maths / IT / combo)
     */
    public function assign(Request $request, $id)
    {
        $this->authorizeAdmin($request);
        $bank = QuestionBank::findOrFail($id);
        $data = $request->validate([
            'mode'          => 'nullable|in:specific,all,all_of_type',
            'centre_type'   => 'nullable|string|max:64',
            'centres'       => 'nullable|array',
            'centres.*'     => 'string',
        ]);

        $mode = $data['mode'] ?? 'specific';
        if ($mode === 'all') {
            $centres = PortalAtcCentres::codes();
        } elseif ($mode === 'all_of_type') {
            $type = trim((string) ($data['centre_type'] ?? ''));
            if ($type === '') {
                return response()->json(['message' => 'centre_type is required for all_of_type'], 422);
            }
            $centres = PortalAtcCentres::codesMatchingType($type);
        } else {
            $centres = array_values(array_unique(array_filter(
                array_map(static fn ($c) => trim((string) $c), $data['centres'] ?? []),
                static fn ($c) => $c !== ''
            )));
        }

        $bank->assignments()->delete();
        foreach ($centres as $c) {
            QuestionBankAssignment::create(['question_bank_id' => $bank->id, 'centre_id' => $c]);
        }

        return response()->json([
            'mode'        => $mode,
            'centre_type' => $data['centre_type'] ?? null,
            'assigned_to' => $centres,
            'count'       => count($centres),
            'message'     => 'Assigned successfully',
        ]);
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

    /**
     * Resolve ATC code for centre-scoped exports.
     * Admin (India service token) must pass centre_id; ATC users default to their own centre.
     */
    private function resolveCentreId(Request $request): string
    {
        $user = $request->user();
        $centreId = trim((string) $request->query('centre_id', $request->input('centre_id', '')));

        if ($centreId === '' && !$user->isAdmin() && !empty($user->centre_id)) {
            $centreId = trim((string) $user->centre_id);
        }

        if ($centreId === '') {
            abort(422, 'centre_id is required');
        }

        if (!$user->isAdmin() && trim((string) $user->centre_id) !== $centreId) {
            abort(403, 'Not allowed for this centre');
        }

        return $centreId;
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
