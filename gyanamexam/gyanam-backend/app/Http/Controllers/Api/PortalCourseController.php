<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * PortalCourseController
 * Receives course data synced from the Gyanam India main portal.
 * Stores courses in JSON + Laravel cache so the exam portal admin frontend
 * can populate QB / exam subject dropdowns with real course names.
 */
class PortalCourseController extends Controller
{
    private const CACHE_KEY = 'portal_courses_payload';
    private const CACHE_FILE = 'portal_courses.json';

    private function cachePath(): string
    {
        return storage_path('app/' . self::CACHE_FILE);
    }

    /**
     * @return array{synced_at:?string,courses:array}
     */
    private function readPayload(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached) && isset($cached['courses']) && is_array($cached['courses'])) {
            return [
                'synced_at' => $cached['synced_at'] ?? null,
                'courses'   => $cached['courses'],
            ];
        }

        $path = $this->cachePath();
        if (!file_exists($path)) {
            return ['synced_at' => null, 'courses' => []];
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return ['synced_at' => null, 'courses' => []];
        }

        return [
            'synced_at' => $data['synced_at'] ?? null,
            'courses'   => is_array($data['courses'] ?? null) ? $data['courses'] : [],
        ];
    }

    /**
     * Receive synced courses from the main portal.
     * POST /api/v1/portal-courses
     *
     * Body: { courses: [ { id, course_name, course_type, duration, status }, ... ] }
     */
    public function sync(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Forbidden. Admin only.'], 403);
        }

        $courses = $request->input('courses', []);
        if (!is_array($courses)) {
            return response()->json(['message' => 'Invalid courses payload.'], 422);
        }

        // Re-index and drop empty names
        $normalized = [];
        foreach ($courses as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['course_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $normalized[] = [
                'id'          => $row['id'] ?? null,
                'course_name' => $name,
                'course_type' => $row['course_type'] ?? null,
                'duration'    => $row['duration'] ?? null,
                'status'      => $row['status'] ?? 'Active',
            ];
        }

        $payload = [
            'synced_at' => now()->toISOString(),
            'courses'   => array_values($normalized),
        ];

        $dir = dirname($this->cachePath());
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            Log::error('portal_courses: cannot create storage dir', ['dir' => $dir]);
            return response()->json([
                'message' => 'Cannot write course cache (storage directory missing).',
            ], 500);
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return response()->json(['message' => 'Failed to encode courses JSON.'], 500);
        }

        $written = @file_put_contents($this->cachePath(), $json, LOCK_EX);
        if ($written === false) {
            Log::error('portal_courses: file_put_contents failed', ['path' => $this->cachePath()]);
            return response()->json([
                'message' => 'Failed to write portal_courses.json. Fix permissions on storage/app.',
            ], 500);
        }

        try {
            Cache::forever(self::CACHE_KEY, $payload);
        } catch (\Throwable $e) {
            Log::warning('portal_courses: cache write failed: ' . $e->getMessage());
        }

        // Re-read to confirm persistence before acknowledging success
        $stored = $this->readPayload();
        $storedCount = count($stored['courses']);
        if ($storedCount < count($payload['courses'])) {
            return response()->json([
                'message' => 'Course cache write did not persist (got ' . $storedCount . ' of ' . count($payload['courses']) . ').',
                'count'   => $storedCount,
            ], 500);
        }

        return response()->json([
            'message'   => $storedCount . ' courses synced successfully.',
            'count'     => $storedCount,
            'synced_at' => $payload['synced_at'],
        ]);
    }

    /**
     * Get the list of synced courses (for exam portal frontend dropdowns).
     * GET /api/v1/portal-courses
     */
    public function index(Request $request)
    {
        $data = $this->readPayload();

        if (empty($data['courses'])) {
            return response()->json([
                'courses'   => [],
                'synced_at' => $data['synced_at'],
                'count'     => 0,
                'message'   => 'No courses synced yet. Please sync from the main portal.',
            ]);
        }

        return response()->json([
            'courses'   => $data['courses'],
            'synced_at' => $data['synced_at'],
            'count'     => count($data['courses']),
        ]);
    }
}
