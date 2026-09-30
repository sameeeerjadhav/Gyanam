<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\User;
use App\Support\CentreExamAccess;
use App\Support\PortalAtcCentres;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class CentreExamAccessController extends Controller
{
    public function show(Request $request)
    {
        $centre = $this->centreCode($request);
        $status = CentreExamAccess::status($centre);
        $students = Student::query()
            ->where('centre_name', $centre)
            ->orderBy('name')
            ->get(['id', 'identifier', 'name'])
            ->map(fn ($student) => [
                'id' => (int) $student->id,
                'identifier' => (string) $student->identifier,
                'name' => (string) $student->name,
            ])
            ->values();

        return response()->json(array_merge($status, [
            'students' => $students,
        ]));
    }

    public function open(Request $request)
    {
        $user = $request->user();
        $centre = $this->centreCode($request);
        $data = $request->validate([
            'password' => 'required|string|max:200',
            'scope' => 'nullable|in:all,students',
            'student_ids' => 'nullable|array|max:500',
            'student_ids.*' => 'integer',
        ]);
        $password = (string) $data['password'];

        if (!Hash::check($password, (string) $user->password)) {
            return response()->json(['message' => 'Password is not correct.'], 422);
        }

        $scope = ($data['scope'] ?? 'all') === 'students' ? 'students' : 'all';
        $studentIds = [];
        if ($scope === 'students') {
            $requested = array_values(array_unique(array_map('intval', $data['student_ids'] ?? [])));
            if ($requested === []) {
                return response()->json(['message' => 'Select at least one student.'], 422);
            }
            $studentIds = Student::query()
                ->where('centre_name', $centre)
                ->whereIn('id', $requested)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
            if ($studentIds === []) {
                return response()->json(['message' => 'Select students from your centre.'], 422);
            }
        }

        $status = CentreExamAccess::open($centre, (int) $user->id, 3, $scope, $studentIds);
        $who = $scope === 'students'
            ? count($studentIds) . ' selected student(s)'
            : 'all students at your centre';

        return response()->json(array_merge($status, [
            'message' => 'Main exams are open for ' . $who . ' for 3 hours.',
        ]));
    }

    public function close(Request $request)
    {
        $centre = $this->centreCode($request);
        $status = CentreExamAccess::close($centre);

        return response()->json(array_merge($status, [
            'message' => 'Main exams are closed. Students who already started can finish.',
        ]));
    }

    public function centres(Request $request)
    {
        $this->requireAdmin($request);
        $rows = User::query()
            ->where('role', 'atc')
            ->whereNotNull('centre_id')
            ->where('centre_id', '!=', '')
            ->orderBy('name')
            ->get(['centre_id', 'name']);

        $centreNames = [];
        foreach (PortalAtcCentres::all() as $synced) {
            $code = strtolower(trim((string) ($synced['code'] ?? '')));
            $label = trim((string) ($synced['name'] ?? ''));
            if ($code !== '' && $label !== '') {
                $centreNames[$code] = $label;
            }
        }

        $centres = [];
        foreach ($rows as $row) {
            $code = trim((string) $row->centre_id);
            if ($code === '' || isset($centres[$code])) {
                continue;
            }
            $centres[$code] = [
                'code' => $code,
                'name' => $centreNames[strtolower($code)] ?? '',
                'owner' => (string) $row->name,
            ];
        }
        $flags = CentreExamAccess::annotate(array_keys($centres));
        $list = [];
        foreach ($centres as $code => $centre) {
            $flag = $flags[$code] ?? ['open' => false, 'expires_at' => null];
            $list[] = array_merge($centre, $flag);
        }
        usort($list, function ($a, $b) {
            $left = $a['name'] !== '' ? $a['name'] : $a['owner'];
            $right = $b['name'] !== '' ? $b['name'] : $b['owner'];
            return strcasecmp($left, $right);
        });

        return response()->json(['centres' => $list]);
    }

    public function openCentres(Request $request)
    {
        $user = $this->requireAdmin($request);
        $data = $request->validate([
            'password' => 'required|string|max:200',
            'centres' => 'required|array|min:1|max:100',
            'centres.*' => 'string|max:255',
        ]);
        if (!Hash::check($data['password'], (string) $user->password)) {
            return response()->json(['message' => 'Password is not correct.'], 422);
        }
        $allowed = $this->allowedCentreCodes();
        $chosen = array_values(array_filter($data['centres'], fn ($code) => isset($allowed[trim((string) $code)])));
        if ($chosen === []) {
            return response()->json(['message' => 'Select at least one ATC.'], 422);
        }
        $count = CentreExamAccess::openMany($chosen, (int) $user->id, 3);

        return response()->json([
            'message' => 'Main exams are open for ' . $count . ' ATC(s) for 3 hours.',
            'count' => $count,
        ]);
    }

    public function closeCentres(Request $request)
    {
        $user = $this->requireAdmin($request);
        $data = $request->validate([
            'centres' => 'required|array|min:1|max:100',
            'centres.*' => 'string|max:255',
        ]);
        $allowed = $this->allowedCentreCodes();
        $chosen = array_values(array_filter($data['centres'], fn ($code) => isset($allowed[trim((string) $code)])));
        if ($chosen === []) {
            return response()->json(['message' => 'Select at least one ATC.'], 422);
        }
        $count = CentreExamAccess::closeMany($chosen);

        return response()->json([
            'message' => 'Main exams are closed for ' . $count . ' ATC(s).',
            'count' => $count,
        ]);
    }

    private function requireAdmin(Request $request): User
    {
        $user = $request->user();
        if (!$user->isAdmin()) {
            abort(403, 'Only an admin can open exams for selected ATCs.');
        }

        return $user;
    }

    /** @return array<string, true> */
    private function allowedCentreCodes(): array
    {
        $codes = [];
        $rows = User::query()
            ->where('role', 'atc')
            ->whereNotNull('centre_id')
            ->where('centre_id', '!=', '')
            ->pluck('centre_id');
        foreach ($rows as $code) {
            $code = trim((string) $code);
            if ($code !== '') {
                $codes[$code] = true;
            }
        }

        return $codes;
    }

    private function centreCode(Request $request): string
    {
        $user = $request->user();
        if ($user->role !== 'atc') {
            abort(403, 'Only an ATC login can open exams for a centre.');
        }
        $centre = trim((string) ($user->centre_id ?? ''));
        if ($centre === '') {
            abort(422, 'This login is not linked to a centre.');
        }

        return $centre;
    }
}
