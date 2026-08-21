<?php
/**
 * Gyanam Portal — ATC: Course Completion Certificates
 * Certificate is only generatable when:
 *   1. Student photo is uploaded (admissions.photo != '')
 *   2. HO share has been paid (share_payments, status=Completed, student in JSON array)
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo      = getDBConnection();
$userName = sanitize(getUserName());
$atcId    = $_SESSION['atc_id'] ?? null;

// ATC details
$atcDetails = [];
try {
    $s = $pdo->prepare("SELECT * FROM atc_centers WHERE id = ?");
    $s->execute([$atcId]);
    $atcDetails = $s->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {}

// Fetch all active students with has_photo, share_paid, and exam_passed flags
$students = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            a.id, a.roll_no, a.registration_id,
            a.first_name, a.middle_name, a.last_name,
            a.course, a.photo, a.mobile, a.created_at,
            CASE WHEN a.photo IS NOT NULL AND TRIM(a.photo) != '' THEN 1 ELSE 0 END AS has_photo,
            (
                SELECT COUNT(*) FROM share_payments sp
                WHERE sp.atc_id = a.atc_id
                  AND sp.status = 'Completed'
                  AND JSON_CONTAINS(sp.student_ids, CAST(a.id AS JSON), '$')
            ) AS share_paid,
            COALESCE(es.exam_status, '') AS exam_status
        FROM admissions a
        LEFT JOIN exam_schedules es ON es.admission_id = a.id AND es.atc_id = a.atc_id
        WHERE a.atc_id = ? AND a.status = 'Active'
        ORDER BY a.roll_no ASC
    ");
    $stmt->execute([$atcId]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Annotate exam_passed flag
foreach ($students as &$_s) {
    $_s['exam_passed'] = ($_s['exam_status'] === 'Passed') ? 1 : 0;
}
unset($_s);

// Stats
$totalStudents    = count($students);
$readyCount       = count(array_filter($students, fn($s) => $s['has_photo'] && $s['share_paid'] && $s['exam_passed']));
$noPhotoCount     = count(array_filter($students, fn($s) => !$s['has_photo']));
$unpaidCount      = count(array_filter($students, fn($s) => !$s['share_paid']));
$examPassedCount  = count(array_filter($students, fn($s) => $s['exam_passed']));
$examNotPassed    = $totalStudents - $examPassedCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Completion Certificate — ATC Center | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎓</text></svg>">
<style>
:root {
    --brand:#4361ee; --brand-dk:#3451d1; --brand-soft:#eef1fd; --brand-glow:rgba(67,97,238,.18);
    --violet:#7c3aed; --violet-soft:#f5f3ff;
    --emerald:#10b981; --emerald-dk:#059669; --emerald-soft:#ecfdf5;
    --amber:#f59e0b; --amber-dk:#d97706; --amber-soft:#fffbeb;
    --rose:#f43f5e; --rose-soft:#fff1f3;
    --sky:#0ea5e9; --sky-soft:#f0f9ff;
    --mono:'JetBrains Mono',monospace;
    --font:'Sora',sans-serif;
    --r-sm:6px; --r-md:10px; --r-lg:14px; --r-xl:18px; --r-2xl:24px; --r-full:9999px;
    --sh-sm:0 1px 4px rgba(0,0,0,.06),0 2px 8px rgba(0,0,0,.04);
    --sh-md:0 4px 16px rgba(0,0,0,.08);
    --sh-lg:0 20px 60px rgba(0,0,0,.14),0 8px 20px rgba(0,0,0,.06);
}
.cc-wrap { padding:1.75rem 2rem; width:100%; box-sizing:border-box; }


/* Page header */
.cc-page-header { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1.75rem; }
.cc-page-left   { display:flex; align-items:center; gap:1rem; }
.cc-page-icon   { width:50px; height:50px; border-radius:var(--r-lg); background:linear-gradient(135deg,var(--brand),var(--violet)); display:flex; align-items:center; justify-content:center; box-shadow:0 6px 20px var(--brand-glow); flex-shrink:0; }
.cc-page-icon svg { width:24px; height:24px; stroke:white; fill:none; }
.cc-page-title  { font-size:1.375rem; font-weight:800; color:var(--text-primary); letter-spacing:-.03em; }
.cc-page-sub    { font-size:.8125rem; color:var(--text-secondary); margin-top:.2rem; }

