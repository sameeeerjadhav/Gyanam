<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LiveSessionService;
use Illuminate\Http\Request;

class LiveMonitorController extends Controller
{
    public function __construct(private LiveSessionService $liveSessions) {}

    /**
     * Get all currently active exam sessions (DB-backed, briefly cacheable).
     */
    public function active(Request $request)
    {
        $user = $request->user();

        try {
            $centre = $user->isAdmin() ? null : $user->centre_id;
            $cacheKey = 'live_active:' . ($centre ?? 'all');

            $sessions = cache()->remember($cacheKey, 3, function () use ($centre) {
                return $this->liveSessions->activeSessions($centre)->values()->all();
            });

            return response()->json($sessions);
        } catch (\Throwable $e) {
            \Log::warning('LiveMonitor: error reading sessions: ' . $e->getMessage());
            return response()->json([]);
        }
    }

    /**
     * Extend a live student's exam time (admin or scoped staff only).
     */
    public function extendTime(Request $request, $studentId, $examId)
    {
        $user = $request->user();
        $data = $request->validate(['extra_minutes' => 'required|integer|min:1|max:60']);

        // Prefer exam_config_id (numeric); fall back to exam_code string
        $session = is_numeric($examId)
            ? $this->liveSessions->find((int) $studentId, (int) $examId)
            : \App\Models\LiveExamSession::where('student_id', $studentId)
                ->where('exam_code', $examId)
                ->first();

        if (!$session) {
            abort(404, 'Active session not found. The student may have already submitted or disconnected.');
        }

        if (!$user->isAdmin() && ($session->centre_name ?? '') !== $user->centre_id) {
            abort(403, 'You can only extend time for students in your centre.');
        }

        $session = $this->liveSessions->extend((int) $session->student_id, (int) $session->exam_config_id, (int) $data['extra_minutes']);

        cache()->forget('live_active:all');
        if ($session->centre_name) {
            cache()->forget('live_active:' . $session->centre_name);
        }

        return response()->json([
            'message'     => "Added {$data['extra_minutes']} minutes successfully.",
            'total_extra' => $session->extra_minutes,
        ]);
    }
}
