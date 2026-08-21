<?php
/**
 * Gyanam Portal — Admin: Print Certificates
 * Lists students who are eligible for an exam certificate (passed).
 * Certificate is printed via admin/student_certificate.php
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
if (file_exists(__DIR__ . '/../includes/exam_integration.php')) {
    require_once __DIR__ . '/../includes/exam_integration.php';
}

requireLogin(['Admin']);

$pdo      = getDBConnection();
$userName = sanitize(getUserName());

// ── Load filter options ───────────────────────────────────────────────────────
$dlcList = $pdo->query("SELECT id, name FROM dlc_offices WHERE status='Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$atcList = $pdo->query("SELECT id, name, dlc_id, atc_code FROM atc_centers WHERE status='Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$courseList = [];
try {
    $courseList = $pdo->query("SELECT DISTINCT course FROM admissions WHERE course IS NOT NULL AND course != '' ORDER BY course")->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {}

// ── Filters ───────────────────────────────────────────────────────────────────
$filterDlc    = isset($_GET['dlc_id'])    && $_GET['dlc_id']    !== '' ? (int)$_GET['dlc_id']    : 0;
$filterAtc    = isset($_GET['atc_id'])    && $_GET['atc_id']    !== '' ? (int)$_GET['atc_id']    : 0;
$filterCourse = trim($_GET['course']  ?? '');
$filterSearch = trim($_GET['search']  ?? '');

// ── Try to pull passed student identifiers from exam portal ───────────────────
$passedIdentifiers = []; // Set of registration_id / GYANAM IDs that passed
$integrationReady  = function_exists('fetchAllExamResults') && defined('EXAM_API_TOKEN') && EXAM_API_TOKEN !== 'PASTE_YOUR_TOKEN_HERE';
if ($integrationReady) {
    $res = fetchAllExamResults();
    if ($res['success'] && isset($res['data']['submissions'])) {
        foreach ($res['data']['submissions'] as $sub) {
            // Skip DEMO exams — only main exams count for certificate eligibility
            $examTitle = strtolower($sub['exam_title'] ?? ($sub['exam']['title'] ?? ''));
            if (str_contains($examTitle, 'demo')) continue;

            if (strtolower($sub['result'] ?? '') === 'pass') {
                $id = $sub['student']['identifier'] ?? '';
                if ($id) {
                    // Store score and date alongside the pass record
                    $passedIdentifiers[$id] = [
                        'score'      => intval($sub['score'] ?? 0),
                        'exam_date'  => date('Y-m-d', strtotime($sub['submitted_at'] ?? 'now')),
                        'exam_title' => $sub['exam_title'] ?? ($sub['exam']['title'] ?? ''),
                    ];
                }
            }
        }
    }
}

// ── Fetch students ─────────────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($filterAtc) {
    $where[] = 'a.atc_id = ?'; $params[] = $filterAtc;
} elseif ($filterDlc) {
    $where[] = 'atc.dlc_id = ?'; $params[] = $filterDlc;
}
if ($filterCourse !== '') {
    $where[] = 'a.course = ?'; $params[] = $filterCourse;
}
if ($filterSearch !== '') {
    $where[] = "(CONCAT(a.first_name,' ',COALESCE(a.middle_name,''),' ',a.last_name) LIKE ? OR a.roll_no LIKE ? OR a.registration_id LIKE ?)";
    $s = "%$filterSearch%"; $params = array_merge($params, [$s, $s, $s]);
}

try {
    $sql = "SELECT a.id, a.roll_no, a.registration_id,
                   CONCAT(a.first_name,' ',COALESCE(NULLIF(TRIM(a.middle_name),''),''),' ',a.last_name) AS student_name,
                   a.course, a.admission_date, a.photo,
                   atc.name AS atc_name, atc.atc_code,
                   dlc.name AS dlc_name
            FROM admissions a
            LEFT JOIN atc_centers atc ON atc.id = a.atc_id
            LEFT JOIN dlc_offices dlc ON dlc.id = atc.dlc_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY a.admission_date DESC, student_name ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $students = [];
}

// Tag each student with pass status + score/date
foreach ($students as &$s) {
    $regId = $s['registration_id'] ?: ('GYANAM' . $s['id']);
    $passData = $passedIdentifiers[$regId] ?? null;
    $s['exam_passed']  = $passData !== null;
    $s['exam_score']   = $passData['score']   ?? 0;
    $s['exam_date']    = $passData['exam_date'] ?? date('Y-m-d');
    $s['exam_title']   = $passData['exam_title'] ?? '';
}
unset($s);

$totalStudents = count($students);
$passedCount   = count(array_filter($students, fn($s) => $s['exam_passed']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Print Certificates — Admin | Gyanam India Educational Services</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<link rel="stylesheet" href="../assets/css/notifications.css">
<style>
:root { --font:'Sora',sans-serif; --mono:'JetBrains Mono',monospace; }

/* Stats */
.cert-stats { display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:1rem;margin-bottom:1.5rem }
.cert-stat { background:#fff;border:1.5px solid var(--border-color);border-radius:14px;padding:1rem 1.25rem;display:flex;align-items:center;gap:.85rem }
.cstat-icon { width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0 }
.cstat-icon svg { width:20px;height:20px }
.cstat-icon.blue   { background:#eff6ff;color:#3b82f6 }
.cstat-icon.green  { background:#ecfdf5;color:#059669 }
.cstat-icon.amber  { background:#fffbeb;color:#d97706 }
.cstat-val { font-size:1.5rem;font-weight:900;color:var(--text-primary,#111);line-height:1 }
.cstat-lbl { font-size:.71rem;font-weight:700;color:var(--text-secondary,#6b7280);margin-top:.15rem }

/* Filter bar */
.cert-filters { display:flex;gap:.65rem;align-items:flex-end;flex-wrap:wrap;margin-bottom:1.25rem;background:#fff;padding:1.1rem 1.25rem;border-radius:14px;border:1.5px solid var(--border-color) }
.cf-grp { display:flex;flex-direction:column;gap:.28rem }
.cf-grp label { font-size:.72rem;font-weight:700;color:var(--text-secondary,#6b7280) }
.cf-grp select,.cf-grp input { height:38px;padding:0 .75rem;border:1.5px solid var(--border-color);border-radius:8px;font-family:var(--font);font-size:.84rem;outline:none;background:#fff;transition:border-color .18s }
.cf-grp select:focus,.cf-grp input:focus { border-color:#6366f1 }
.btn-apply { height:38px;padding:0 1.15rem;background:#6366f1;color:#fff;border:none;border-radius:8px;font-weight:700;cursor:pointer;font-family:var(--font);white-space:nowrap;transition:background .18s }
.btn-apply:hover { background:#4f46e5 }
.btn-clr { height:38px;padding:0 .9rem;background:#f3f4f6;color:#4b5563;border:1.5px solid var(--border-color);border-radius:8px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;font-family:var(--font);white-space:nowrap }
.btn-clr:hover { background:#e5e7eb }

/* Info bar */
.info-bar { display:flex;align-items:center;justify-content:space-between;margin-bottom:.85rem;flex-wrap:wrap;gap:.5rem }
.info-bar-left { font-size:.82rem;font-weight:700;color:var(--text-secondary,#6b7280) }
.info-notice { font-size:.75rem;background:#fef9ec;border:1px solid #fde68a;color:#92400e;padding:.35rem .8rem;border-radius:8px;font-weight:600 }

/* Table */
.cert-table-wrap { background:#fff;border:1.5px solid var(--border-color);border-radius:14px;overflow:hidden }
.cert-table { width:100%;border-collapse:collapse;font-size:.84rem }
.cert-table thead { background:#fafbfc }
.cert-table th { padding:.8rem 1rem;text-align:left;font-size:.68rem;font-weight:800;color:var(--text-secondary,#6b7280);text-transform:uppercase;letter-spacing:.07em;border-bottom:1px solid var(--border-color);white-space:nowrap }
.cert-table tbody tr { border-bottom:1px solid #f3f4f6;transition:background .12s }
.cert-table tbody tr:hover { background:#f8faff }
.cert-table tbody tr:last-child { border-bottom:none }
.cert-table td { padding:.85rem 1rem;vertical-align:middle }

/* Student cell */
.stu-cell { display:flex;align-items:center;gap:.75rem }
.stu-av { width:38px;height:38px;border-radius:9px;object-fit:cover;flex-shrink:0;border:1.5px solid var(--border-color) }
.stu-av-ph { width:38px;height:38px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:.9rem;color:#fff;background:linear-gradient(135deg,#6366f1,#8b5cf6) }
.stu-name { font-weight:800;font-size:.88rem;color:#111 }
.stu-id { font-size:.7rem;font-family:var(--mono);color:#9ca3af;margin-top:2px }

/* Badges */
.course-tag { display:inline-block;padding:.2rem .6rem;background:#f0f4ff;color:#4361ee;border:1px solid #c7d2fe;border-radius:6px;font-size:.73rem;font-weight:700 }
.atc-tag { display:inline-block;font-size:.72rem;font-weight:700;color:#6b7280 }

.status-badge { display:inline-flex;align-items:center;gap:.3rem;padding:.28rem .7rem;border-radius:999px;font-size:.72rem;font-weight:800;white-space:nowrap }
.status-dot { width:6px;height:6px;border-radius:50% }
.badge-pass   { background:#d1fae5;color:#065f46;border:1px solid #6ee7b7 }
.badge-pass   .status-dot { background:#10b981 }
.badge-pending { background:#fef3c7;color:#92400e;border:1px solid #fde68a }
.badge-pending .status-dot { background:#f59e0b }
.badge-none   { background:#f3f4f6;color:#6b7280;border:1px solid #e5e7eb }
.badge-none   .status-dot { background:#d1d5db }

/* Print button */
.btn-cert { display:inline-flex;align-items:center;gap:.3rem;padding:.42rem .9rem;border-radius:8px;border:1.5px solid #a7f3d0;background:#ecfdf5;color:#065f46;font-size:.78rem;font-weight:800;text-decoration:none;transition:all .15s;white-space:nowrap }
.btn-cert:hover { background:#d1fae5;transform:translateY(-1px);box-shadow:0 2px 8px rgba(16,185,129,.2) }
.btn-cert-disabled { display:inline-flex;align-items:center;gap:.3rem;padding:.42rem .9rem;border-radius:8px;border:1.5px solid #e5e7eb;background:#f9fafb;color:#9ca3af;font-size:.78rem;font-weight:800;cursor:not-allowed;white-space:nowrap }

/* Empty */
.empty-state { text-align:center;padding:4rem 2rem }
.empty-state svg { width:52px;height:52px;stroke:#d1d5db;display:block;margin:0 auto 1rem }
.empty-title { font-size:1rem;font-weight:800;color:#6b7280;margin-bottom:.3rem }
.empty-sub { font-size:.82rem;color:#9ca3af }

@media (max-width:768px) {
    .cert-filters { flex-direction:column;align-items:stretch }
    .cert-stats { grid-template-columns:1fr 1fr }
}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include __DIR__ . '/sidebar.php'; ?>

<main class="main-content">
    <header class="top-header">
        <div class="header-left">
            <button class="hamburger" id="hamburgerBtn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <div class="header-greeting">
                <h2>Print Certificates</h2>
                <p>Generate &amp; print exam certificates for passed students</p>
            </div>
        </div>
        <div class="header-right">
            <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
            <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">

        <!-- Stats -->
        <div class="cert-stats">
            <div class="cert-stat">
                <div class="cstat-icon blue">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div><div class="cstat-val"><?= $totalStudents ?></div><div class="cstat-lbl">Students Listed</div></div>
            </div>
            <div class="cert-stat">
                <div class="cstat-icon green">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <div><div class="cstat-val"><?= $passedCount ?></div><div class="cstat-lbl">Exam Passed</div></div>
            </div>
            <div class="cert-stat">
                <div class="cstat-icon amber">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <div><div class="cstat-val"><?= $totalStudents - $passedCount ?></div><div class="cstat-lbl">Pending / Not Taken</div></div>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="cert-filters">
            <div class="cf-grp">
                <label>DLC Office</label>
                <select name="dlc_id" onchange="this.form.submit()" style="min-width:160px">
                    <option value="">— All DLCs —</option>
                    <?php foreach ($dlcList as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $filterDlc == $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="cf-grp">
                <label>ATC Center</label>
                <select name="atc_id" style="min-width:180px">
                    <option value="">— All ATCs —</option>
                    <?php foreach ($atcList as $a): ?>
                    <option value="<?= $a['id'] ?>"
                        data-dlc="<?= $a['dlc_id'] ?>"
                        <?= $filterAtc == $a['id'] ? 'selected' : '' ?>
                        <?= ($filterDlc && (int)$a['dlc_id'] !== $filterDlc) ? 'class="hidden-opt"' : '' ?>>
                        <?= htmlspecialchars($a['name']) ?><?= $a['atc_code'] ? ' (' . htmlspecialchars($a['atc_code']) . ')' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (!empty($courseList)): ?>
            <div class="cf-grp">
                <label>Course</label>
                <select name="course" style="min-width:140px">
                    <option value="">— All Courses —</option>
                    <?php foreach ($courseList as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?= $filterCourse === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="cf-grp">
                <label>Search</label>
                <input type="text" name="search" placeholder="Name, Reg ID, Roll No…" value="<?= htmlspecialchars($filterSearch) ?>" style="min-width:180px">
            </div>
            <button type="submit" class="btn-apply">🔍 Apply</button>
            <?php if ($filterDlc || $filterAtc || $filterCourse || $filterSearch): ?>
            <a href="print_certificates.php" class="btn-clr">✕ Clear</a>
            <?php endif; ?>
        </form>

        <!-- Info bar -->
        <div class="info-bar">
            <span class="info-bar-left"><?= $totalStudents ?> student<?= $totalStudents !== 1 ? 's' : '' ?> listed — <?= $passedCount ?> eligible for certificate</span>
            <?php if (!$integrationReady): ?>
            <span class="info-notice">⚠️ Exam portal not connected — pass status unavailable. Certificates can still be printed manually.</span>
            <?php endif; ?>
        </div>

        <!-- Table -->
        <?php if (empty($students)): ?>
        <div class="cert-table-wrap">
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <div class="empty-title">No students found</div>
                <div class="empty-sub">Try adjusting filters or search to find students.</div>
            </div>
        </div>
        <?php else: ?>
        <div class="cert-table-wrap">
            <table class="cert-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Student</th>
                        <th>Course</th>
                        <th>ATC Center</th>
                        <th>Admission Date</th>
                        <th>Exam Status</th>
                        <th style="text-align:center">Certificate</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $idx => $s):
                    $name     = trim(preg_replace('/\s+/', ' ', $s['student_name']));
                    $parts    = array_filter(explode(' ', $name));
                    $initials = strtoupper(implode('', array_map(fn($w) => $w[0], array_slice($parts, 0, 2))));
                    $regId    = $s['registration_id'] ?: ('GYANAM' . $s['id']);
                    $admDate  = $s['admission_date'] ? date('d M Y', strtotime($s['admission_date'])) : '—';
                ?>
                <tr>
                    <td style="color:#9ca3af;font-size:.75rem"><?= $idx + 1 ?></td>

                    <!-- Student -->
                    <td>
                        <div class="stu-cell">
                            <?php if ($s['photo']): ?>
                            <img class="stu-av" src="../<?= htmlspecialchars($s['photo']) ?>" alt="<?= htmlspecialchars($name) ?>" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                            <div class="stu-av-ph" style="display:none"><?= $initials ?></div>
                            <?php else: ?>
                            <div class="stu-av-ph"><?= $initials ?></div>
                            <?php endif; ?>
                            <div>
                                <div class="stu-name"><?= htmlspecialchars($name) ?></div>
                                <div class="stu-id"><?= htmlspecialchars($regId) ?> <?= $s['roll_no'] ? '· Roll ' . htmlspecialchars($s['roll_no']) : '' ?></div>
                            </div>
                        </div>
                    </td>

                    <!-- Course -->
                    <td><span class="course-tag"><?= htmlspecialchars($s['course'] ?: '—') ?></span></td>

                    <!-- ATC -->
                    <td>
                        <div class="stu-name" style="font-size:.84rem"><?= htmlspecialchars($s['atc_name'] ?? '—') ?></div>
                        <?php if ($s['dlc_name']): ?><div class="atc-tag"><?= htmlspecialchars($s['dlc_name']) ?></div><?php endif; ?>
                    </td>

                    <!-- Admission Date -->
                    <td style="font-size:.82rem;color:#6b7280"><?= $admDate ?></td>

                    <!-- Exam Status -->
                    <td>
                        <?php if (!$integrationReady): ?>
                        <span class="status-badge badge-none"><span class="status-dot"></span>Not Connected</span>
                        <?php elseif ($s['exam_passed']): ?>
                        <span class="status-badge badge-pass"><span class="status-dot"></span>✅ Passed</span>
                        <?php else: ?>
                        <span class="status-badge badge-pending"><span class="status-dot"></span>Pending / Fail</span>
                        <?php endif; ?>
                    </td>

                    <!-- Certificate button -->
                    <td style="text-align:center">
                        <?php if ($s['exam_passed']): ?>
                        <a href="generate_course_certificate.php?reg_id=<?= urlencode($regId) ?>&score=<?= $s['exam_score'] ?>&exam_date=<?= urlencode($s['exam_date']) ?>&preview=1"
                           target="_blank" class="btn-cert">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                            Print Certificate
                        </a>
                        <?php else: ?>
                        <span class="btn-cert-disabled" title="<?= !$integrationReady ? 'Exam portal not connected' : 'Student has not passed the main exam yet' ?>">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            Not Eligible
                        </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

    </div><!-- /page-content -->
</main>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
// Filter ATC dropdown by DLC
document.querySelector('select[name="dlc_id"]')?.addEventListener('change', function() {
    const dlcId = this.value;
    const atcSel = document.querySelector('select[name="atc_id"]');
    if (!atcSel) return;
    Array.from(atcSel.options).forEach(opt => {
        if (!opt.value) { opt.style.display = ''; return; }
        opt.style.display = (!dlcId || opt.dataset.dlc === dlcId) ? '' : 'none';
    });
    atcSel.value = '';
});
// Apply DLC filter on load
(function() {
    const dlcVal = document.querySelector('select[name="dlc_id"]')?.value;
    if (dlcVal) document.querySelector('select[name="dlc_id"]')?.dispatchEvent(new Event('change'));
})();
</script>
</body>
</html>
