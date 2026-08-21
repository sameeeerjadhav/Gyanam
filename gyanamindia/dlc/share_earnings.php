<?php
/**
 * Gyanam Portal — DLC: Share Earnings (per-student view)
 * Shows DLC share due after ATCs under this DLC have paid HO share.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['DLC Office']);

$pdo     = getDBConnection();
$userName = sanitize(getUserName());
$dlcId   = (int)($_SESSION['dlc_id'] ?? 0);

ensureDualMaterialCourseSchema($pdo);

$summary = $dlcId > 0
    ? calculateDlcShareSummary($pdo, $dlcId)
    : ['due' => 0, 'paid' => 0, 'pending' => 0, 'student_count' => 0, 'students' => []];

$dlcName = '';
if ($dlcId) {
    try {
        $s = $pdo->prepare("SELECT name FROM dlc_offices WHERE id = ?");
        $s->execute([$dlcId]);
        $dlcName = (string)$s->fetchColumn();
    } catch (Exception $e) {}
}

// Paginate student list
$pager = paginationMeta(count($summary['students']), paginationParams(25));
$studentPage = array_slice($summary['students'], $pager['offset'], $pager['per_page']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Share Earnings — DLC | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💸</text></svg>">
    <style>
        .earn-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:1rem; margin-bottom:1.5rem; }
        .earn-card { background:#fff; border:1.5px solid #e5e7eb; border-radius:14px; padding:1.1rem 1.25rem; box-shadow:0 2px 8px rgba(0,0,0,.04); }
        .earn-label { font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; }
        .earn-val { font-size:1.55rem; font-weight:800; margin-top:.3rem; letter-spacing:-.02em; }
        .earn-val.due { color:#1f2937; }
        .earn-val.paid { color:#059669; }
        .earn-val.pend { color:#c2410c; }
        .info-note {
            background:linear-gradient(135deg,#eef2ff,#f5f3ff); border:1.5px solid #c7d2fe;
            border-radius:12px; padding:.85rem 1.1rem; font-size:.84rem; color:#3730a3; margin-bottom:1.25rem; line-height:1.5;
        }
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
                <div class="header-greeting">
                    <h2>Share Earnings</h2>
                    <p><?= htmlspecialchars($dlcName ?: 'DLC Office') ?> — per-student DLC share</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">
            <div class="info-note">
                DLC share is calculated <strong>per student</strong> from the course’s DLC share (With / Without Material).
                Students appear here only after their ATC has paid the <strong>HO share</strong> to Admin.
                Admin pays your DLC share separately (shown as Paid / Pending below).
            </div>

            <div class="earn-stats">
                <div class="earn-card">
                    <div class="earn-label">Students (HO paid)</div>
                    <div class="earn-val due"><?= (int)$summary['student_count'] ?></div>
                </div>
                <div class="earn-card">
                    <div class="earn-label">Total DLC Due</div>
                    <div class="earn-val due">₹<?= number_format((float)$summary['due'], 0) ?></div>
                </div>
                <div class="earn-card">
                    <div class="earn-label">Received from Admin</div>
                    <div class="earn-val paid">₹<?= number_format((float)$summary['paid'], 0) ?></div>
                </div>
                <div class="earn-card">
                    <div class="earn-label">Pending from Admin</div>
                    <div class="earn-val pend">₹<?= number_format((float)$summary['pending'], 0) ?></div>
                </div>
            </div>

            <div class="table-card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Roll / Reg ID</th>
                            <th>ATC</th>
                            <th>Course</th>
                            <th>Material</th>
                            <th>DLC Share (₹)</th>
                            <th>Admission Date</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($studentPage)): ?>
                        <tr>
                            <td colspan="8" class="table-empty">
                                <p>No share-paid students yet. Earnings appear when ATC centers under you pay HO share.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($studentPage as $i => $s): ?>
                        <tr>
                            <td><?= $pager['from'] + $i ?></td>
                            <td><div class="cell-name"><?= htmlspecialchars($s['student_name']) ?></div></td>
                            <td>
                                <?= htmlspecialchars($s['roll_no']) ?>
                                <?php if (!empty($s['registration_id'])): ?>
                                    <div class="cell-sub"><?= htmlspecialchars($s['registration_id']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($s['atc_name']) ?></td>
                            <td><?= htmlspecialchars($s['course']) ?></td>
                            <td><?= htmlspecialchars($s['material_type'] ?: '—') ?></td>
                            <td style="font-weight:800;color:#9a3412">₹<?= number_format((float)$s['dlc_share'], 0) ?></td>
                            <td><?= !empty($s['admission_date']) ? date('d M Y', strtotime($s['admission_date'])) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= renderPagination($pager, 'students') ?>
        </div>
    </main>
</div>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