/* KPI Cards */
.cc-kpi-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:1rem; margin-bottom:1.75rem; width:100%; }
@media(max-width:600px){.cc-kpi-grid{grid-template-columns:1fr 1fr}}
@media(max-width:400px){.cc-kpi-grid{grid-template-columns:1fr}}
.cc-kpi { background:#fff; border:1px solid var(--border-color); border-radius:var(--r-xl); padding:1.25rem 1.5rem; position:relative; overflow:hidden; box-shadow:var(--sh-sm); border-left:4px solid transparent; }
.cc-kpi.brand  { border-left-color:var(--brand); }
.cc-kpi.green  { border-left-color:var(--emerald); }
.cc-kpi.amber  { border-left-color:var(--amber); }
.cc-kpi.rose   { border-left-color:var(--rose); }
.cc-kpi-label  { font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; color:var(--text-secondary); margin-bottom:.5rem; }
.cc-kpi-value  { font-size:2rem; font-weight:800; color:var(--text-primary); line-height:1; letter-spacing:-.04em; }

/* Toolbar */
.cc-toolbar { display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:1rem; flex-wrap:wrap; }
.cc-toolbar-title { font-size:1rem; font-weight:700; color:var(--text-primary); display:flex; align-items:center; gap:.5rem; }
.cc-count-badge { font-size:.72rem; font-weight:700; background:var(--surface-color); border:1px solid var(--border-color); color:var(--text-secondary); padding:.175rem .6rem; border-radius:var(--r-full); }
.cc-search    { position:relative; display:flex; align-items:center; }
.cc-search svg { position:absolute; left:.875rem; width:15px; height:15px; stroke:var(--text-secondary); fill:none; pointer-events:none; }
.cc-search input { padding:.65rem .875rem .65rem 2.4rem; border:1.5px solid var(--border-color); border-radius:var(--r-md); font-size:.85rem; font-family:var(--font); background:#fff; color:var(--text-primary); outline:none; width:230px; transition:border-color .2s,box-shadow .2s; }
.cc-search input:focus { border-color:var(--brand); box-shadow:0 0 0 3px var(--brand-glow); }

/* Table */
.cc-table-wrap { background:#fff; border:1px solid var(--border-color); border-radius:var(--r-xl); box-shadow:var(--sh-sm); overflow:hidden; }
.cc-table { width:100%; border-collapse:collapse; font-size:.875rem; }
.cc-table thead { background:#fafbfc; border-bottom:1px solid var(--border-color); }
.cc-table thead th { padding:.875rem 1.25rem; text-align:left; font-size:.7rem; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:.07em; white-space:nowrap; }
.cc-table tbody tr { border-bottom:1px solid #f3f5f9; transition:background .12s; }
.cc-table tbody tr:last-child { border-bottom:none; }
.cc-table tbody tr:hover { background:#fafbff; }
.cc-table tbody td { padding:.9rem 1.25rem; vertical-align:middle; }

/* Student cell */
.cc-stu-cell  { display:flex; align-items:center; gap:.75rem; }
.cc-stu-photo { width:38px; height:38px; border-radius:var(--r-md); object-fit:cover; border:1.5px solid var(--border-color); flex-shrink:0; background:#f3f5f9; }
.cc-stu-initials { width:38px; height:38px; border-radius:var(--r-md); background:linear-gradient(135deg,var(--brand),var(--violet)); color:#fff; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:.875rem; flex-shrink:0; }
.cc-stu-name  { font-weight:700; font-size:.875rem; color:var(--text-primary); }
.cc-stu-roll  { font-size:.75rem; color:var(--text-secondary); font-family:var(--mono); margin-top:.1rem; }

/* Status chips */
.cc-chip { display:inline-flex; align-items:center; gap:.35rem; padding:.28rem .75rem; border-radius:var(--r-full); font-size:.7rem; font-weight:700; white-space:nowrap; }
.cc-chip-dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; background:currentColor; }
.chip-ready    { background:var(--emerald-soft); color:var(--emerald-dk); border:1px solid #a7f3d0; }
.chip-nophoto  { background:var(--amber-soft); color:var(--amber-dk); border:1px solid #fde68a; }
.chip-unpaid   { background:var(--rose-soft); color:#be123c; border:1px solid #fecdd3; }
.chip-blocked  { background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0; }

/* Action buttons */
.btn-generate {
    display:inline-flex; align-items:center; gap:.4rem;
    padding:.55rem 1.1rem; border-radius:var(--r-md);
    font-size:.8rem; font-weight:700; font-family:var(--font);
    cursor:pointer; border:none; transition:all .18s;
    background:linear-gradient(135deg,var(--brand),var(--brand-dk));
    color:#fff; box-shadow:0 3px 10px var(--brand-glow);
}
.btn-generate:hover { transform:translateY(-1px); box-shadow:0 6px 16px var(--brand-glow); }
.btn-generate:disabled { opacity:.4; cursor:not-allowed; transform:none; box-shadow:none; background:#e2e8f0; color:#94a3b8; }
.btn-generate svg { width:13px; height:13px; }

/* Modal */
.cert-overlay { position:fixed; inset:0; background:rgba(8,12,28,.55); backdrop-filter:blur(6px); z-index:1000; display:none; align-items:center; justify-content:center; padding:1.25rem; font-family:var(--font); }
.cert-overlay.open { display:flex; }
.cert-modal { background:#fff; border-radius:var(--r-2xl); box-shadow:var(--sh-lg); width:100%; max-width:820px; max-height:92vh; display:flex; flex-direction:column; animation:ccSlideUp .28s cubic-bezier(.34,1.56,.64,1); overflow:hidden; }
@keyframes ccSlideUp { from{opacity:0;transform:scale(.92) translateY(16px)} to{opacity:1;transform:scale(1) translateY(0)} }
.cert-modal-head { padding:1rem 1.5rem; display:flex; align-items:center; justify-content:space-between; border-bottom:1px solid var(--border-color); background:#fafbfc; flex-shrink:0; }
.cert-modal-head-title { font-size:.95rem; font-weight:800; color:var(--text-primary); }
.cert-modal-head-sub   { font-size:.75rem; color:var(--text-secondary); margin-top:.1rem; }
.cert-modal-actions { display:flex; align-items:center; gap:.75rem; }
.cert-btn-print  { display:inline-flex; align-items:center; gap:.4rem; padding:.55rem 1.1rem; border-radius:var(--r-md); background:linear-gradient(135deg,var(--emerald),var(--emerald-dk)); color:#fff; font-size:.8rem; font-weight:700; font-family:var(--font); border:none; cursor:pointer; box-shadow:0 3px 10px rgba(16,185,129,.25); transition:all .18s; }
.cert-btn-print:hover { transform:translateY(-1px); box-shadow:0 6px 16px rgba(16,185,129,.35); }
.cert-btn-pdf    { display:inline-flex; align-items:center; gap:.4rem; padding:.55rem 1.1rem; border-radius:var(--r-md); background:linear-gradient(135deg,var(--brand),var(--brand-dk)); color:#fff; font-size:.8rem; font-weight:700; font-family:var(--font); border:none; cursor:pointer; box-shadow:0 3px 10px var(--brand-glow); transition:all .18s; }
.cert-btn-pdf:hover { transform:translateY(-1px); box-shadow:0 6px 16px var(--brand-glow); }
.cert-btn-close  { width:34px; height:34px; display:flex; align-items:center; justify-content:center; background:transparent; border:1.5px solid var(--border-color); border-radius:var(--r-md); cursor:pointer; transition:all .18s; }
.cert-btn-close:hover { background:var(--rose-soft); border-color:#fca5a5; }
.cert-btn-close svg { width:14px; height:14px; stroke:var(--text-secondary); fill:none; }
.cert-btn-close:hover svg { stroke:var(--rose); }
.cert-btn-print svg,.cert-btn-pdf svg { width:13px; height:13px; }
.cert-modal-body { padding:1.5rem; overflow-y:auto; flex:1; }

/* ═══ CERTIFICATE DESIGN ═══ */
.certificate {
    border:4px solid var(--brand);
    border-radius:12px;
    padding:2rem;
    font-family:Arial,Helvetica,sans-serif;
    position:relative;
    background:#fff;
    box-shadow:inset 0 0 0 6px #fff, inset 0 0 0 8px var(--brand-soft);
}
/* Decorative corner accents */
.certificate::before,.certificate::after {
    content:'';
    position:absolute;
    width:60px; height:60px;
    border:3px solid var(--violet);
    border-radius:4px;
    opacity:.35;
}
.certificate::before { top:12px; left:12px; border-right:none; border-bottom:none; }
.certificate::after  { bottom:12px; right:12px; border-left:none; border-top:none; }

.cert-head { display:flex; align-items:center; justify-content:space-between; padding-bottom:1.25rem; border-bottom:3px double var(--brand); margin-bottom:1.25rem; gap:1rem; }
.cert-logo { width:80px; height:80px; flex-shrink:0; }
.cert-logo img { width:100%; height:100%; object-fit:contain; }
.cert-org  { flex:1; text-align:center; }
.cert-org h1 { font-size:20px; font-weight:800; color:var(--brand); letter-spacing:.5px; margin:0 0 4px; text-transform:uppercase; }
.cert-org h2 { font-size:13px; color:var(--violet); font-weight:700; margin:0 0 2px; text-transform:uppercase; letter-spacing:.3px; }
.cert-org p  { font-size:11px; color:#6b7280; margin:0; }
.cert-student-photo { width:100px; height:120px; border:2px solid var(--brand); object-fit:cover; border-radius:4px; flex-shrink:0; }
.cert-student-photo-placeholder { width:100px; height:120px; border:2px dashed #cbd5e1; border-radius:4px; display:flex; align-items:center; justify-content:center; font-size:10px; color:#9ca3af; text-align:center; flex-shrink:0; }

.cert-body { text-align:center; padding:1rem 0 1.25rem; }
.cert-body .cert-certify-text { font-size:13px; color:#6b7280; margin:0 0 .5rem; font-style:italic; }
.cert-body .cert-student-name { font-family:'Playfair Display', Georgia, serif; font-size:32px; font-weight:700; color:var(--brand); letter-spacing:.5px; margin:.3rem 0; line-height:1.2; }
.cert-body .cert-completed-text { font-size:13px; color:#374151; margin:.5rem 0; }
.cert-body .cert-course-name { font-size:20px; font-weight:800; color:var(--violet); margin:.25rem 0 1rem; text-transform:uppercase; letter-spacing:.3px; }

/* Details bar */
.cert-details-bar { display:flex; flex-wrap:wrap; justify-content:center; gap:.5rem 1.25rem; background:var(--brand-soft); border-radius:8px; padding:.75rem 1rem; margin-bottom:1.25rem; font-size:11.5px; color:#374151; }
.cert-details-bar strong { color:var(--brand); }

/* Divider */
.cert-divider { border:none; border-top:1.5px dashed #e5e7eb; margin:1.25rem 0; }

/* Signatures */
.cert-sigs { display:flex; justify-content:space-between; gap:1rem; margin-top:.25rem; }
.cert-sig-box { flex:1; text-align:center; }
.cert-sig-line { width:130px; height:50px; border-bottom:2px solid #374151; margin:0 auto 6px; }
.cert-sig-label { font-size:10px; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.4px; }
.cert-sig-title { font-size:9px; color:#9ca3af; margin-top:2px; }

/* Footer note */
.cert-foot { text-align:center; font-size:9.5px; color:#9ca3af; margin-top:1rem; line-height:1.6; }

/* Empty state */
.cc-empty { text-align:center; padding:4rem 2rem; }
.cc-empty svg { width:48px; height:48px; stroke:#d1d5db; fill:none; display:block; margin:0 auto .75rem; }
.cc-empty-title { font-size:.9375rem; font-weight:700; color:var(--text-primary); }
.cc-empty-sub   { font-size:.8125rem; color:var(--text-secondary); margin-top:.25rem; }

/* Tooltip */
[title] { position:relative; }
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
                <h2>Completion Certificates</h2>
                <p>Generate course completion certificates for eligible students</p>
            </div>
        </div>
        <div class="header-right">
            <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
            <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="cc-wrap">

        <!-- Page header -->
        <div class="cc-page-header">
            <div class="cc-page-left">
                <div class="cc-page-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                </div>
                <div>
                    <div class="cc-page-title">Course Completion Certificates</div>
                    <div class="cc-page-sub">Certificates are available only after photo is uploaded &amp; HO share is paid</div>
                </div>
            </div>
        </div>

        <!-- KPI Cards -->
        <div class="cc-kpi-grid">
            <div class="cc-kpi brand">
                <div class="cc-kpi-label">Total Students</div>
                <div class="cc-kpi-value"><?= $totalStudents ?></div>
            </div>
            <div class="cc-kpi green">
                <div class="cc-kpi-label">Ready for Certificate</div>
                <div class="cc-kpi-value"><?= $readyCount ?></div>
            </div>
            <div class="cc-kpi amber">
                <div class="cc-kpi-label">No Photo</div>
                <div class="cc-kpi-value"><?= $noPhotoCount ?></div>
            </div>
            <div class="cc-kpi rose">
                <div class="cc-kpi-label">Share Unpaid</div>
                <div class="cc-kpi-value"><?= $unpaidCount ?></div>
            </div>
            <div class="cc-kpi" style="border-left-color:#4361ee">
                <div class="cc-kpi-label">Exam Passed</div>
                <div class="cc-kpi-value"><?= $examPassedCount ?></div>
            </div>
        </div>

        <!-- Toolbar -->
        <div class="cc-toolbar">
            <div class="cc-toolbar-title">
                All Students
                <span class="cc-count-badge" id="visCount"><?= $totalStudents ?> shown</span>
            </div>
            <div class="cc-search">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                <input type="text" id="ccSearch" placeholder="Search by name, roll no, course…" autocomplete="off">
            </div>
        </div>

        <!-- Table -->
        <div class="cc-table-wrap">
            <table class="cc-table" id="ccTable">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Course</th>
                        <th>Photo</th>
                        <th>Share Paid</th>
                        <th>Exam</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($students)): ?>
                    <tr><td colspan="7">
                        <div class="cc-empty">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                            <div class="cc-empty-title">No active students found</div>
                            <div class="cc-empty-sub">Add students via New Admission to generate certificates.</div>
                        </div>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($students as $s):
                        $fullName   = trim($s['first_name'] . ' ' . ($s['middle_name'] ? $s['middle_name'].' ' : '') . $s['last_name']);
                        $hasPhoto   = (bool)$s['has_photo'];
                        $sharePaid  = (bool)$s['share_paid'];
                        $examPassed = (bool)$s['exam_passed'];
                        $ready      = $hasPhoto && $sharePaid && $examPassed;
                        $initial    = strtoupper(substr($s['first_name'], 0, 1));
                        $photoUrl   = $hasPhoto ? '../' . htmlspecialchars($s['photo']) : '';

                        if ($ready)              { $statusClass = 'chip-ready';   $statusText = '✓ Ready';        }
                        elseif (!$hasPhoto && !$sharePaid && !$examPassed) { $statusClass = 'chip-blocked'; $statusText = 'Multiple Missing'; }
                        elseif (!$examPassed)    { $statusClass = 'chip-blocked'; $statusText = 'Exam Not Passed'; }
                        elseif (!$hasPhoto)      { $statusClass = 'chip-nophoto'; $statusText = 'No Photo';       }
                        else                     { $statusClass = 'chip-unpaid';  $statusText = 'Share Unpaid';   }
                    ?>
                    <tr>
                        <td>
                            <div class="cc-stu-cell">
                                <?php if ($hasPhoto): ?>
                                    <img src="<?= $photoUrl ?>" class="cc-stu-photo" alt="">
                                <?php else: ?>
                                    <div class="cc-stu-initials"><?= $initial ?></div>
                                <?php endif; ?>
                                <div>
                                    <div class="cc-stu-name"><?= htmlspecialchars($fullName) ?></div>
                                    <div class="cc-stu-roll"><?= htmlspecialchars($s['roll_no']) ?> · <?= htmlspecialchars($s['registration_id'] ?? $s['roll_no']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td style="font-weight:600"><?= htmlspecialchars($s['course']) ?></td>
                        <td>
                            <?php if ($hasPhoto): ?>
                                <span class="cc-chip chip-ready"><span class="cc-chip-dot"></span>Uploaded</span>
                            <?php else: ?>
                                <span class="cc-chip chip-nophoto"><span class="cc-chip-dot"></span>Missing</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($sharePaid): ?>
                                <span class="cc-chip chip-ready"><span class="cc-chip-dot"></span>Paid</span>
                            <?php else: ?>
                                <span class="cc-chip chip-unpaid"><span class="cc-chip-dot"></span>Unpaid</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($examPassed): ?>
                                <span class="cc-chip chip-ready"><span class="cc-chip-dot"></span>Passed</span>
                            <?php elseif (($s['exam_status'] ?? '') === 'Failed'): ?>
                                <span class="cc-chip chip-unpaid"><span class="cc-chip-dot"></span>Failed</span>
                            <?php elseif (($s['exam_status'] ?? '') === 'Scheduled'): ?>
                                <span class="cc-chip chip-nophoto"><span class="cc-chip-dot"></span>Scheduled</span>
                            <?php else: ?>
                                <span class="cc-chip chip-blocked"><span class="cc-chip-dot"></span>Not Scheduled</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="cc-chip <?= $statusClass ?>">
                                <span class="cc-chip-dot"></span><?= $statusText ?>
                            </span>
                        </td>
                        <td>
                            <button
                                class="btn-generate"
                                <?= $ready ? '' : 'disabled' ?>
                                <?php if (!$ready):
                                    if (!$examPassed && !$hasPhoto && !$sharePaid) $reason = 'Pass exam, upload photo & pay share first';
                                    elseif (!$examPassed) $reason = 'Student must pass exam first';
                                    elseif (!$hasPhoto && !$sharePaid) $reason = 'Upload photo and pay HO share first';
                                    elseif (!$hasPhoto) $reason = 'Upload student photo first';
                                    else $reason = 'Pay HO share first';
                                ?>title="<?= $reason ?>"<?php endif; ?>
                                onclick='openCertModal(<?= json_encode([
                                    'id'              => $s['id'],
                                    'roll_no'         => $s['roll_no'],
                                    'registration_id' => $s['registration_id'] ?? $s['roll_no'],
                                    'full_name'       => $fullName,
                                    'course'          => $s['course'],
                                    'photo'           => $photoUrl,
                                    'mobile'          => $s['mobile'] ?? '',
                                    'created_at'      => $s['created_at'],
                                ]) ?>)'>
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                                Generate
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div><!-- /cc-wrap -->
</main>
</div><!-- /dashboard-layout -->

<!-- ══ CERTIFICATE MODAL ══ -->
<div class="cert-overlay" id="certOverlay" onclick="overlayClickClose(event)">
    <div class="cert-modal" id="certModal">
        <div class="cert-modal-head">
            <div>
                <div class="cert-modal-head-title">🎓 Course Completion Certificate</div>
                <div class="cert-modal-head-sub" id="certHeaderSub">Preview — print or download as PDF</div>
            </div>
            <div class="cert-modal-actions">
                <button class="cert-btn-print" onclick="printCert()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Print
                </button>
                <button class="cert-btn-pdf" onclick="downloadPdf()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Download PDF
                </button>
                <button class="cert-btn-close" onclick="closeCertModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
        </div>
        <div class="cert-modal-body" id="certContent"><!-- injected --></div>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
const ATC_NAME    = <?= json_encode($atcDetails['name'] ?? 'ATC Center') ?>;
const ATC_CODE    = <?= json_encode($atcDetails['center_code'] ?? ($atcDetails['id'] ?? '')) ?>;
const ATC_ADDRESS = <?= json_encode($atcDetails['address'] ?? '') ?>;
const ATC_MOBILE  = <?= json_encode($atcDetails['mobile'] ?? '') ?>;
const YEAR        = new Date().getFullYear();

let _currentCert = null;

// ── Search ──────────────────────────────────────────────────────────────────
document.getElementById('ccSearch').addEventListener('input', function() {
    const q    = this.value.toLowerCase();
    const rows = document.querySelectorAll('#ccTable tbody tr');
    let vis = 0;
    rows.forEach(r => {
        const match = r.textContent.toLowerCase().includes(q);
        r.style.display = match ? '' : 'none';
        if (match) vis++;
    });
    document.getElementById('visCount').textContent = vis + ' shown';
});

// ── Open Modal ───────────────────────────────────────────────────────────────
function openCertModal(student) {
    _currentCert = student;
    document.getElementById('certHeaderSub').textContent = student.full_name + ' — ' + student.course;

    const certNo   = `CERT-${ATC_CODE || 'ATC'}-${student.roll_no}-${YEAR}`;
    const today    = new Date().toLocaleDateString('en-IN', {day:'2-digit', month:'long', year:'numeric'});
    const photoHtml = student.photo
        ? `<img src="${student.photo}" class="cert-student-photo" alt="Student Photo">`
        : `<div class="cert-student-photo-placeholder">No Photo</div>`;

    document.getElementById('certContent').innerHTML = `
        <div class="certificate" id="theCertificate">
            <div class="cert-head">
                <div class="cert-logo"><img src="../assets/logo.png" alt="Gyanam India"></div>
                <div class="cert-org">
                    <h1>Gyanam India Educational Services</h1>
                    <h2>Certificate of Course Completion</h2>
                    <p>This certificate is issued by the authorized examination body</p>
                </div>
                ${photoHtml}
            </div>

            <div class="cert-body">
                <p class="cert-certify-text">This is to certify that</p>
                <div class="cert-student-name">${escHtml(student.full_name.toUpperCase())}</div>
                <p class="cert-completed-text">has successfully completed the course</p>
                <div class="cert-course-name">${escHtml(student.course)}</div>
            </div>

            <div class="cert-details-bar">
                <span><strong>Roll No:</strong> ${escHtml(student.roll_no)}</span>
                <span><strong>Reg. ID:</strong> ${escHtml(student.registration_id)}</span>
                <span><strong>Center:</strong> ${escHtml(ATC_NAME)}</span>
                <span><strong>Date:</strong> ${today}</span>
                <span><strong>Cert. No:</strong> ${certNo}</span>
            </div>

            <hr class="cert-divider">

            <div class="cert-sigs">
                <div class="cert-sig-box">
                    <div class="cert-sig-line"></div>
                    <div class="cert-sig-label">Student</div>
                    <div class="cert-sig-title">${escHtml(student.full_name)}</div>
                </div>
                <div class="cert-sig-box">
                    <div class="cert-sig-line"></div>
                    <div class="cert-sig-label">ATC In-Charge</div>
                    <div class="cert-sig-title">${escHtml(ATC_NAME)}</div>
                </div>
                <div class="cert-sig-box">
                    <div class="cert-sig-line"></div>
                    <div class="cert-sig-label">Director</div>
                    <div class="cert-sig-title">Gyanam India Educational Services</div>
                </div>
            </div>

            <div class="cert-foot">
                Computer generated certificate · Does not require physical stamp · Contact: ${ATC_MOBILE || 'N/A'}<br>
                ${escHtml(ATC_ADDRESS)}
            </div>
        </div>`;

    document.getElementById('certOverlay').classList.add('open');
}

function closeCertModal() {
    document.getElementById('certOverlay').classList.remove('open');
    _currentCert = null;
}
function overlayClickClose(e) {
    if (e.target === document.getElementById('certOverlay')) closeCertModal();
}

// ── Print ────────────────────────────────────────────────────────────────────
function printCert() {
    const content = document.getElementById('theCertificate').outerHTML;
    openCertWindow(content, false);
}

// ── Download PDF ─────────────────────────────────────────────────────────────
function downloadPdf() {
    const content = document.getElementById('theCertificate').outerHTML;
    openCertWindow(content, true);
}

function openCertWindow(certHtml, showHint) {
    const pw = window.open('', '_blank', 'height=900,width=1050');
    pw.document.write(`<html><head><title>Certificate — Gyanam India</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,600;0,700;1,600&display=swap" rel="stylesheet">
    <style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:Arial,Helvetica,sans-serif;background:#f5f5f5;padding:24px}
    .certificate{max-width:210mm;margin:0 auto;background:#fff;padding:2rem;border:4px solid #4361ee;border-radius:12px;box-shadow:inset 0 0 0 6px #fff,inset 0 0 0 8px #eef1fd;position:relative}
    .certificate::before,.certificate::after{content:'';position:absolute;width:60px;height:60px;border:3px solid #7c3aed;border-radius:4px;opacity:.35}
    .certificate::before{top:12px;left:12px;border-right:none;border-bottom:none}
    .certificate::after{bottom:12px;right:12px;border-left:none;border-top:none}
    .cert-head{display:flex;align-items:center;justify-content:space-between;padding-bottom:1.25rem;border-bottom:3px double #4361ee;margin-bottom:1.25rem;gap:1rem}
    .cert-logo img{width:80px;height:80px;object-fit:contain}
    .cert-org{flex:1;text-align:center}
    .cert-org h1{font-size:20px;font-weight:800;color:#4361ee;letter-spacing:.5px;margin:0 0 4px;text-transform:uppercase}
    .cert-org h2{font-size:13px;color:#7c3aed;font-weight:700;margin:0 0 2px;text-transform:uppercase;letter-spacing:.3px}
    .cert-org p{font-size:11px;color:#6b7280;margin:0}
    .cert-student-photo{width:100px;height:120px;border:2px solid #4361ee;object-fit:cover;border-radius:4px;flex-shrink:0}
    .cert-student-photo-placeholder{width:100px;height:120px;border:2px dashed #cbd5e1;border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:10px;color:#9ca3af;text-align:center;flex-shrink:0}
    .cert-body{text-align:center;padding:1rem 0 1.25rem}
    .cert-certify-text{font-size:13px;color:#6b7280;margin:0 0 .5rem;font-style:italic}
    .cert-student-name{font-family:'Playfair Display',Georgia,serif;font-size:32px;font-weight:700;color:#4361ee;letter-spacing:.5px;margin:.3rem 0;line-height:1.2}
    .cert-completed-text{font-size:13px;color:#374151;margin:.5rem 0}
    .cert-course-name{font-size:20px;font-weight:800;color:#7c3aed;margin:.25rem 0 1rem;text-transform:uppercase;letter-spacing:.3px}
    .cert-details-bar{display:flex;flex-wrap:wrap;justify-content:center;gap:.5rem 1.25rem;background:#eef1fd;border-radius:8px;padding:.75rem 1rem;margin-bottom:1.25rem;font-size:11.5px;color:#374151}
    .cert-details-bar strong{color:#4361ee}
    .cert-divider{border:none;border-top:1.5px dashed #e5e7eb;margin:1.25rem 0}
    .cert-sigs{display:flex;justify-content:space-between;gap:1rem;margin-top:.25rem}
    .cert-sig-box{flex:1;text-align:center}
    .cert-sig-line{width:130px;height:50px;border-bottom:2px solid #374151;margin:0 auto 6px}
    .cert-sig-label{font-size:10px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.4px}
    .cert-sig-title{font-size:9px;color:#9ca3af;margin-top:2px}
    .cert-foot{text-align:center;font-size:9.5px;color:#9ca3af;margin-top:1rem;line-height:1.6}
    .download-hint{text-align:center;padding:12px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;font-size:12px;color:#92400e;margin-bottom:16px;font-weight:600}
    @media print{body{padding:0;background:#fff}.download-hint{display:none}}
    </style></head><body>`);
    if (showHint) {
        pw.document.write('<div class="download-hint">📥 Press <strong>Ctrl + P</strong> (or ⌘P on Mac) → Select <strong>"Save as PDF"</strong> as the printer to download.</div>');
    }
    pw.document.write(certHtml);
    pw.document.write('</body></html>');
    pw.document.close();
    if (!showHint) setTimeout(() => pw.print(), 400);
}

function escHtml(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Escape key
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeCertModal(); });
</script>
</body>
</html>
