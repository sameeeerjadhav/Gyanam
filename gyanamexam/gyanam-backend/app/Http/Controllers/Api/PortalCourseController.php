<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * PortalCourseController
 * Receives course data synced from the Gyanam India main portal.
 * Stores courses in a simple cache so the exam portal admin frontend
 * can populate QB subject dropdowns with real course names.
 */
class PortalCourseController extends Controller
{
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
        
        // Store in a simple JSON file that the frontend can read
        $cachePath = storage_path('app/portal_courses.json');
        file_put_contents($cachePath, json_encode([
            'synced_at' => now()->toISOString(),
            'courses'   => $courses,
        ], JSON_PRETTY_PRINT));

        return response()->json([
            'message' => count($courses) . ' courses synced successfully.',
            'count'   => count($courses),
        ]);
    }

    /**
     * Get the list of synced courses (for exam portal frontend dropdowns).
     * GET /api/v1/portal-courses
     */
    public function index(Request $request)
    {
        $cachePath = storage_path('app/portal_courses.json');
        
        if (!file_exists($cachePath)) {
            return response()->json([
                'courses'   => [],
                'synced_at' => null,
                'message'   => 'No courses synced yet. Please sync from the main portal.',
            ]);
        }

        $data = json_decode(file_get_contents($cachePath), true);
        return response()->json($data);
    }
}
