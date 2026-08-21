<?php
/**
 * ATC-facing Auth Certificate downloader.
 * Redirects to the admin generator with the session ATC's own ID.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin(['ATC CENTER']);

$atcId = intval($_SESSION['atc_id'] ?? 0);
if (!$atcId) {
    die('Session error: ATC ID not found.');
}

$preview = isset($_GET['preview']) ? '&preview=1' : '';
header('Location: ../admin/generate_auth_certificate.php?atc_id=' . $atcId . $preview);
exit;
