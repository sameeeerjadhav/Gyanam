<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * PortalUserController
 * Allows the Gyanam India admin portal to create/update/delete
 * ATC and DLC user accounts in the Exam Portal via the integration API.
 * Protected by admin-only Bearer token (sanctum).
 */
class PortalUserController extends Controller
{
    /**
     * List all portal users (admin, atc, dlc).
     * GET /api/v1/portal-users
     * Admin-only endpoint for the Credentials management page.
     */
    public function index(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Forbidden. Admin only.'], 403);
        }

        $users = User::orderByRaw("FIELD(role, 'admin', 'atc', 'dlc')")
            ->orderBy('name')
            ->get(['id', 'username', 'name', 'email', 'role', 'centre_id', 'created_at']);

        return response()->json($users);
    }

    /**
     * Upsert (create or update) a portal user.
     * POST /api/v1/portal-users
     *
     * Body: { username, name, email, password, role, centre_id }
     */
    public function upsert(Request $request)
    {
        // Only admin token can manage portal users
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Forbidden. Admin only.'], 403);
        }

        $validated = $request->validate([
            'username'   => 'required|string|max:255',
            'name'       => 'required|string|max:255',
            'email'      => 'nullable|email|max:255',
            'password'   => 'nullable|string|min:4',
            'role'       => 'required|in:atc,dlc,admin',
            'centre_id'  => 'nullable|string|max:255',
        ]);

        $existing = User::where('username', $validated['username'])->first();

        if ($existing) {
            // Update
            $existing->name      = $validated['name'];
            $existing->email     = $validated['email'] ?? $existing->email;
            $existing->role      = $validated['role'];
            $existing->centre_id = $validated['centre_id'] ?? $existing->centre_id;
            if (!empty($validated['password'])) {
                $existing->password = Hash::make($validated['password']);
            }
            $existing->save();

            return response()->json([
                'message' => 'User updated successfully.',
                'user'    => $existing->only(['id', 'username', 'name', 'role', 'centre_id']),
                'action'  => 'updated',
            ]);
        } else {
            // Create
            $user = User::create([
                'username'   => $validated['username'],
                'name'       => $validated['name'],
                'email'      => $validated['email'] ?? null,
                'password'   => Hash::make($validated['password'] ?? 'Admin@1234'),
                'role'       => $validated['role'],
                'centre_id'  => $validated['centre_id'] ?? null,
            ]);

            return response()->json([
                'message' => 'User created successfully.',
                'user'    => $user->only(['id', 'username', 'name', 'role', 'centre_id']),
                'action'  => 'created',
            ], 201);
        }
    }

    /**
     * Delete a portal user by username.
     * DELETE /api/v1/portal-users/{username}
     */
    public function destroyByUsername(Request $request, string $username)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Forbidden. Admin only.'], 403);
        }

        $user = User::where('username', $username)->where('role', '!=', 'admin')->first();

        if (!$user) {
            return response()->json(['message' => 'User not found or cannot delete admin.'], 404);
        }

        $user->delete();
        return response()->json(['message' => "User '{$username}' deleted from Exam Portal."]);
    }

    /**
     * Get all unique ATC centres from the Exam Portal users.
     * GET /api/v1/portal-centres
     * Used by the QB module to populate the "Assign to Centres" dropdown.
     */
    public function getCentres(Request $request)
    {
        $centres = User::where('role', 'atc')
            ->whereNotNull('centre_id')
            ->where('centre_id', '!=', '')
            ->orderBy('name')
            ->get(['centre_id', 'name'])
            ->map(fn($u) => [
                'code' => $u->centre_id,
                'name' => $u->name,
            ]);

        // Deduplicate by code (in case multiple users share a centre)
        $unique = collect($centres)->unique('code')->values();

        return response()->json([
            'centres' => $unique,
            'count'   => $unique->count(),
        ]);
    }
}
