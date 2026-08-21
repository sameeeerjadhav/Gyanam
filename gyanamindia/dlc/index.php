<?php
/**
 * Gyanam Portal — DLC Office Dashboard
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['DLC Office']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$greeting = getGreeting();
$dlcId = $_SESSION['dlc_id'] ?? null;

// Fetch analytics for this DLC — wrapped in try-catch to prevent blank page
$totalATC = 0; $totalInquiries = 0; $totalAdmissions = 0; $activeStudents = 0;

try {
    if ($dlcId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM atc_centers WHERE dlc_id = ?");
        $stmt->execute([$dlcId]);
        $totalATC = $stmt->fetchColumn();
    } else {
        $totalATC = $pdo->query("SELECT COUNT(*) FROM atc_centers")->fetchColumn();
    }
} catch (Exception $e) {}

try {
    if ($dlcId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM inquiries i JOIN atc_centers a ON i.atc_id = a.id WHERE a.dlc_id = ?");
        $stmt->execute([$dlcId]);
        $totalInquiries = $stmt->fetchColumn();
    } else {
        $totalInquiries = $pdo->query("SELECT COUNT(*) FROM inquiries")->fetchColumn();
    }
} catch (Exception $e) {}

try {
    if ($dlcId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions ad JOIN atc_centers a ON ad.atc_id = a.id WHERE a.dlc_id = ?");
        $stmt->execute([$dlcId]);
        $totalAdmissions = $stmt->fetchColumn();
    } else {
        $totalAdmissions = $pdo->query("SELECT COUNT(*) FROM admissions")->fetchColumn();
    }
} catch (Exception $e) {}

try {
    if ($dlcId) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions ad JOIN atc_centers a ON ad.atc_id = a.id WHERE a.dlc_id = ? AND ad.status = 'Active'");
        $stmt->execute([$dlcId]);
        $activeStudents = $stmt->fetchColumn();
    } else {
    }
} catch (Exception $e) {}

$activeBanners = [];
try {
    $stmt = $pdo->query("SELECT * FROM announcements WHERE status = 'Active' AND target_audience IN ('All', 'DLC') ORDER BY created_at DESC");
    $activeBanners = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$shareSummary = ['due' => 0, 'paid' => 0, 'pending' => 0, 'student_count' => 0];
if ($dlcId) {
    try {
        ensureDualMaterialCourseSchema($pdo);
        $shareSummary = calculateDlcShareSummary($pdo, (int)$dlcId);
    } catch (Exception $e) {}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — DLC Login | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📚</text></svg>">
</head>
<body>
<div class="dashboard-layout">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <main class="main-content">
        <header class="top-header">
            <div class="header-left">
                <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <div class="header-greeting">
                    <h2><?= $greeting ?>, <?= $userName ?>!</h2>
                    <p><?= date('l, d F Y') ?></p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">

            <!-- ═══ DASHBOARD BANNERS — Auto-Sliding Carousel ═══ -->
            <?php $imgPrefix = '../uploads/announcements/'; include __DIR__ . '/../includes/banner_carousel.php'; ?>

            <div class="stats-grid">
                <div class="stat-card green">
                    <div class="stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">ATC Centers</div>
                        <div class="stat-value" data-count="<?= $totalATC ?>">0</div>
                    </div>
                </div>
                <div class="stat-card amber">
                    <div class="stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Total Inquiries</div>
                        <div class="stat-value" data-count="<?= $totalInquiries ?>">0</div>
                    </div>
                </div>
                <div class="stat-card purple">
                    <div class="stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Total Admissions</div>
                        <div class="stat-value" data-count="<?= $totalAdmissions ?>">0</div>
                    </div>
                </div>
                <div class="stat-card blue">
                    <div class="stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Active Students</div>
                        <div class="stat-value" data-count="<?= $activeStudents ?>">0</div>
                    </div>
                </div>
                <div class="stat-card green">
                    <div class="stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">DLC Share Pending</div>
                        <div class="stat-value">₹<?= number_format((float)$shareSummary['pending'], 0) ?></div>
                    </div>
                </div>
            </div>

            <div class="section-header">
                <h3>Quick Actions</h3>
            </div>
            <div class="actions-grid">
                <a href="/dlc/atc_centers.php" class="action-card">
                    <div class="action-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                    </div>
                    <h4>Manage ATCs</h4>
                    <p>View and manage ATC centers</p>
                </a>
                <a href="/dlc/share_earnings.php" class="action-card">
                    <div class="action-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <h4>Share Earnings</h4>
                    <p>Per-student DLC share (Due ₹<?= number_format((float)$shareSummary['due'], 0) ?>)</p>
                </a>
                <a href="/dlc/analytics.php" class="action-card">
                    <div class="action-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    </div>
                    <h4>View Reports</h4>
                    <p>Analytics and performance</p>
                </a>
            </div>
        </div>
    </main>
</div>

<script src="../assets/js/dashboard.js"></script>
</body>
</html>
