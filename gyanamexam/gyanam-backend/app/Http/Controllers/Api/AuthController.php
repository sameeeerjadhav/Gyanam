<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Portal login: admin / atc / dlc
     */
    public function portalLogin(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $request->username)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['Invalid credentials.'],
            ]);
        }

        $token = $user->createToken('portal-token', ['role:' . $user->role])->plainTextToken;

        return response()->json([
            'token'     => $token,
            'user'      => [
                'id'        => $user->id,
                'username'  => $user->username,
                'name'      => $user->name,
                'role'      => $user->role,
                'centre_id' => $user->centre_id,
            ],
        ]);
    }

    /**
     * Student login: identifier + password
     * The student's identifier is their registration ID (e.g. GYANAM1, GYANAM2)
     * and the default password is "password".
     */
    public function studentLogin(Request $request)
    {
        $request->validate([
            'identifier' => 'required|string',
            'password'   => 'required|string',
        ]);

        $student = Student::where('identifier', $request->identifier)->first();

        if (!$student) {
            throw ValidationException::withMessages([
                'identifier' => ['Student not found.'],
            ]);
        }

        // Verify password
        // Handle both bcrypt-hashed and legacy plain-text passwords
        // Laravel 12 throws RuntimeException if hash is not bcrypt, so we catch it
        $passwordOk = false;
        if ($student->password) {
            // Try bcrypt verification first (wrapped in try/catch for Laravel 12)
            try {
                $passwordOk = Hash::check($request->password, $student->password);
            } catch (\RuntimeException $e) {
                // Hash is not bcrypt — fall back to plain-text comparison
                $passwordOk = false;
            }

            // If bcrypt check failed, try plain-text comparison
            if (!$passwordOk && $request->password === $student->password) {
                $passwordOk = true;
                // Auto-rehash so next login uses bcrypt
                $student->password = Hash::make($request->password);
                $student->save();
            }
        }

        if (!$passwordOk) {
            throw ValidationException::withMessages([
                'password' => ['Invalid password.'],
            ]);
        }

        $token = $student->createToken('student-token')->plainTextToken;

        return response()->json([
            'token'   => $token,
            'user'    => $this->studentProfilePayload($student),
        ]);
    }

    /**
     * Current authenticated student profile (fresh from DB) with exam summary.
     */
    public function studentMe(Request $request)
    {
        $student = $request->user();
        $payload = $this->studentProfilePayload($student);

        $payload['registered_at'] = $student->created_at ? $student->created_at->toIso8601String() : null;
        $payload['profile_updated_at'] = $student->updated_at ? $student->updated_at->toIso8601String() : null;

        $assigned = $student->exams()
            ->where('active', true)
            ->orderBy('title')
            ->get([
                'exam_configs.id',
                'exam_configs.title',
                'exam_configs.subject',
                'exam_configs.exam_type',
                'exam_configs.duration',
                'exam_configs.total_questions',
                'exam_configs.passing_score',
                'exam_configs.proctored',
            ])
            ->filter(fn ($e) => \App\Services\ExamCourseAssignmentService::coursesMatch($student->course, $e->subject))
            ->values();

        $payload['assigned_exams'] = $assigned->map(fn ($e) => [
            'id'              => $e->id,
            'title'           => $e->title,
            'subject'         => $e->subject,
            'exam_type'       => $e->exam_type,
            'duration'        => $e->duration,
            'total_questions' => $e->total_questions,
            'passing_score'   => $e->passing_score,
            'proctored'       => (bool) $e->proctored,
        ])->values()->all();
        $payload['assigned_exams_count'] = $assigned->count();

        $subs = $student->submissions()
            ->orderByDesc('submitted_at')
            ->get([
                'id', 'exam_title', 'score', 'result', 'correct_answers',
                'total_questions', 'duration_taken', 'submitted_at',
            ]);

        $payload['attempts_count'] = $subs->count();
        $payload['passed_count']   = $subs->where('result', 'pass')->count();
        $payload['failed_count']   = $subs->where('result', 'fail')->count();
        $payload['best_score']     = $subs->max('score');
        $payload['latest_attempt'] = $subs->first() ? [
            'exam_title'       => $subs->first()->exam_title,
            'score'            => $subs->first()->score,
            'result'           => $subs->first()->result,
            'correct_answers'  => $subs->first()->correct_answers,
            'total_questions'  => $subs->first()->total_questions,
            'duration_taken'   => $subs->first()->duration_taken,
            'submitted_at'     => $subs->first()->submitted_at
                ? $subs->first()->submitted_at->toIso8601String()
                : null,
        ] : null;

        return response()->json($payload);
    }

    /**
     * Logout portal user or student
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }

    private function studentProfilePayload($student): array
    {
        return [
            'id'          => $student->id,
            'identifier'  => $student->identifier,
            'name'        => $student->name,
            'centre_name' => $student->centre_name,
            'exam_slot'   => $student->exam_slot,
            'time_window' => $student->time_window,
            'photo_url'   => $student->photo_url,
            'course'      => $student->course,
            'role'        => 'student',
        ];
    }
}
