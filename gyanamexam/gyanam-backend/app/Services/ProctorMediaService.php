<?php

namespace App\Services;

use App\Models\LiveExamSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Proctor media helpers:
 * - Identity photo: one small JPEG on disk for the active session only
 * - WebRTC signaling: tiny SDP/ICE JSON in cache — media never touches the server
 */
class ProctorMediaService
{
    public const SIGNAL_TTL = 120; // seconds

    public function signalKey(int $studentId, int $examConfigId): string
    {
        return "proctor_rtc:{$studentId}:{$examConfigId}";
    }

    public function getSignals(int $studentId, int $examConfigId): array
    {
        return Cache::get($this->signalKey($studentId, $examConfigId), [
            'offer' => null,
            'answer' => null,
            'ice_student' => [],
            'ice_viewer' => [],
            'viewer_watching' => false,
            'updated_at' => null,
        ]);
    }

    public function putSignals(int $studentId, int $examConfigId, array $data): array
    {
        $data['updated_at'] = now()->toISOString();
        Cache::put($this->signalKey($studentId, $examConfigId), $data, self::SIGNAL_TTL);
        return $data;
    }

    public function clearSignals(int $studentId, int $examConfigId): void
    {
        Cache::forget($this->signalKey($studentId, $examConfigId));
    }

    public function savePhoto(LiveExamSession $session, string $dataUrl): string
    {
        if (!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,#i', $dataUrl, $m)) {
            throw new \InvalidArgumentException('Invalid image data.');
        }

        $binary = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $dataUrl), true);
        if ($binary === false || strlen($binary) < 100) {
            throw new \InvalidArgumentException('Could not decode image.');
        }
        if (strlen($binary) > 600 * 1024) {
            throw new \InvalidArgumentException('Photo too large (max 600KB).');
        }

        $ext = strtolower($m[1]) === 'png' ? 'png' : 'jpg';
        $path = sprintf(
            'proctor_photos/%d_%d_%d.%s',
            $session->student_id,
            $session->exam_config_id,
            $session->attempt_number ?: 1,
            $ext
        );

        if ($session->proctor_photo_path && $session->proctor_photo_path !== $path) {
            Storage::disk('local')->delete($session->proctor_photo_path);
        }

        Storage::disk('local')->put($path, $binary);
        $session->proctor_photo_path = $path;
        $session->save();

        return $path;
    }

    public function deletePhoto(?LiveExamSession $session): void
    {
        if (!$session?->proctor_photo_path) {
            return;
        }
        Storage::disk('local')->delete($session->proctor_photo_path);
    }

    public function authorizeStaff($user, LiveExamSession $session): void
    {
        if ($user->isAdmin()) {
            return;
        }
        if (($session->centre_name ?? '') !== ($user->centre_id ?? '')) {
            abort(403, 'You can only view proctor media for your centre.');
        }
    }
}
