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
     * Match master types (Abacus / Vedic Maths / IT / Typing) against combo centre_type values.
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
        if ($ft === 'typing') {
            return str_contains($raw, 'typing');
        }
        if ($ft === 'it') {
            // Word-boundary so "Typing" alone does not count as IT
            return (bool) preg_match('/(^|[^a-z])it([^a-z]|$)/', $raw)
                || str_contains($raw, 'all three');
        }

        return str_contains($raw, $ft);
    }

    public static function normalizeCourseName(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return mb_strtolower($value);
    }

    /**
     * Course names this ATC has activated on the main portal.
     * null means the centre was synced before active courses were included.
     *
     * @return list<string>|null
     */
    public static function activeCourseNames(?string $code): ?array
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }

        foreach (self::all() as $centre) {
            if (strcasecmp(trim((string) ($centre['code'] ?? '')), $code) !== 0) {
                continue;
            }
            if (!array_key_exists('active_courses', $centre) || !is_array($centre['active_courses'])) {
                return null;
            }
            $names = [];
            foreach ($centre['active_courses'] as $name) {
                $name = trim((string) $name);
                if ($name !== '') {
                    $names[$name] = $name;
                }
            }

            return array_values($names);
        }

        return null;
    }

    /** Admins and unsynced centres are not restricted. An empty synced list hides every course. */
    public static function centreAllowsSubject(?string $centreId, ?string $subject): bool
    {
        if ($centreId === null || trim($centreId) === '') {
            return true;
        }
        $allowed = self::activeCourseNames($centreId);
        if ($allowed === null) {
            return true;
        }
        $needle = self::normalizeCourseName($subject);
        if ($needle === '') {
            return false;
        }
        foreach ($allowed as $name) {
            if (self::normalizeCourseName($name) === $needle) {
                return true;
            }
        }

        return false;
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
