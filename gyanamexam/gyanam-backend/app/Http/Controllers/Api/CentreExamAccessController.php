<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CentreExamAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class CentreExamAccessController extends Controller
{
    public function show(Request $request)
    {
        $centre = $this->centreCode($request);

        return response()->json(CentreExamAccess::status($centre));
    }

    public function open(Request $request)
    {
        $user = $request->user();
        $centre = $this->centreCode($request);
        $password = (string) $request->validate([
            'password' => 'required|string|max:200',
        ])['password'];

        if (!Hash::check($password, (string) $user->password)) {
            return response()->json(['message' => 'Password is not correct.'], 422);
        }

        $status = CentreExamAccess::open($centre, (int) $user->id, 3);

        return response()->json(array_merge($status, [
            'message' => 'Main exams are open at your centre for 3 hours.',
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
