<?php
/**
 * One-time tool: rename legacy GIIT1 / GIIT2 → GIIT20261 / GIIT20262
 * Runs automatically when this page is opened. Delete this file after use.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$year = giitRegistrationYear(isset($_GET['year']) ? (int)$_GET['year'] : null);

// Apply migration immediately on page open
$result = migrateLegacyGiitRegistrationIds($pdo, $year, false);

if (!empty($result['error'])) {
    $message = 'Migration failed: ' . $result['error'];
    $messageType = 'error';
} elseif (empty($result['rows'])) {
    $message = 'No legacy GIIT# IDs found — already in year format (or none to convert).';
    $messageType = 'success';
} else {
    $message = 'Done. Updated ' . (int)$result['updated'] . ' admission(s)'
        . ($result['skipped'] ? ('; · skipped ' . (int)$result['skipped'] . ' (conflict)') : '')
        . '. You can delete this page from the server when finished.';
    $messageType = ((int)$result['updated'] > 0 || (int)$result['skipped'] === 0) ? 'success' : 'info';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Migrate GIIT Reg IDs — Admin | Gyanam India</title>
<?php include __DIR__ . '/../includes/head_fonts.php'; ?>
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<link rel="stylesheet" href="../assets/css/notifications.css">
<style>
.mig-card {
    background: #fff; border: 1.5px solid #e5e7eb; border-radius: 14px;
    padding: 1.25rem 1.35rem; box-shadow: 0 1px 4px rgba(0,0,0,.03);
    max-width: 980px;
}
.mig-card h1 { margin: 0 0 .35rem; font-size: 1.2rem; font-weight: 800; color: #0f172a; }
.mig-card > .lead { margin: 0 0 1rem; color: #64748b; font-size: .88rem; line-height: 1.5; }
.mig-alert {
    padding: .7rem 1rem; border-radius: 8px; font-size: .85rem; font-weight: 600;
    margin-bottom: 1rem;
}
.mig-alert.info { background: #eff6ff; color: #1d4ed8; border: 1px solid #bfdbfe; }
.mig-alert.success { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
.mig-alert.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
.mig-actions { display: flex; flex-wrap: wrap; gap: .6rem; margin-bottom: 1rem; }
.mig-btn {
    height: 36px; padding: 0 1rem; border-radius: 8px; border: none;
    font-size: .8rem; font-weight: 700; cursor: pointer; text-decoration: none;
    display: inline-flex; align-items: center; font-family: inherit;
}
.mig-btn.ghost { background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; }
.mig-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
.mig-table th, .mig-table td { padding: .55rem .65rem; border-bottom: 1px solid #f1f5f9; text-align: left; vertical-align: top; }
.mig-table th { font-size: .68rem; text-transform: uppercase; letter-spacing: .04em; color: #94a3b8; }
.mig-mono { font-family: ui-monospace, Menlo, Consolas, monospace; font-weight: 700; }
.mig-old { color: #94a3b8; text-decoration: line-through; }
.mig-new { color: #059669; }
.mig-badge {
    display: inline-block; font-size: .65rem; font-weight: 800; padding: .15rem .45rem;
    border-radius: 99px; background: #f1f5f9; color: #475569; text-transform: uppercase;
}
.mig-badge.updated { background: #dbeafe; color: #1d4ed8; }
.mig-badge.conflict { background: #fef3c7; color: #b45309; }
.mig-note { margin-top: 1rem; font-size: .78rem; color: #64748b; line-height: 1.45; }
.empty-ok { margin: 0; font-weight: 700; color: #059669; }
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
    <header class="top-header">
        <div class="header-left">
            <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <div style="margin-left:1rem">
                <h1 style="font-size:1.1rem;font-weight:800;margin:0;line-height:1.2">Migrate GIIT Reg IDs</h1>
                <p style="font-size:.75rem;color:#94a3b8;margin:.15rem 0 0">GIIT1 → GIIT<?= (int)$year ?>1 (runs on open)</p>
            </div>
        </div>
        <div class="header-right">
            <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
            <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">
        <div class="mig-card">
            <h1>Legacy GIIT IDs → year format</h1>
            <p class="lead">
                Converts <span class="mig-mono">GIIT1</span>, <span class="mig-mono">GIIT2</span>…
                to <span class="mig-mono">GIIT<?= (int)$year ?>1</span>,
                <span class="mig-mono">GIIT<?= (int)$year ?>2</span>….
                Matching roll numbers are updated too. This ran automatically when you opened the page.
            </p>

            <div class="mig-alert <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>

            <div class="mig-actions">
                <a class="mig-btn ghost" href="migrate_giit_reg_ids.php">Run again</a>
                <a class="mig-btn ghost" href="students.php?search=GIIT">Open students (GIIT)</a>
            </div>

            <?php if ($result && empty($result['rows'])): ?>
                <p class="empty-ok">Nothing left to migrate.</p>
            <?php elseif ($result && !empty($result['rows'])): ?>
                <table class="mig-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Student</th>
                            <th>Registration</th>
                            <th>Roll</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($result['rows'] as $r): ?>
                        <tr>
                            <td><?= (int)$r['id'] ?></td>
                            <td>
                                <?= htmlspecialchars($r['name'] ?: '—') ?>
                                <?php if (($r['course'] ?? '') !== ''): ?>
                                    <div style="color:#94a3b8;font-size:.72rem"><?= htmlspecialchars($r['course']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="mig-mono mig-old"><?= htmlspecialchars($r['old']) ?></span>
                                →
                                <span class="mig-mono mig-new"><?= htmlspecialchars($r['new']) ?></span>
                            </td>
                            <td class="mig-mono">
                                <?= htmlspecialchars($r['roll_old'] ?: '—') ?>
                                <?php if (($r['roll_new'] ?? '') !== ($r['roll_old'] ?? '')): ?>
                                    → <span class="mig-new"><?= htmlspecialchars($r['roll_new']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td><span class="mig-badge <?= htmlspecialchars($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <p class="mig-note">
                After confirming Students shows <span class="mig-mono">GIIT<?= (int)$year ?>#</span> IDs,
                delete <span class="mig-mono">admin/migrate_giit_reg_ids.php</span> and remove the sidebar link.
                If exam-portal logins still use old IDs (e.g. GIIT1), update those identifiers to match.
            </p>
        </div>
    </div>
</main>
</div>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
