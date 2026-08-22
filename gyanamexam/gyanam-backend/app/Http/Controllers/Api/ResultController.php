<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Submission;
use Illuminate\Http\Request;

class ResultController extends Controller
{
    /**
     * All submissions scoped by centre (admin sees all).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $perPage = min(100, max(10, (int) $request->input('per_page', 50)));
        $page = max(1, (int) $request->input('page', 1));

        $query = Submission::with(['student', 'exam'])
            ->forCentre($user->centre_id)
            ->when($request->since, fn ($q) => $q->where('submitted_at', '>=', $request->since))
            ->when($request->student_identifier, function ($q) use ($request) {
                $q->whereHas('student', fn ($sq) => $sq->where('identifier', $request->student_identifier));
            })
            ->when($request->q, function ($q) use ($request) {
                $term = '%' . $request->q . '%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('student_name', 'like', $term)
                        ->orWhere('exam_title', 'like', $term)
                        ->orWhere('centre_name', 'like', $term);
                });
            })
            ->latest('submitted_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // Aggregate stats over the filtered set (not only the current page)
        $statsQuery = Submission::query()
            ->forCentre($user->centre_id)
            ->when($request->since, fn ($q) => $q->where('submitted_at', '>=', $request->since))
            ->when($request->student_identifier, function ($q) use ($request) {
                $q->whereHas('student', fn ($sq) => $sq->where('identifier', $request->student_identifier));
            })
            ->when($request->q, function ($q) use ($request) {
                $term = '%' . $request->q . '%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('student_name', 'like', $term)
                        ->orWhere('exam_title', 'like', $term)
                        ->orWhere('centre_name', 'like', $term);
                });
            });

        $totalAll = (clone $statsQuery)->count();
        $passedAll = (clone $statsQuery)->where('result', 'pass')->count();
        $failedAll = (clone $statsQuery)->where('result', 'fail')->count();
        $avgAll = $totalAll ? round((clone $statsQuery)->avg('score')) : 0;

        return response()->json([
            'submissions' => $paginator->items(),
            'stats'       => [
                'total'  => $totalAll,
                'passed' => $passedAll,
                'failed' => $failedAll,
                'avg'    => $avgAll,
            ],
            'pagination'  => [
                'page'      => $paginator->currentPage(),
                'per_page'  => $paginator->perPage(),
                'total'     => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * Export results as CSV.
     */
    public function export(Request $request)
    {
        $user = $request->user();
        $subs = Submission::with(['student', 'exam'])
            ->forCentre($user->centre_id)
            ->latest('submitted_at')
            ->get();

        $csv  = "Student,Exam,Score,Result,Submitted At\n";
        foreach ($subs as $s) {
            $csv .= implode(',', [
                $s->student_name,
                $s->exam_title,
                $s->score . '%',
                $s->result,
                $s->submitted_at,
            ]) . "\n";
        }

        return response($csv, 200, [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => 'attachment; filename="results.csv"',
        ]);
    }

    private function stats($subs): array
    {
        $total  = $subs->count();
        $passed = $subs->where('result', 'pass')->count();
        $failed = $subs->where('result', 'fail')->count();
        $avg    = $total ? round($subs->avg('score')) : 0;
        return compact('total', 'passed', 'failed', 'avg');
    }
}
