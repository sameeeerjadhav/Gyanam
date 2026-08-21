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
        $subs = Submission::with(['student','exam'])
            ->forCentre($user->centre_id)
            ->when($request->since, fn($q) => $q->where('submitted_at', '>=', $request->since))
            ->when($request->student_identifier, function ($q) use ($request) {
                $q->whereHas('student', fn($sq) => $sq->where('identifier', $request->student_identifier));
            })
            ->latest('submitted_at')
            ->get();

        return response()->json([
            'submissions' => $subs,
            'stats'       => $this->stats($subs),
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
