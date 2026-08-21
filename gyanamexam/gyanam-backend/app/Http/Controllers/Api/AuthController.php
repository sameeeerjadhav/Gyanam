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
            'user'    => [
                'id'          => $student->id,
                'identifier'  => $student->identifier,
                'name'        => $student->name,
                'centre_name' => $student->centre_name,
                'exam_slot'   => $student->exam_slot,
                'role'        => 'student',
            ],
        ]);
    }

    /**
     * Logout portal user or student
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out']);
    }
}
