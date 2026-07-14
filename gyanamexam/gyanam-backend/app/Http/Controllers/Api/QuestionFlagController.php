<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuestionFlag;
use App\Models\Submission;
use Illuminate\Http\Request;

class QuestionFlagController extends Controller
{
    /**
     * Student submits a challenge for a question in their submission.
     * Route: POST /api/student/flags
     */
    public function store(Request $request)
    {
        $student = $request->user();
        $data = $request->validate([
            'submission_id' => 'required|integer|exists:submissions,id',
            'question_id'   => 'required|integer|exists:questions,id',
            'reason'        => 'required|string|max:500',
        ]);

        // Verify this submission belongs to the student
        $submission = Submission::where('id', $data['submission_id'])
            ->where('student_id', $student->id)
            ->firstOrFail();

        // Prevent duplicate flags for same Q in same submission
        $exists = QuestionFlag::where('submission_id', $data['submission_id'])
            ->where('question_id', $data['question_id'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'You have already flagged this question.'], 422);
        }

        $flag = QuestionFlag::create([
            'submission_id' => $data['submission_id'],
            'question_id'   => $data['question_id'],
            'student_id'    => $student->id,
            'centre_name'   => $student->centre_name,
            'reason'        => $data['reason'],
            'status'        => 'pending',
        ]);

        return response()->json(['message' => 'Flag submitted successfully.', 'flag' => $flag], 201);
    }

    /**
     * Admin / ATC lists all flags for their centre (scoped).
     * Route: GET /api/flags
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $flags = QuestionFlag::with(['student', 'question', 'submission'])
            ->forCentre($user->centre_id)
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->get()
            ->map(fn($f) => [
                'id'            => $f->id,
                'student_name'  => $f->student?->name,
                'centre'        => $f->centre_name,
                'question_text' => $f->question?->text,
                'exam_title'    => $f->submission?->exam_title,
                'reason'        => $f->reason,
                'status'        => $f->status,
                'admin_note'    => $f->admin_note,
                'created_at'    => $f->created_at,
            ]);

        return response()->json($flags);
    }

    /**
     * Admin marks a flag as reviewed or dismissed with a note.
     * Route: PATCH /api/flags/{id}
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $data = $request->validate([
            'status'     => 'required|in:reviewed,dismissed',
            'admin_note' => 'nullable|string|max:1000',
        ]);

        $flag = QuestionFlag::forCentre($user->centre_id)->findOrFail($id);
        $flag->update([
            'status'     => $data['status'],
            'admin_note' => $data['admin_note'] ?? $flag->admin_note,
        ]);

        return response()->json(['message' => 'Flag updated.', 'flag' => $flag]);
    }
}
