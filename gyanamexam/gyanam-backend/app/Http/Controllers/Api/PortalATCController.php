<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PortalAtcCentres;
use Illuminate\Http\Request;

/**
 * PortalATCController
 * Stores and serves ATC centre metadata (code, name, centre_type)
 * synced from the Gyanam India main portal.
 *
 * Stored in: storage/app/portal_atcs.json
 * This avoids any DB schema changes — metadata is cached as JSON.
 */
class PortalATCController extends Controller
{
    private const CACHE_FILE = 'portal_atcs.json';

    private function cachePath(): string
    {
        return storage_path('app/' . self::CACHE_FILE);
    }

    /**
     * Receive synced ATC centre data from the main portal.
     * POST /api/v1/portal-atc-centres
     *
     * Body: { centres: [ { code, name, centre_type, district, state }, ... ] }
     */
    public function sync(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            return response()->json(['message' => 'Forbidden. Admin only.'], 403);
        }

        $centres = $request->input('centres', []);

        file_put_contents($this->cachePath(), json_encode([
            'synced_at' => now()->toISOString(),
            'centres'   => $centres,
        ], JSON_PRETTY_PRINT));

        return response()->json([
            'message' => count($centres) . ' ATC centres synced successfully.',
            'count'   => count($centres),
        ]);
    }

    /**
     * Return the cached ATC centre list with types.
     * GET /api/v1/portal-atc-centres
     *
     * Supports ?type=Abacus filtering.
     */
    public function index(Request $request)
    {
        $path = $this->cachePath();

        if (!file_exists($path)) {
            return response()->json([
                'centres'   => [],
                'synced_at' => null,
                'message'   => 'No ATC centres synced yet. Please sync from the main portal.',
            ]);
        }

        $data     = json_decode(file_get_contents($path), true);
        $centres  = $data['centres'] ?? [];

        // Optional ?type= filter (matches master types against combo centre_type)
        $typeFilter = $request->query('type');
        if ($typeFilter) {
            $centres = array_values(array_filter($centres, function ($c) use ($typeFilter) {
                return PortalAtcCentres::matchesType($c['centre_type'] ?? '', (string) $typeFilter);
            }));
        }

        // Master types + any synced combo values for dropdowns
        $syncedTypes = collect($data['centres'] ?? [])
            ->pluck('centre_type')
            ->filter()
            ->unique();
        $allTypes = collect(['Abacus', 'Vedic Maths', 'IT'])
            ->merge($syncedTypes)
            ->unique()
            ->sort()
            ->values();

        return response()->json([
            'centres'    => $centres,
            'types'      => $allTypes,
            'synced_at'  => $data['synced_at'] ?? null,
            'count'      => count($centres),
        ]);
    }
}
