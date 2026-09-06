<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LiveExamSession;
use App\Services\LiveSessionService;
use App\Services\ProctorMediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class LiveMonitorController extends Controller
{
    public function __construct(
        private LiveSessionService $liveSessions,
        private ProctorMediaService $proctorMedia,
    ) {}

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

        $session = is_numeric($examId)
            ? $this->liveSessions->find((int) $studentId, (int) $examId)
            : LiveExamSession::where('student_id', $studentId)
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

    /**
     * Serve identity photo for an active session (centre-scoped).
     */
    public function proctorPhoto(Request $request, $studentId, $examId)
    {
        $session = $this->liveSessions->find((int) $studentId, (int) $examId);
        if (!$session || empty($session->proctor_photo_path)) {
            abort(404, 'Photo not found.');
        }

        $this->proctorMedia->authorizeStaff($request->user(), $session);

        if (!Storage::disk('local')->exists($session->proctor_photo_path)) {
            abort(404, 'Photo file missing.');
        }

        $mime = str_ends_with($session->proctor_photo_path, '.png') ? 'image/png' : 'image/jpeg';
        return response(Storage::disk('local')->get($session->proctor_photo_path), 200, [
            'Content-Type'  => $mime,
            'Cache-Control' => 'private, max-age=30',
        ]);
    }

    /** Poll WebRTC signaling state */
    public function getProctorSignal(Request $request, $studentId, $examId)
    {
        $session = $this->liveSessions->find((int) $studentId, (int) $examId);
        if (!$session) {
            abort(404, 'Active session not found.');
        }
        $this->proctorMedia->authorizeStaff($request->user(), $session);

        return response()->json([
            'ok'            => true,
            'camera_active' => (bool) $session->camera_active,
            'has_photo'     => !empty($session->proctor_photo_path),
            'signals'       => $this->proctorMedia->getSignals((int) $studentId, (int) $examId),
        ]);
    }

    /**
     * Staff WebRTC signaling (SDP/ICE only — video never uploaded).
     */
    public function postProctorSignal(Request $request, $studentId, $examId)
    {
        $data = $request->validate([
            'action'    => 'required|in:status,answer,ice,watch,clear',
            'sdp'       => 'nullable|array',
            'candidate' => 'nullable|array',
        ]);

        $session = $this->liveSessions->find((int) $studentId, (int) $examId);
        if (!$session) {
            abort(404, 'Active session not found.');
        }

        $this->proctorMedia->authorizeStaff($request->user(), $session);

        $sid = (int) $studentId;
        $eid = (int) $examId;
        $signals = $this->proctorMedia->getSignals($sid, $eid);

        if ($data['action'] === 'watch') {
            $signals['viewer_watching'] = true;
            // Force student to renegotiate a fresh offer for this viewer
            $signals['offer'] = null;
            $signals['answer'] = null;
            $signals['ice_student'] = [];
            $signals['ice_viewer'] = [];
            $this->proctorMedia->putSignals($sid, $eid, $signals);
        } elseif ($data['action'] === 'clear') {
            $signals['viewer_watching'] = false;
            $signals['answer'] = null;
            $signals['ice_viewer'] = [];
            $this->proctorMedia->putSignals($sid, $eid, $signals);
        } elseif ($data['action'] === 'answer' && !empty($data['sdp'])) {
            $signals['answer'] = $data['sdp'];
            $signals['viewer_watching'] = true;
            $this->proctorMedia->putSignals($sid, $eid, $signals);
        } elseif ($data['action'] === 'ice' && !empty($data['candidate'])) {
            $list = $signals['ice_viewer'] ?? [];
            $list[] = $data['candidate'];
            $signals['ice_viewer'] = array_slice($list, -30);
            $this->proctorMedia->putSignals($sid, $eid, $signals);
        } else {
            $this->proctorMedia->putSignals($sid, $eid, $signals);
        }

        return response()->json([
            'ok'            => true,
            'camera_active' => (bool) $session->camera_active,
            'has_photo'     => !empty($session->proctor_photo_path),
            'signals'       => $this->proctorMedia->getSignals($sid, $eid),
        ]);
    }
}
