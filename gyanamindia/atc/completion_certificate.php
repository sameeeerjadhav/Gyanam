<?php
/**
 * Gyanam Portal — ATC: Course Completion Certificates
 * Opens the official GIIT PDF (generate_course_certificate.php).
 *
 * Certificate is only available when:
 *   1. Student photo is uploaded
 *   2. HO share has been paid
 *   3. Main exam passed (Exam Portal, or exam_schedules fallback)
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
if (file_exists(__DIR__ . '/../includes/exam_integration.php')) {
    require_once __DIR__ . '/../includes/exam_integration.php';
}

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

// Exam pass map from Exam Portal (authoritative when connected)
$examPassIds = [];
$atcCode = trim((string)($atcDetails['atc_code'] ?? ''));
if ($atcCode !== '' && function_exists('examIntegrationReady') && examIntegrationReady()) {
    $res = fetchAllExamResultsComplete();
    if ($res['success'] && !empty($res['data']['submissions'])) {
        foreach ($res['data']['submissions'] as $sub) {
            if (($sub['centre_name'] ?? '') !== $atcCode) {
                continue;
            }
            $rec = examSubmissionPassRecord($sub);
            if ($rec) {
                $examPassIds[$rec['identifier']] = true;
            }
        }
    }
}

// Annotate exam_passed flag
foreach ($students as &$_s) {
    $regKey = trim((string)($_s['registration_id'] ?? ''));
    if ($regKey === '') {
        $regKey = trim((string)($_s['roll_no'] ?? ''));
    }
    $passedPortal = $regKey !== '' && !empty($examPassIds[$regKey]);
    $_s['exam_passed'] = ($passedPortal || ($_s['exam_status'] ?? '') === 'Passed') ? 1 : 0;
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
.btn-generate:disabled, .btn-generate.disabled { opacity:.4; cursor:not-allowed; transform:none; box-shadow:none; background:#e2e8f0; color:#94a3b8; pointer-events:none; text-decoration:none; }
.btn-generate svg { width:13px; height:13px; }
a.btn-generate { text-decoration:none; }

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
                <p>Print official GIIT course completion PDFs (exam pass + share paid + photo)</p>
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
                    <div class="cc-page-sub">Official GIIT PDF after exam pass, HO share paid &amp; photo uploaded</div>
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
                            <?php
                            $regIdForCert = trim((string)($s['registration_id'] ?? ''));
                            if ($regIdForCert === '') {
                                $regIdForCert = trim((string)($s['roll_no'] ?? ''));
                            }
                            $certUrl = '../admin/generate_course_certificate.php?reg_id=' . urlencode($regIdForCert) . '&preview=1';
                            if (!$ready):
                                if (!$examPassed && !$hasPhoto && !$sharePaid) $reason = 'Pass exam, upload photo & pay share first';
                                elseif (!$examPassed) $reason = 'Student must pass exam first';
                                elseif (!$hasPhoto && !$sharePaid) $reason = 'Upload photo and pay HO share first';
                                elseif (!$hasPhoto) $reason = 'Upload student photo first';
                                else $reason = 'Pay HO share first';
                            endif;
                            ?>
                            <?php if ($ready): ?>
                            <a class="btn-generate" href="<?= htmlspecialchars($certUrl) ?>" target="_blank" rel="noopener">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                                View GIIT PDF
                            </a>
                            <?php else: ?>
                            <span class="btn-generate disabled" title="<?= htmlspecialchars($reason) ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                                View GIIT PDF
                            </span>
                            <?php endif; ?>
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

<script src="../assets/js/dashboard.js"></script>
<script>
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
</script>
</body>
</html>
