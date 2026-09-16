<?php
/**
 * Authenticated training video stream.
 * Direct file URLs under uploads/training_videos/ are blocked.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin(['Training']);

$pdo = getDBConnection();
$atcId = $_SESSION['atc_id'] ?? null;
$assignId = (int)($_GET['a'] ?? 0);

if ($assignId <= 0) {
    http_response_code(400);
    exit('Invalid request');
}

$sql = "
    SELECT tv.video_path, tv.video_type, tv.status
    FROM video_assignments va
    JOIN training_videos tv ON tv.id = va.video_id AND tv.status = 'Active'
    WHERE va.id = ?
      AND (va.atc_id IS NULL" . ($atcId ? " OR va.atc_id = ?" : "") . ")
      AND va.access_start <= CURDATE()
      AND va.access_end >= CURDATE()
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$atcId ? $stmt->execute([$assignId, $atcId]) : $stmt->execute([$assignId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row || ($row['video_type'] ?? '') !== 'upload' || empty($row['video_path'])) {
    http_response_code(403);
    exit('Access denied');
}

$uploadRoot = realpath(__DIR__ . '/../uploads/training_videos');
$candidate = realpath(__DIR__ . '/../' . ltrim(str_replace(['\\', "\0"], ['/', ''], $row['video_path']), '/'));

if (!$uploadRoot || !$candidate || strpos($candidate, $uploadRoot) !== 0 || !is_file($candidate)) {
    http_response_code(404);
    exit('Video not found');
}

$size = filesize($candidate);
if ($size === false) {
    http_response_code(500);
    exit('Unable to read file');
}

$ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
$mimeMap = [
    'mp4'  => 'video/mp4',
    'webm' => 'video/webm',
    'ogg'  => 'video/ogg',
    'ogv'  => 'video/ogg',
    'mov'  => 'video/quicktime',
    'm4v'  => 'video/mp4',
];
$mime = $mimeMap[$ext] ?? 'application/octet-stream';

// Discourage caching / offline saves
header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');
header('Cache-Control: private, no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="training.mp4"');

$start = 0;
$end = $size - 1;

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
    if ($m[1] !== '') {
        $start = (int)$m[1];
    }
    if ($m[2] !== '') {
        $end = (int)$m[2];
    }
    if ($end >= $size) {
        $end = $size - 1;
    }
    if ($start > $end || $start >= $size) {
        http_response_code(416);
        header("Content-Range: bytes */$size");
        exit;
    }
    http_response_code(206);
    header("Content-Range: bytes $start-$end/$size");
}

$length = $end - $start + 1;
header("Content-Length: $length");

$fp = fopen($candidate, 'rb');
if (!$fp) {
    http_response_code(500);
    exit('Unable to open file');
}

fseek($fp, $start);
$chunk = 8192;
$sent = 0;
while (!feof($fp) && $sent < $length && connection_status() === CONNECTION_NORMAL) {
    $read = min($chunk, $length - $sent);
    $buf = fread($fp, $read);
    if ($buf === false) {
        break;
    }
    echo $buf;
    $sent += strlen($buf);
    if (function_exists('ob_flush')) {
        @ob_flush();
    }
    flush();
}
fclose($fp);
exit;
