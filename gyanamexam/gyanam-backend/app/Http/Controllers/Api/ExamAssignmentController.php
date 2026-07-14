<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamConfig;
use App\Models\Student;
use Illuminate\Http\Request;

class ExamAssignmentController extends Controller
{
    /**
     * List all students in this admin's scope with their exam assignments.
     */
    public function students(Request $request)
    {
        $user = $request->user();

        $students = Student::query()
            ->when($user->centre_id, fn($q) => $q->where('centre_name', $user->centre_id))
            ->with(['exams' => function ($q) {
                $q->withPivot(['max_attempts', 'assigned_at', 'assigned_by_user_id'])
                  ->select('exam_configs.id', 'exam_configs.exam_id', 'exam_configs.title',
                           'exam_configs.subject', 'exam_configs.active');
            }])
            ->get(['id', 'identifier', 'name', 'centre_name', 'exam_slot'])
            ->map(function ($student) {
                $assignments = $student->exams->map(function ($exam) use ($student) {
                    $used = $student->submissions()
                        ->where('exam_config_id', $exam->id)
                        ->count();
                    return [
                        'exam_id'        => $exam->id,
                        'exam_code'      => $exam->exam_id,
                        'title'          => $exam->title,
                        'subject'        => $exam->subject,
                        'active'         => $exam->active,
                        'max_attempts'   => $exam->pivot->max_attempts,
                        'used_attempts'  => $used,
                        'remaining'      => max(0, $exam->pivot->max_attempts - $used),
                        'assigned_at'    => $exam->pivot->assigned_at,
                    ];
                });

                return [
                    'id'          => $student->id,
                    'identifier'  => $student->identifier,
                    'name'        => $student->name,
                    'centre_name' => $student->centre_name,
                    'exam_slot'   => $student->exam_slot,
                    'assignments' => $assignments,
                ];
            });

        return response()->json($students);
    }

    /**
     * Assign an exam to a student (or update existing assignment).
     */
    public function assign(Request $request)
    {
        $data = $request->validate([
            'student_id'   => 'required|exists:students,id',
            'exam_id'      => 'required|exists:exam_configs,id',
            'max_attempts' => 'integer|min:1|max:10',
        ]);

        $user    = $request->user();
        $student = Student::findOrFail($data['student_id']);

        // Scope check — ATC/DLC can only assign to their own centre's students
        if ($user->centre_id && $student->centre_name !== $user->centre_id) {
            abort(403, 'You can only assign exams to students in your centre.');
        }

        // Upsert the pivot row
        $student->exams()->syncWithoutDetaching([
            $data['exam_id'] => [
                'max_attempts'        => $data['max_attempts'] ?? 1,
                'assigned_by_user_id' => $user->id,
                'assigned_at'         => now(),
            ],
        ]);

        return response()->json(['message' => 'Exam assigned successfully.']);
    }

    /**
     * Bulk assign an exam to multiple students.
     */
    public function bulkAssign(Request $request)
    {
        $data = $request->validate([
            'student_ids'  => 'required|array',
            'student_ids.*'=> 'exists:students,id',
            'exam_id'      => 'required|exists:exam_configs,id',
            'max_attempts' => 'integer|min:1|max:10',
        ]);

        $user = $request->user();
        $assignedCount = 0;

        foreach ($data['student_ids'] as $studentId) {
            $student = Student::find($studentId);
            if (!$student) continue;

            // Scope check per student
            if ($user->centre_id && $student->centre_name !== $user->centre_id) {
                continue;
            }

            $student->exams()->syncWithoutDetaching([
                $data['exam_id'] => [
                    'max_attempts'        => $data['max_attempts'] ?? 1,
                    'assigned_by_user_id' => $user->id,
                    'assigned_at'         => now(),
                ],
            ]);
            $assignedCount++;
        }

        return response()->json([
            'message' => "Successfully assigned exam to {$assignedCount} students.",
            'count'   => $assignedCount
        ]);
    }

    /**
     * Update max_attempts for an existing assignment.
     */
    public function updateAttempts(Request $request, $studentId, $examId)
    {
        $data = $request->validate([
            'max_attempts' => 'required|integer|min:1|max:10',
        ]);

        $user    = $request->user();
        $student = Student::findOrFail($studentId);

        if ($user->centre_id && $student->centre_name !== $user->centre_id) {
            abort(403, 'You can only modify assignments in your centre.');
        }

        $student->exams()->updateExistingPivot($examId, [
            'max_attempts' => $data['max_attempts'],
        ]);

        return response()->json(['message' => 'Attempt limit updated.']);
    }

    /**
     * Remove an exam assignment from a student.
     */
    public function unassign(Request $request, $studentId, $examId)
    {
        $user    = $request->user();
        $student = Student::findOrFail($studentId);

        if ($user->centre_id && $student->centre_name !== $user->centre_id) {
            abort(403, 'You can only remove assignments in your centre.');
        }

        $student->exams()->detach($examId);

        return response()->json(['message' => 'Assignment removed.']);
    }

    /**
     * Get all available exam configs (for the assign dropdown).
     */
    public function availableExams(Request $request)
    {
        $user = $request->user();

        $exams = ExamConfig::query()
            ->where('active', true)
            ->when($user->centre_id, function ($q) use ($user) {
                $q->whereHas('questionBank.assignments', fn($a) => $a->where('centre_id', $user->centre_id));
            })
            ->get(['id', 'exam_id', 'title', 'subject', 'total_questions', 'duration', 'passing_score']);

        return response()->json($exams);
    }
}
