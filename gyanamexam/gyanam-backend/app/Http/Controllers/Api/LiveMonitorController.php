<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

class LiveMonitorController extends Controller
{
    /**
     * Get all currently active exam sessions.
     * Uses file cache (no Redis dependency), falls back to empty array gracefully.
     */
    public function active(Request $request)
    {
        $user     = $request->user();
        $sessions = [];

        try {
            // Scan known live session keys stored by StudentExamController
            // Since we can't enumerate file cache keys, we store an index
            $liveIndex = Cache::get('live_session_index', []);

            foreach ($liveIndex as $key) {
                $session = Cache::get($key);
                if (!$session) continue;

                // Check staleness: last seen within 10 minutes = still live
                $lastSeen = isset($session['lastSeen'])
                    ? \Carbon\Carbon::parse($session['lastSeen'])
                    : null;

                if (!$lastSeen || now()->diffInMinutes($lastSeen) > 10) continue;

                // Scope by centre for non-admin users
                if (!$user->isAdmin() && ($session['centreName'] ?? '') !== $user->centre_id) {
                    continue;
                }

                $sessions[] = $session;
            }
        } catch (\Throwable $e) {
            \Log::warning('LiveMonitor: error reading sessions: ' . $e->getMessage());
        }

        return response()->json($sessions);
    }

    /**
     * Extend a live student's exam time (admin or scoped staff only).
     */
    public function extendTime(Request $request, $studentId, $examId)
    {
        $user = $request->user();
        $data = $request->validate(['extra_minutes' => 'required|integer|min:1|max:60']);

        $key     = "live:{$studentId}:{$examId}";
        $session = Cache::get($key);

        if (!$session) {
            abort(404, 'Active session not found. The student may have already submitted or disconnected.');
        }

        // Scope check for non-admin
        if (!$user->isAdmin() && ($session['centreName'] ?? '') !== $user->centre_id) {
            abort(403, 'You can only extend time for students in your centre.');
        }

        $session['extraMinutes'] = ($session['extraMinutes'] ?? 0) + $data['extra_minutes'];
        Cache::put($key, $session, 7200);

        return response()->json([
            'message'     => "Added {$data['extra_minutes']} minutes successfully.",
            'total_extra' => $session['extraMinutes'],
        ]);
    }
}
