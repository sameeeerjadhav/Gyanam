<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\ExamConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StudentController extends Controller
{
    public function index(Request $request)
    {
        $user     = $request->user();
        $students = Student::with('exams')
            ->when(!$user->isAdmin(), fn($q) => $q->where('centre_name', $user->centre_id))
            ->get();

        return response()->json($students);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'identifier'  => 'required|string|unique:students,identifier',
            'name'        => 'required|string',
            'centre_name' => 'required|string',
            'exam_slot'   => 'nullable|string',
            'time_window' => 'nullable|string',
            'exam_ids'    => 'nullable|array',
            'exam_ids.*'  => 'exists:exam_configs,id',
            'exams'       => 'nullable|array',
            'exams.*'     => 'exists:exam_configs,id',
        ]);

        $student = Student::create([
            'identifier'  => $data['identifier'],
            'name'        => $data['name'],
            'centre_name' => $data['centre_name'],
            'exam_slot'   => strtoupper($data['exam_slot']   ?? 'SLOT1'),
            'time_window' => strtoupper($data['time_window']  ?? 'MORNING'),
            'password'    => Hash::make('password'),
        ]);

        $examIds = $data['exam_ids'] ?? $data['exams'] ?? [];
        if (!empty($examIds)) {
            $student->exams()->sync($examIds);
        }

        return response()->json($student->load('exams'), 201);
    }

    public function show($id)
    {
        return response()->json(Student::with(['exams','submissions'])->findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $student = Student::findOrFail($id);
        $student->update($request->validate([
            'name'        => 'sometimes|string',
            'centre_name' => 'sometimes|string',
            'exam_slot'   => 'sometimes|in:SLOT1,SLOT2,SLOT3',
            'time_window' => 'sometimes|in:MORNING,AFTERNOON,EVENING',
        ]));

        // Accept either 'exam_ids' or 'exams' for flexibility
        $examIds = $request->input('exam_ids', $request->input('exams'));
        if ($examIds !== null) {
            $student->exams()->sync($examIds);
        }

        return response()->json($student->load('exams'));
    }

    public function destroy($id)
    {
        Student::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted']);
    }

    public function importCsv(Request $request)
    {
        $request->validate(['csv' => 'required|string']);

        $lines   = array_filter(explode("\n", trim($request->csv)));
        $added   = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($lines as $i => $line) {
            $cols = str_getcsv($line);
            // identifier, name, centre_name, exam_slot, time_window
            if (count($cols) < 5) { $skipped++; continue; }

            [$identifier, $name, $centre, $slot, $window] = $cols;
            $identifier = trim($identifier);

            if (Student::where('identifier', $identifier)->exists()) {
                $errors[] = "Row " . ($i+1) . ": $identifier already exists";
                $skipped++;
                continue;
            }

            Student::create([
                'identifier'  => $identifier,
                'name'        => trim($name),
                'centre_name' => trim($centre),
                'exam_slot'   => strtoupper(trim($slot)),
                'time_window' => strtoupper(trim($window)),
                'password'    => Hash::make('password'),
            ]);
            $added++;
        }

        return response()->json(['added' => $added, 'skipped' => $skipped, 'errors' => $errors]);
    }

    public function history($id)
    {
        $student = Student::with('submissions.exam')->findOrFail($id);
        return response()->json($student->submissions);
    }

    /**
     * Bulk update multiple students at once.
     * POST /students/bulk-update
     *
     * Body: {
     *   student_ids: [1, 2, 3],
     *   centre_name: "New Centre"  (optional),
     *   exam_slot:   "SLOT2"       (optional),
     *   time_window: "AFTERNOON"   (optional),
     *   name:        "..."         (optional — rarely used in bulk)
     * }
     */
    public function bulkUpdate(Request $request)
    {
        $data = $request->validate([
            'student_ids'   => 'required|array|min:1',
            'student_ids.*' => 'exists:students,id',
            'name'          => 'nullable|string',
            'centre_name'   => 'nullable|string',
            'exam_slot'     => 'nullable|in:SLOT1,SLOT2,SLOT3',
            'time_window'   => 'nullable|in:MORNING,AFTERNOON,EVENING',
        ]);

        $ids    = $data['student_ids'];
        $fields = collect($data)->except('student_ids')->filter(fn($v) => $v !== null && $v !== '')->toArray();

        if (empty($fields)) {
            return response()->json(['message' => 'No fields to update.', 'updated' => 0]);
        }

        $updated = Student::whereIn('id', $ids)->update($fields);

        return response()->json([
            'message' => $updated . ' student(s) updated successfully.',
            'updated' => $updated,
            'fields'  => array_keys($fields),
        ]);
    }
}
