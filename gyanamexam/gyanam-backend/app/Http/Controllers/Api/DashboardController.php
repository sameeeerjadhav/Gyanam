<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\Submission;
use App\Models\QuestionBank;
use App\Models\ExamConfig;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class DashboardController extends Controller
{
    /**
     * Get high-level summary stats for the admin dashboard.
     * Replaces multiple heavy API calls with one fast summary.
     */
    public function stats(Request $request)
    {
        $user = $request->user();
        $centreId = $user->centre_id;

        // 1. Basic counts (fast indexed queries)
        $studentCount = Student::when($centreId, fn($q) => $q->where('centre_name', $centreId))->count();
        $bankCount    = QuestionBank::visibleTo($centreId, $user->username)->count();
        $examCount    = ExamConfig::where('active', true)->count();

        // 2. Submission stats
        $submissionQuery = Submission::forCentre($centreId);
        $totalSubmissions = $submissionQuery->count();
        
        $stats = [
            'total'  => $totalSubmissions,
            'passed' => (clone $submissionQuery)->where('result', 'pass')->count(),
            'failed' => (clone $submissionQuery)->where('result', 'fail')->count(),
            'avg'    => $totalSubmissions ? round((clone $submissionQuery)->avg('score')) : 0,
        ];

        // 3. Recent Submissions (limited to 10 for dashboard speed)
        $recentSubmissions = Submission::with(['student', 'exam'])
            ->forCentre($centreId)
            ->latest('submitted_at')
            ->limit(10)
            ->get();

        // 4. Live Sessions (retrieve count from cache index)
        $liveIndex = Cache::get('live_session_index', []);
        $activeLiveCount = 0;
        foreach ($liveIndex as $key) {
            if (Cache::has($key)) $activeLiveCount++;
        }

        return response()->json([
            'counts' => [
                'students'        => $studentCount,
                'question_banks'  => $bankCount,
                'exam_configs'    => $examCount,
                'submissions'     => $totalSubmissions,
                'live_now'        => $activeLiveCount,
            ],
            'stats'            => $stats,
            'recent'           => $recentSubmissions,
            'centre_breakdown' => $user->isAdmin() ? $this->centreBreakdown() : [],
            'server_time'      => now()->toIso8601String(),
        ]);
    }

    private function centreBreakdown(): array
    {
        // Get student counts per centre
        $studentGroups = Student::selectRaw('centre_name, count(*) as student_count')
            ->groupBy('centre_name')
            ->get()
            ->keyBy('centre_name');

        // Get submission stats per centre
        $subGroups = Submission::selectRaw('centre_name, count(*) as total, sum(result="pass") as passed, avg(score) as avg_score')
            ->groupBy('centre_name')
            ->get()
            ->keyBy('centre_name');

        $centres = $studentGroups->keys()->merge($subGroups->keys())->unique()->sort();

        return $centres->map(function ($centre) use ($studentGroups, $subGroups) {
            $stu  = $studentGroups->get($centre);
            $sub  = $subGroups->get($centre);
            $total  = $sub ? (int)$sub->total  : 0;
            $passed = $sub ? (int)$sub->passed : 0;
            return [
                'centre_name'   => $centre,
                'student_count' => $stu ? (int)$stu->student_count : 0,
                'submissions'   => $total,
                'pass_rate'     => $total ? round($passed / $total * 100) : 0,
                'avg_score'     => $sub ? round($sub->avg_score) : 0,
            ];
        })->values()->toArray();
    }
}
