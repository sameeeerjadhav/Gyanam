<?php
/**
 * Gyanam Portal — Admin: Material Requirements
 * ATC-wise view of course materials needed for share-paid students.
 * Tracks pending vs completed (dispatched) batches.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo      = getDBConnection();
$userName = sanitize(getUserName());

// ── ATC list ──────────────────────────────────────────────────────────────────
$atcList = [];
try { $atcList = $pdo->query("SELECT id, name, atc_code FROM atc_centers WHERE status='Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC); } catch (Exception $e) {}

$filterAtc = isset($_GET['atc_id']) && $_GET['atc_id'] !== '' ? (int)$_GET['atc_id'] : 0;
$tab       = $_GET['tab'] ?? 'pending'; // 'pending' or 'completed'

$pendingStudents   = [];
$completedStudents = [];
$materialSummary   = []; // aggregated counts for pending

if ($filterAtc) {
    // ── 1. Get all "With Material" students for this ATC ──────────────────────
    try {
        $stmt = $pdo->prepare("
            SELECT a.id, a.roll_no, a.registration_id,
                   TRIM(CONCAT(a.first_name,' ',COALESCE(NULLIF(TRIM(a.middle_name),''),''),' ',a.last_name)) AS student_name,
                   a.course, a.uniform_size, a.material_language, a.material_type, a.admission_date,
                   COALESCE(a.ho_share_paid, 0) AS ho_share_paid
            FROM admissions a
            WHERE a.atc_id = ? AND a.status = 'Active' AND a.material_type = 'With Material'
            ORDER BY a.first_name ASC
        ");
        $stmt->execute([$filterAtc]);
        $allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // ho_share_paid column may not exist — retry without it
        $stmt = $pdo->prepare("
            SELECT a.id, a.roll_no, a.registration_id,
                   TRIM(CONCAT(a.first_name,' ',COALESCE(NULLIF(TRIM(a.middle_name),''),''),' ',a.last_name)) AS student_name,
                   a.course, a.uniform_size, a.material_language, a.material_type, a.admission_date,
                   0 AS ho_share_paid
            FROM admissions a
            WHERE a.atc_id = ? AND a.status = 'Active' AND a.material_type = 'With Material'
            ORDER BY a.first_name ASC
        ");
        $stmt->execute([$filterAtc]);
        $allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── 2. Build share-paid map ───────────────────────────────────────────────
    $paidMap = [];
    try {
        $spStmt = $pdo->prepare("SELECT student_ids FROM share_payments WHERE atc_id = ? AND status = 'Completed'");
        $spStmt->execute([$filterAtc]);
        foreach ($spStmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $ids = json_decode($json, true);
            if (is_array($ids)) foreach ($ids as $sid) $paidMap[(int)$sid] = true;
        }
    } catch (Exception $e) {}

    // ── 3. Build dispatched map (per-item only) ────────────────────────────────
    $partialDispatched  = []; // key => 'Dispatched' or 'Pending'
    $admIds = array_column($allStudents, 'id');
    if (!empty($admIds)) {
        $ph = implode(',', array_fill(0, count($admIds), '?'));
        // Check dispatch_items for per-item tracking
        try {
            $diStmt = $pdo->prepare("SELECT admission_id, item_type, item_detail, status FROM dispatch_items WHERE admission_id IN ($ph)");
            $diStmt->execute($admIds);
            foreach ($diStmt->fetchAll(PDO::FETCH_ASSOC) as $di) {
                $key = $di['admission_id'] . '_' . $di['item_type'] . '_' . $di['item_detail'];
                // Only mark as dispatched if explicitly 'Dispatched', NOT 'Pending'
                $partialDispatched[$key] = $di['status'];
            }
        } catch (Exception $e) {}
    }

    // ── 4. Categorize students ────────────────────────────────────────────────
    foreach ($allStudents as $s) {
        $isSharePaid = isset($paidMap[$s['id']]) || !empty($s['ho_share_paid']);
        if (!$isSharePaid) continue; // Only show share-paid students

        $materials     = [];
        $allDispatched = true;

        // T-Shirt
        if (!empty($s['uniform_size'])) {
            $tKey = $s['id'] . '_T-Shirt_Size ' . $s['uniform_size'];
            $status = $partialDispatched[$tKey] ?? null;
            // ONLY mark as dispatched if dispatch_items explicitly says 'Dispatched'
            if ($status === 'Dispatched') {
                $materials[] = ['type' => 'T-Shirt', 'detail' => 'Size ' . $s['uniform_size'], 'dispatched' => true];
            } else {
                $materials[] = ['type' => 'T-Shirt', 'detail' => 'Size ' . $s['uniform_size'], 'dispatched' => false];
                $allDispatched = false;
            }
        }

        // Book
        if (!empty($s['material_language'])) {
            $bKey = $s['id'] . '_Book_' . $s['material_language'];
            $status = $partialDispatched[$bKey] ?? null;
            if ($status === 'Dispatched') {
                $materials[] = ['type' => 'Book', 'detail' => $s['material_language'], 'dispatched' => true];
            } else {
                $materials[] = ['type' => 'Book', 'detail' => $s['material_language'], 'dispatched' => false];
                $allDispatched = false;
            }
        }

        // Certificate (every student gets one)
        $cKey = $s['id'] . '_Certificate_' . ($s['course'] ?? 'General');
        $certPartialStatus = $partialDispatched[$cKey] ?? null;
        if ($certPartialStatus === 'Dispatched') {
            $materials[] = ['type' => 'Certificate', 'detail' => $s['course'] ?? 'General', 'dispatched' => true];
        } else {
            $materials[] = ['type' => 'Certificate', 'detail' => $s['course'] ?? 'General', 'dispatched' => false];
            $allDispatched = false;
        }

        $s['materials'] = $materials;

        if ($allDispatched && !empty($materials)) {
            $completedStudents[] = $s;
        } elseif (!empty($materials)) {
            $pendingStudents[] = $s;
            // Count pending materials for summary
            foreach ($materials as $m) {
                if (!$m['dispatched']) {
                    $key = $m['type'] . ' — ' . $m['detail'];
                    $materialSummary[$key] = ($materialSummary[$key] ?? 0) + 1;
                }
            }
        }
    }
}

$pendingCount   = count($pendingStudents);
$completedCount = count($completedStudents);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Material Requirements — Admin | Gyanam India Educational Services</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<link rel="stylesheet" href="../assets/css/notifications.css">
<style>
:root { --font:'Sora',sans-serif; --mono:'JetBrains Mono',monospace }

/* ── Stats strip ── */
.mr-stats { display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem }
.mr-stat { background:#fff;border:1.5px solid var(--border-color);border-radius:14px;padding:1rem 1.2rem;display:flex;align-items:center;gap:.8rem }
.mr-stat-icon { width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0 }
.mr-stat-icon svg { width:18px;height:18px }
.mr-stat-icon.orange { background:#fff7ed;color:#f97316 }
.mr-stat-icon.green  { background:#ecfdf5;color:#10b981 }
.mr-stat-icon.blue   { background:#eff6ff;color:#3b82f6 }
.mr-stat-val { font-size:1.4rem;font-weight:900;color:var(--text-primary);line-height:1 }
.mr-stat-lbl { font-size:.7rem;font-weight:700;color:var(--text-secondary);margin-top:.15rem }

/* ── Filter ── */
.mr-filter { display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1.25rem;background:#fff;padding:1.1rem 1.25rem;border-radius:14px;border:1.5px solid var(--border-color) }
.mf-grp { display:flex;flex-direction:column;gap:.28rem }
.mf-grp label { font-size:.72rem;font-weight:700;color:var(--text-secondary) }
.mf-grp select { height:38px;padding:0 .75rem;border:1.5px solid var(--border-color);border-radius:8px;font-family:var(--font);font-size:.84rem;outline:none;background:#fff;min-width:220px }
.mf-grp select:focus { border-color:#6366f1 }
.btn-go { height:38px;padding:0 1.15rem;background:#6366f1;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-family:var(--font);white-space:nowrap }
.btn-go:hover { background:#4f46e5 }

/* ── Tabs ── */
.mr-tabs { display:flex;gap:.5rem;margin-bottom:1.25rem }
.mr-tab { padding:.55rem 1.1rem;border-radius:10px;border:1.5px solid var(--border-color);background:#fff;font:700 .82rem var(--font);color:var(--text-secondary);cursor:pointer;display:flex;align-items:center;gap:.4rem;transition:all .15s;text-decoration:none }
.mr-tab:hover { border-color:#a5b4fc;background:#eef2ff }
.mr-tab.active { background:linear-gradient(135deg,#6366f1,#4f46e5);border-color:#4f46e5;color:#fff;box-shadow:0 2px 8px rgba(99,102,241,.2) }
.mr-tab-count { padding:.12rem .45rem;border-radius:999px;font-size:.68rem;font-weight:800;min-width:18px;text-align:center }
.mr-tab.active .mr-tab-count { background:rgba(255,255,255,.2) }
.mr-tab:not(.active) .mr-tab-count { background:#e5e7eb;color:#6b7280 }

/* ── Material summary ── */
.mr-summary { background:#fff;border:1.5px solid var(--border-color);border-radius:14px;padding:1.1rem 1.25rem;margin-bottom:1.25rem }
.mr-summary-title { font-size:.78rem;font-weight:800;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.05em;margin-bottom:.65rem;display:flex;align-items:center;justify-content:space-between }
.mr-summary-grid { display:flex;flex-wrap:wrap;gap:.5rem }
.mr-sum-chip { display:inline-flex;align-items:center;gap:.4rem;padding:.4rem .85rem;border-radius:10px;font-size:.78rem;font-weight:700;border:1.5px solid var(--border-color);background:#fafbfc }
.mr-sum-chip .count { font-family:var(--mono);font-weight:900;color:#4361ee;font-size:.9rem }
.mr-sum-chip .label { color:var(--text-secondary) }

/* ── Print button ── */
.btn-print { display:inline-flex;align-items:center;gap:.4rem;padding:.55rem 1.1rem;border-radius:10px;border:1.5px solid #c4b5fd;background:#f5f3ff;color:#6d28d9;font:700 .8rem var(--font);cursor:pointer;transition:all .15s;white-space:nowrap }
.btn-print:hover { background:#ede9fe;transform:translateY(-1px);box-shadow:0 2px 8px rgba(109,40,217,.15) }

/* ── Table ── */
.mr-tbl-wrap { background:#fff;border:1.5px solid var(--border-color);border-radius:14px;overflow:hidden }
.mr-tbl { width:100%;border-collapse:collapse;font-size:.84rem }
.mr-tbl thead { background:#fafbfc }
.mr-tbl th { padding:.8rem 1rem;text-align:left;font-size:.68rem;font-weight:800;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.07em;border-bottom:1px solid var(--border-color);white-space:nowrap }
.mr-tbl tbody tr { border-bottom:1px solid #f3f4f6;transition:background .12s }
.mr-tbl tbody tr:hover { background:#f8faff }
.mr-tbl tbody tr:last-child { border-bottom:none }
.mr-tbl td { padding:.85rem 1rem;vertical-align:middle }

.mat-pills { display:flex;flex-wrap:wrap;gap:.4rem }
.mat-pill { display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .6rem;border-radius:6px;font-size:.72rem;font-weight:700 }
.mat-pill.book { background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe }
.mat-pill.tshirt { background:#fdf4ff;color:#a855f7;border:1px solid #e9d5ff }
.mat-pill.cert { background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7 }
.mat-pill.done { background:#ecfdf5;color:#065f46;border:1px solid #6ee7b7;text-decoration:line-through;opacity:.6 }

.empty-state { text-align:center;padding:3.5rem 2rem;color:var(--text-secondary) }
.empty-state svg { width:48px;height:48px;stroke:#d1d5db;display:block;margin:0 auto .75rem }
.empty-state .title { font-size:1rem;font-weight:800;margin-bottom:.25rem }
.empty-state .sub { font-size:.82rem;color:#9ca3af }

/* ── Print styles ── */
@media print {
    /* Hide everything UI related */
    .sidebar, .sidebar-overlay, .top-header, .mr-filter, .mr-tabs,
    .mr-stats, .btn-print, .hamburger, .notification-bell,
    .profile-dropdown, .header-right, .header-left { display:none!important }

    /* Layout reset */
    body { background:#fff!important; font-family:'Sora',Arial,sans-serif!important }
    .dashboard-layout { display:block!important }
    .main-content { margin:0!important; padding:0!important; width:100%!important; box-shadow:none!important }
    .page-content { padding:0!important }

    /* Hide screen versions */
    .mr-tbl-wrap, .mr-summary { display:none!important }

    /* Show print version */
    .print-doc { display:block!important }

    /* Misc */
    @page { size: A4 portrait; margin: 12mm 14mm; }
    * { -webkit-print-color-adjust: exact!important; print-color-adjust: exact!important; }
}
/* Print document (only visible on print) */
.print-doc { display:none }

/* Print document styles — only matter during print */
.pd-page { font-family:'Sora',Arial,sans-serif; color:#111; font-size:9.5pt }
.pd-header { display:flex; align-items:stretch; gap:0; margin-bottom:14pt; border-radius:0; overflow:hidden; border:1.5pt solid #1a3a8f }
.pd-header-blue { background:linear-gradient(135deg,#1a3a8f 0%,#3b63d9 100%); padding:12pt 16pt; display:flex; flex-direction:column; justify-content:center; min-width:140pt }
.pd-header-blue .org { font-size:13pt; font-weight:900; color:#fff; letter-spacing:-.03em; line-height:1.1 }
.pd-header-blue .tagline { font-size:7.5pt; color:rgba(255,255,255,.75); margin-top:3pt }
.pd-header-right { flex:1; padding:10pt 14pt; background:#f8faff; display:flex; flex-direction:column; justify-content:space-between }
.pd-header-right .doc-title { font-size:11pt; font-weight:800; color:#1a3a8f }
.pd-header-right .doc-sub { font-size:7.5pt; color:#6b7280; margin-top:2pt }
.pd-meta { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8pt; margin-top:6pt }
.pd-meta-item .lbl { font-size:6.5pt; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:#9ca3af }
.pd-meta-item .val { font-size:8.5pt; font-weight:700; color:#111; margin-top:1pt }

/* Summary table */
.pd-section-title { font-size:8pt; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:#1a3a8f; margin:12pt 0 5pt; padding-bottom:3pt; border-bottom:1.5pt solid #3b63d9; display:flex; align-items:center; gap:5pt }
.pd-sum-tbl { width:100%; border-collapse:collapse; font-size:8.5pt; margin-bottom:12pt }
.pd-sum-tbl th { background:#1a3a8f; color:#fff; padding:5pt 8pt; text-align:left; font-size:7pt; font-weight:700; letter-spacing:.05em; text-transform:uppercase }
.pd-sum-tbl td { padding:5pt 8pt; border-bottom:1pt solid #e5e7eb }
.pd-sum-tbl tr:last-child td { border-bottom:none }
.pd-sum-tbl tr:nth-child(even) td { background:#f8fafc }
.pd-sum-tbl .total-row td { background:#1a3a8f!important; color:#fff!important; font-weight:800 }

/* Student table */
.pd-stu-tbl { width:100%; border-collapse:collapse; font-size:8pt }
.pd-stu-tbl th { background:#1a3a8f; color:#fff; padding:5pt 6pt; text-align:left; font-size:6.5pt; font-weight:700; text-transform:uppercase; letter-spacing:.05em; white-space:nowrap }
.pd-stu-tbl td { padding:5.5pt 6pt; border-bottom:.75pt solid #e5e7eb; vertical-align:middle }
.pd-stu-tbl tr:last-child td { border-bottom:none }
.pd-stu-tbl tr:nth-child(even) td { background:#f8fafc }
.pd-stu-tbl .pill { display:inline-block; padding:1.5pt 5pt; border-radius:4pt; font-weight:700; font-size:7pt }
.pd-stu-tbl .pill-book { background:#dbeafe; color:#1e40af }
.pd-stu-tbl .pill-tshirt { background:#f3e8ff; color:#7c3aed }
.pd-footer { margin-top:14pt; display:flex; justify-content:space-between; align-items:flex-end; border-top:1pt solid #e5e7eb; padding-top:8pt }
.pd-footer .sign-box { display:flex; flex-direction:column; align-items:center; gap:4pt }
.pd-footer .sign-line { width:80pt; border-top:1pt solid #374151 }
.pd-footer .sign-lbl { font-size:7pt; font-weight:700; color:#374151 }
.pd-footer .sign-sub { font-size:6pt; color:#9ca3af }
.pd-footer-center { text-align:center; font-size:6.5pt; color:#9ca3af; line-height:1.6 }

@media (max-width:768px) {
    .mr-filter { flex-direction:column;align-items:stretch }
    .mr-stats { grid-template-columns:1fr 1fr }
}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include __DIR__ . '/sidebar.php'; ?>

<main class="main-content">
    <header class="top-header">
        <div class="header-left">
            <button class="hamburger" id="hamburgerBtn"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
            <div class="header-greeting">
                <h2>📋 Material Requirements</h2>
                <p>ATC-wise course material needs for share-paid students</p>
            </div>
        </div>
        <div class="header-right">
            <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
            <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">

        <!-- Professional Print Document (hidden on screen, shown on print) -->
        <?php
        $selAtcName = '';
        $selAtcCode = '';
        foreach ($atcList as $a) {
            if ($a['id'] == $filterAtc) {
                $selAtcName = $a['name'];
                $selAtcCode = $a['atc_code'] ?? '';
                break;
            }
        }
        ?>
        <div class="print-doc">
        <?php if ($filterAtc && !empty($pendingStudents)): ?>
        <div class="pd-page">

            <!-- A4 Header -->
            <div class="pd-header">
                <div class="pd-header-blue">
                    <?php if (file_exists(__DIR__ . '/../assets/logo.png')): ?>
                    <img src="../assets/logo.png" style="height:32pt;width:auto;margin-bottom:6pt;object-fit:contain" alt="Logo">
                    <?php endif; ?>
                    <div class="org">Gyanam India<br>Educational Services</div>
                    <div class="tagline">Material Requirements Report</div>
                </div>
                <div class="pd-header-right">
                    <div>
                        <div class="doc-title">Course Material Requirements</div>
                        <div class="doc-sub">ATC-wise material needs for share-paid students</div>
                    </div>
                    <div class="pd-meta">
                        <div class="pd-meta-item">
                            <div class="lbl">ATC Center</div>
                            <div class="val"><?= htmlspecialchars($selAtcName) ?></div>
                        </div>
                        <div class="pd-meta-item">
                            <div class="lbl">ATC Code</div>
                            <div class="val"><?= $selAtcCode ? htmlspecialchars($selAtcCode) : '—' ?></div>
                        </div>
                        <div class="pd-meta-item">
                            <div class="lbl">Generated</div>
                            <div class="val"><?= date('d M Y, h:i A') ?></div>
                        </div>
                        <div class="pd-meta-item">
                            <div class="lbl">Pending Students</div>
                            <div class="val"><?= $pendingCount ?></div>
                        </div>
                        <div class="pd-meta-item">
                            <div class="lbl">Material Types</div>
                            <div class="val"><?= count($materialSummary) ?></div>
                        </div>
                        <div class="pd-meta-item">
                            <div class="lbl">Prepared By</div>
                            <div class="val"><?= htmlspecialchars($userName) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Material Summary Table -->
            <?php if (!empty($materialSummary)): ?>
            <div class="pd-section-title">📊 Material Summary — Total Units Required</div>
            <table class="pd-sum-tbl">
                <thead>
                    <tr><th>#</th><th>Material Type</th><th>Description</th><th style="text-align:right">Quantity Needed</th></tr>
                </thead>
                <tbody>
                <?php $n=0; foreach ($materialSummary as $label => $count):
                    $n++;
                    $parts = explode(' — ', $label, 2);
                    $mtype = $parts[0] ?? $label;
                    $mdetail = $parts[1] ?? '';
                ?>
                <tr>
                    <td style="color:#9ca3af;font-weight:700"><?= $n ?></td>
                    <td><strong><?= htmlspecialchars($mtype) ?></strong></td>
                    <td style="color:#6b7280"><?= htmlspecialchars($mdetail) ?></td>
                    <td style="text-align:right;font-weight:800;font-size:10pt;color:#1a3a8f"><?= $count ?> pcs</td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="3" style="text-align:right">TOTAL UNITS</td>
                    <td style="text-align:right"><?= array_sum($materialSummary) ?> pcs</td>
                </tr>
                </tbody>
            </table>
            <?php endif; ?>

            <!-- Student Detail Table -->
            <div class="pd-section-title">👥 Student-wise Material Requirements</div>
            <table class="pd-stu-tbl">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student Name</th>
                        <th>Registration ID</th>
                        <th>Roll No</th>
                        <th>Course</th>
                        <th>Book Language</th>
                        <th>T-Shirt Size</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingStudents as $idx => $s): ?>
                <tr>
                    <td style="color:#9ca3af;font-weight:700;text-align:center"><?= $idx + 1 ?></td>
                    <td><strong><?= htmlspecialchars($s['student_name']) ?></strong></td>
                    <td style="font-family:monospace;font-size:7.5pt"><?= htmlspecialchars($s['registration_id'] ?: 'GYANAM'.$s['id']) ?></td>
                    <td style="font-family:monospace;font-size:7.5pt;color:#6b7280"><?= htmlspecialchars($s['roll_no'] ?? '—') ?></td>
                    <td style="color:#6b7280"><?= htmlspecialchars($s['course'] ?? '—') ?></td>
                    <td>
                        <?php if (!empty($s['materials'])): ?>
                        <?php foreach ($s['materials'] as $m): ?>
                        <?php if (!$m['dispatched'] && $m['type'] === 'Book'): ?>
                        <span class="pill pill-book"><?= htmlspecialchars($m['detail']) ?></span>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($s['materials'])): ?>
                        <?php foreach ($s['materials'] as $m): ?>
                        <?php if (!$m['dispatched'] && $m['type'] === 'T-Shirt'): ?>
                        <span class="pill pill-tshirt"><?= htmlspecialchars($m['detail']) ?></span>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Footer -->
            <div class="pd-footer">
                <div class="sign-box">
                    <div class="sign-line"></div>
                    <div class="sign-lbl">Verified By</div>
                    <div class="sign-sub">Head Office — Gyanam India</div>
                </div>
                <div class="pd-footer-center">
                    <strong>Gyanam India Educational Services</strong><br>
                    Course Material Requirements Report · <?= htmlspecialchars($selAtcName) ?><br>
                    Generated: <?= date('d M Y h:i A') ?> · This is a computer-generated report
                </div>
                <div class="sign-box">
                    <div class="sign-line"></div>
                    <div class="sign-lbl">Received By</div>
                    <div class="sign-sub"><?= htmlspecialchars($selAtcName) ?></div>
                </div>
            </div>

        </div>
        <?php else: ?>
        <div style="text-align:center;padding:2rem;font-family:Arial,sans-serif;color:#6b7280">
            Select an ATC and ensure there are pending students to print the report.
        </div>
        <?php endif; ?>
        </div>

        <!-- Filter -->
        <form method="GET" class="mr-filter">
            <div class="mf-grp">
                <label>ATC Center</label>
                <select name="atc_id">
                    <option value="">— Select ATC Center —</option>
                    <?php foreach ($atcList as $a): ?>
                    <option value="<?= $a['id'] ?>" <?= $filterAtc == $a['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['name']) ?><?= $a['atc_code'] ? ' (' . htmlspecialchars($a['atc_code']) . ')' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
            <button type="submit" class="btn-go">🔍 Load Materials</button>
        </form>

        <?php if ($filterAtc): ?>

        <!-- Stats -->
        <div class="mr-stats">
            <div class="mr-stat">
                <div class="mr-stat-icon orange"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg></div>
                <div><div class="mr-stat-val"><?= $pendingCount ?></div><div class="mr-stat-lbl">Pending Students</div></div>
            </div>
            <div class="mr-stat">
                <div class="mr-stat-icon green"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div>
                <div><div class="mr-stat-val"><?= $completedCount ?></div><div class="mr-stat-lbl">Completed</div></div>
            </div>
            <div class="mr-stat">
                <div class="mr-stat-icon blue"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg></div>
                <div><div class="mr-stat-val"><?= count($materialSummary) ?></div><div class="mr-stat-lbl">Material Types Needed</div></div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="mr-tabs">
            <a href="?atc_id=<?= $filterAtc ?>&tab=pending" class="mr-tab <?= $tab === 'pending' ? 'active' : '' ?>">
                ⏳ Pending Materials <span class="mr-tab-count"><?= $pendingCount ?></span>
            </a>
            <a href="?atc_id=<?= $filterAtc ?>&tab=completed" class="mr-tab <?= $tab === 'completed' ? 'active' : '' ?>">
                ✅ Completed <span class="mr-tab-count"><?= $completedCount ?></span>
            </a>
        </div>

        <?php if ($tab === 'pending'): ?>

        <?php if (!empty($materialSummary)): ?>
        <!-- Material Summary (Aggregate counts) -->
        <div class="mr-summary">
            <div class="mr-summary-title">
                <span>📊 Material Summary — Total Items Needed</span>
                <button type="button" class="btn-print" onclick="window.print()">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Print Report
                </button>
            </div>
            <div class="mr-summary-grid">
                <?php foreach ($materialSummary as $label => $count): ?>
                <div class="mr-sum-chip">
                    <span class="count"><?= $count ?></span>
                    <span class="label">× <?= htmlspecialchars($label) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Pending Students Table -->
        <div class="mr-tbl-wrap">
            <?php if (empty($pendingStudents)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="1.5"><polyline points="20 6 9 17 4 12"/></svg>
                <div class="title">All materials dispatched!</div>
                <div class="sub">No pending material requirements for this ATC. New students will appear here after their share is paid.</div>
            </div>
            <?php else: ?>
            <table class="mr-tbl">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>Registration ID</th>
                        <th>Course</th>
                        <th>Admission Date</th>
                        <th>Materials Needed</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($pendingStudents as $idx => $s): ?>
                <tr>
                    <td style="color:#9ca3af;font-size:.75rem"><?= $idx + 1 ?></td>
                    <td>
                        <div style="font-weight:800;font-size:.88rem"><?= htmlspecialchars($s['student_name']) ?></div>
                        <div style="font-size:.7rem;color:#9ca3af"><?= htmlspecialchars($s['roll_no'] ?? '') ?></div>
                    </td>
                    <td><code style="font-size:.78rem;background:#f3f4f6;padding:.15rem .4rem;border-radius:4px"><?= htmlspecialchars($s['registration_id'] ?: 'GYANAM' . $s['id']) ?></code></td>
                    <td style="font-weight:700;font-size:.82rem;color:#4361ee"><?= htmlspecialchars($s['course'] ?? '—') ?></td>
                    <td style="font-size:.82rem;color:#6b7280"><?= $s['admission_date'] ? date('d M Y', strtotime($s['admission_date'])) : '—' ?></td>
                    <td>
                        <div class="mat-pills">
                            <?php foreach ($s['materials'] as $m): ?>
                            <?php if (!$m['dispatched']): ?>
                            <span class="mat-pill <?= $m['type'] === 'T-Shirt' ? 'tshirt' : ($m['type'] === 'Certificate' ? 'cert' : 'book') ?>">
                                <?= $m['type'] === 'T-Shirt' ? '👕' : ($m['type'] === 'Certificate' ? '📜' : '📚') ?> <?= htmlspecialchars($m['detail']) ?>
                            </span>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- Completed Students Table -->
        <div class="mr-tbl-wrap">
            <?php if (empty($completedStudents)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l3 3"/></svg>
                <div class="title">No completed dispatches yet</div>
                <div class="sub">Once materials are dispatched for students, they will appear here.</div>
            </div>
            <?php else: ?>
            <table class="mr-tbl">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>Registration ID</th>
                        <th>Course</th>
                        <th>Materials Sent</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($completedStudents as $idx => $s): ?>
                <tr>
                    <td style="color:#9ca3af;font-size:.75rem"><?= $idx + 1 ?></td>
                    <td>
                        <div style="font-weight:800;font-size:.88rem"><?= htmlspecialchars($s['student_name']) ?></div>
                        <div style="font-size:.7rem;color:#9ca3af"><?= htmlspecialchars($s['roll_no'] ?? '') ?></div>
                    </td>
                    <td><code style="font-size:.78rem;background:#f3f4f6;padding:.15rem .4rem;border-radius:4px"><?= htmlspecialchars($s['registration_id'] ?: 'GYANAM' . $s['id']) ?></code></td>
                    <td style="font-weight:700;font-size:.82rem;color:#4361ee"><?= htmlspecialchars($s['course'] ?? '—') ?></td>
                    <td>
                        <div class="mat-pills">
                            <?php foreach ($s['materials'] as $m): ?>
                            <span class="mat-pill done">
                                <?= $m['type'] === 'T-Shirt' ? '👕' : ($m['type'] === 'Certificate' ? '📜' : '📚') ?> <?= htmlspecialchars($m['detail']) ?> ✓
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <?php endif; ?>

        <?php elseif (!$filterAtc): ?>
        <div class="mr-tbl-wrap">
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="1.5"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                <div class="title">Select an ATC Center</div>
                <div class="sub">Choose an ATC from the dropdown above to view material requirements.</div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</main>
</div>

<script src="../assets/js/dashboard.js"></script>
</body>
</html>
