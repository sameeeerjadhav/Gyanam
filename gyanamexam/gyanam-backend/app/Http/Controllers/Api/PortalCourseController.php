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
 *
 * Exam portal lists Active IT courses only.
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
     * Keep Active IT courses only (case-insensitive).
     *
     * @param  array<int,mixed>  $courses
     * @return array<int,array<string,mixed>>
     */
    private function filterExamCourses(array $courses): array
    {
        $out = [];
        foreach ($courses as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['course_name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $type = strtoupper(trim((string) ($row['course_type'] ?? '')));
            $status = strtoupper(trim((string) ($row['status'] ?? 'Active')));
            if ($status === '') {
                $status = 'Active';
            }
            if ($type !== 'IT' || $status !== 'ACTIVE') {
                continue;
            }
            $out[] = [
                'id'          => $row['id'] ?? null,
                'course_name' => $name,
                'course_type' => $row['course_type'] ?? 'IT',
                'duration'    => $row['duration'] ?? null,
                'status'      => 'Active',
            ];
        }

        usort($out, static function ($a, $b) {
            return strcasecmp((string) $a['course_name'], (string) $b['course_name']);
        });

        return array_values($out);
    }

    /**
     * Prefer on-disk JSON (source of truth after sync), then refresh cache.
     *
     * @return array{synced_at:?string,courses:array}
     */
    private function readPayload(bool $bypassCache = false): array
    {
        $path = $this->cachePath();
        if (file_exists($path)) {
            $data = json_decode((string) file_get_contents($path), true);
            if (is_array($data) && isset($data['courses']) && is_array($data['courses'])) {
                $payload = [
                    'synced_at' => $data['synced_at'] ?? null,
                    'courses'   => $data['courses'],
                ];
                try {
                    Cache::forever(self::CACHE_KEY, $payload);
                } catch (\Throwable $e) {
                    // non-fatal
                }
                return $payload;
            }
        }

        if (!$bypassCache) {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached) && isset($cached['courses']) && is_array($cached['courses'])) {
                return [
                    'synced_at' => $cached['synced_at'] ?? null,
                    'courses'   => $cached['courses'],
                ];
            }
        }

        return ['synced_at' => null, 'courses' => []];
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

        $normalized = $this->filterExamCourses($courses);

        $payload = [
            'synced_at' => now()->toISOString(),
            'courses'   => $normalized,
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

        // Drop stale cache before write so readers never see the old short list
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (\Throwable $e) {
            // ignore
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

        $stored = $this->readPayload(true);
        $storedCount = count($this->filterExamCourses($stored['courses']));
        if ($storedCount < count($payload['courses'])) {
            return response()->json([
                'message' => 'Course cache write did not persist (got ' . $storedCount . ' of ' . count($payload['courses']) . ').',
                'count'   => $storedCount,
            ], 500);
        }

        return response()->json([
            'message'   => $storedCount . ' Active IT courses synced successfully.',
            'count'     => $storedCount,
            'synced_at' => $payload['synced_at'],
        ]);
    }

    /**
     * Get the list of synced courses (for exam portal frontend dropdowns).
     * GET /api/v1/portal-courses
     * Optional: ?fresh=1 to ignore stale in-memory assumptions and re-read file.
     */
    public function index(Request $request)
    {
        if ($request->boolean('fresh')) {
            try {
                Cache::forget(self::CACHE_KEY);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $data = $this->readPayload((bool) $request->boolean('fresh'));
        $courses = $this->filterExamCourses($data['courses'] ?? []);

        if (empty($courses)) {
            return response()->json([
                'courses'   => [],
                'synced_at' => $data['synced_at'],
                'count'     => 0,
                'message'   => 'No Active IT courses synced yet. Sync from Gyanam India Admin › Courses.',
            ]);
        }

        return response()->json([
            'courses'   => $courses,
            'synced_at' => $data['synced_at'],
            'count'     => count($courses),
        ]);
    }
}
