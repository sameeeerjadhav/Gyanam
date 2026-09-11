<?php
/**
 * DLC Analytics Dashboard
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['DLC Office']);

$pdo   = getDBConnection();
$dlcId = $_SESSION['dlc_id'] ?? null;

// Resolve DLC ID if missing
if (!$dlcId) {
    try {
        $s = $pdo->prepare("SELECT dlc_id FROM users WHERE id = ? LIMIT 1");
        $s->execute([getUserId()]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if ($r && $r['dlc_id']) {
            $dlcId = (int)$r['dlc_id'];
            $_SESSION['dlc_id'] = $dlcId;
        }
    } catch (Exception $e) {}
}
if (!$dlcId) {
    header('Location: /index.php');
    exit;
}
$dlcId = (int)$dlcId;

// ── Metrics ────────────────────────────────────────────────────────
$totalAtcs = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM atc_centers WHERE dlc_id = ?");
    $stmt->execute([$dlcId]);
    $totalAtcs = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

$activeStudents = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions a JOIN atc_centers c ON a.atc_id = c.id WHERE c.dlc_id = ? AND a.status = 'Active'");
    $stmt->execute([$dlcId]);
    $activeStudents = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

$totalInq = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inquiries i JOIN atc_centers c ON i.atc_id = c.id WHERE c.dlc_id = ?");
    $stmt->execute([$dlcId]);
    $totalInq = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

$totalAdmissions = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions a JOIN atc_centers c ON a.atc_id = c.id WHERE c.dlc_id = ?");
    $stmt->execute([$dlcId]);
    $totalAdmissions = (int)$stmt->fetchColumn();
} catch (Exception $e) {}

include __DIR__ . '/_load_analytics_charts.php';
$atcStats = $dlcAtcStats ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics — DLC | Gyanam India</title>
<?php include __DIR__ . '/../includes/head_fonts.php'; ?>
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/dlc-dash.css">
<link rel="stylesheet" href="../assets/css/notifications.css">
<style>
.analytics-table { width:100%; border-collapse:collapse; font-size:.85rem; }
.analytics-table th {
    padding:.7rem 1rem; text-align:left; font-size:.7rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.04em;
    color:var(--text-secondary, #64748b);
    border-bottom:1px solid var(--border-color, #e2e8f0);
    background:var(--bg-secondary, #f8fafc);
}
.analytics-table td { padding:.8rem 1rem; border-bottom:1px solid var(--border-color, #f1f5f9); vertical-align:middle; }
.analytics-table tr:last-child td { border-bottom:none; }
.analytics-table tr:hover td { background:var(--bg-secondary, #f8fafc); }
.tbl-card {
    background: var(--surface-color, #fff);
    border: 1px solid var(--border-color, #e2e8f0);
    border-radius: var(--radius-xl, 16px);
    overflow:hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,.04);
}
.tbl-card-header {
    padding:1rem 1.25rem;
    font-size:.9rem; font-weight:700;
    color:var(--text-primary, #0f1523);
    border-bottom:1px solid var(--border-color, #e2e8f0);
    background:var(--bg-secondary, #f8fafc);
}
.badge-active { display:inline-block; background:#d1fae5; color:#065f46; padding:.15rem .5rem; border-radius:20px; font-size:.7rem; font-weight:700; }
.badge-inactive { display:inline-block; background:#fee2e2; color:#991b1b; padding:.15rem .5rem; border-radius:20px; font-size:.7rem; font-weight:700; }
.num-badge { display:inline-block; background:#eef2ff; color:#4338ca; padding:.15rem .5rem; border-radius:6px; font-weight:700; font-size:.82rem; }
</style>
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
                    <h2>Analytics &amp; Reports</h2>
                    <p>Performance overview for your linked ATC Centers</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">

            <div class="stats-grid">
                <div class="stat-card green">
                    <div class="stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Linked ATCs</div>
                        <div class="stat-value" data-count="<?= $totalAtcs ?>">0</div>
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
                <div class="stat-card purple">
                    <div class="stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Total Admissions</div>
                        <div class="stat-value" data-count="<?= $totalAdmissions ?>">0</div>
                    </div>
                </div>
                <div class="stat-card amber">
                    <div class="stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Total Inquiries</div>
                        <div class="stat-value" data-count="<?= $totalInq ?>">0</div>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/_render_analytics_overview.php'; ?>

            <div class="tbl-card">
                <div class="tbl-card-header">ATC Center Performance Breakdown</div>
                <table class="analytics-table">
                    <thead>
                        <tr>
                            <th>Center Name</th>
                            <th>Code</th>
                            <th>Status</th>
                            <th>Active</th>
                            <th>Total Adm.</th>
                            <th>Inquiries</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($atcStats)): ?>
                        <tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--text-secondary,#64748b)">No ATC centers linked to this DLC.</td></tr>
                    <?php else: ?>
                        <?php foreach ($atcStats as $r): ?>
                        <tr>
                            <td style="font-weight:700;color:var(--text-primary,#0f1523)"><?= htmlspecialchars($r['center_name'] ?? '—') ?></td>
                            <td style="font-family:monospace"><?= htmlspecialchars($r['center_code'] ?? '—') ?></td>
                            <td>
                                <?php if (($r['status'] ?? '') === 'Active'): ?>
                                    <span class="badge-active">Active</span>
                                <?php else: ?>
                                    <span class="badge-inactive"><?= htmlspecialchars($r['status'] ?? 'Inactive') ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="num-badge"><?= (int)($r['active_adm'] ?? 0) ?></span></td>
                            <td><?= (int)($r['total_adm'] ?? 0) ?></td>
                            <td><?= (int)($r['total_inq'] ?? 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

        </div>
    </main>
</div>

<script src="../assets/js/dashboard.js"></script>
<?php include __DIR__ . '/_render_analytics_charts_js.php'; ?>
</body>
</html>
