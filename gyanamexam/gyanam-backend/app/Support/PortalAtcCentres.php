<?php

namespace App\Support;

/**
 * Reads ATC metadata synced from Gyanam India (storage/app/portal_atcs.json).
 */
class PortalAtcCentres
{
    private const CACHE_FILE = 'portal_atcs.json';

    public static function all(): array
    {
        $path = storage_path('app/' . self::CACHE_FILE);
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data['centres'] ?? null) ? $data['centres'] : [];
    }

    /** @return list<string> */
    public static function codes(): array
    {
        $codes = [];
        foreach (self::all() as $c) {
            $code = trim((string) ($c['code'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        return array_values(array_unique($codes));
    }

    /**
     * Match master types (Abacus / Vedic Maths / IT) against combo centre_type values.
     */
    public static function matchesType(?string $centreType, string $targetType): bool
    {
        $centreType = trim((string) $centreType);
        $targetType = trim($targetType);
        if ($centreType === '' || $targetType === '') {
            return false;
        }
        if ($centreType === $targetType) {
            return true;
        }

        $raw = strtolower($centreType);
        $ft  = strtolower($targetType);

        if ($ft === 'abacus') {
            return str_contains($raw, 'abacus');
        }
        if ($ft === 'vedic maths' || $ft === 'vedic') {
            return str_contains($raw, 'vedic');
        }
        if ($ft === 'it') {
            return (bool) preg_match('/(^|[^a-z])it([^a-z]|$)/', $raw)
                || str_contains($raw, 'all three');
        }

        return str_contains($raw, $ft);
    }

    /** @return list<string> */
    public static function codesMatchingType(string $targetType): array
    {
        $codes = [];
        foreach (self::all() as $c) {
            if (!self::matchesType($c['centre_type'] ?? '', $targetType)) {
                continue;
            }
            $code = trim((string) ($c['code'] ?? ''));
            if ($code !== '') {
                $codes[] = $code;
            }
        }
        return array_values(array_unique($codes));
    }
}
