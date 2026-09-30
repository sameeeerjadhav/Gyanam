<?php

namespace App\Support;

/**
 * One open/close flag per ATC centre. Students only read it when a main exam starts.
 */
class CentreExamAccess
{
    private const FILE = 'centre_exam_access.json';

    public static function isOpen(?string $centre): bool
    {
        $row = self::row($centre);
        if ($row === null) {
            return false;
        }
        $expires = strtotime((string) ($row['expires_at'] ?? ''));

        return $expires !== false && $expires > time();
    }

    public static function status(string $centre): array
    {
        $row = self::row($centre);
        $open = self::isOpen($centre);

        return [
            'open' => $open,
            'expires_at' => $open ? ($row['expires_at'] ?? null) : null,
            'opened_at' => $open ? ($row['opened_at'] ?? null) : null,
        ];
    }

    public static function open(string $centre, int $userId, int $hours = 3): array
    {
        $hours = max(1, min(6, $hours));
        $now = now();
        $row = [
            'opened_at' => $now->toIso8601String(),
            'expires_at' => $now->copy()->addHours($hours)->toIso8601String(),
            'opened_by' => $userId,
            'hours' => $hours,
        ];
        self::write($centre, $row);

        return self::status($centre);
    }

    public static function close(string $centre): array
    {
        self::write($centre, null);

        return self::status($centre);
    }

    private static function row(?string $centre): ?array
    {
        $centre = trim((string) $centre);
        if ($centre === '') {
            return null;
        }
        $all = self::read();
        $row = $all[$centre] ?? null;

        return is_array($row) ? $row : null;
    }

    private static function read(): array
    {
        $path = storage_path('app/' . self::FILE);
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    private static function write(string $centre, ?array $row): void
    {
        $path = storage_path('app/' . self::FILE);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $fh = fopen($path, 'c+');
        if ($fh === false) {
            return;
        }
        try {
            flock($fh, LOCK_EX);
            $raw = stream_get_contents($fh);
            $all = json_decode($raw ?: '', true);
            if (!is_array($all)) {
                $all = [];
            }
            if ($row === null) {
                unset($all[$centre]);
            } else {
                $all[$centre] = $row;
            }
            rewind($fh);
            ftruncate($fh, 0);
            fwrite($fh, json_encode($all));
            fflush($fh);
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }
}
