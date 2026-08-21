<?php
/**
 * Gyanam Portal — ATC: Student Marks & Results
 * Groups results by student — one row per student with expandable exam history.
 * Fetches from Exam Portal API + local exam_schedules.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
if (file_exists(__DIR__ . '/../includes/notifications.php'))
    require_once __DIR__ . '/../includes/notifications.php';
if (file_exists(__DIR__ . '/../includes/exam_integration.php'))
    require_once __DIR__ . '/../includes/exam_integration.php';

requireLogin(['ATC CENTER']);

$pdo      = getDBConnection();
$userName = sanitize(getUserName());
$atcId    = $_SESSION['atc_id'] ?? null;

$integrationReady = function_exists('fetchAllExamResults') && defined('EXAM_API_TOKEN') && EXAM_API_TOKEN !== 'PASTE_YOUR_TOKEN_HERE';

// ── Fetch marks from Exam Portal API ─────────────────────────────────────────
$examResults = [];
$fetchError  = null;
if ($integrationReady) {
    $res = fetchAllExamResults();
    if ($res['success']) {
        $examResults = $res['data']['submissions'] ?? $res['data'] ?? [];
    } else {
        $fetchError = $res['error'] ?? 'Failed to fetch results.';
    }
}

// ── Also fetch from local exam_schedules for status merge ─────────────────────
$localStatuses = [];
try {
    $ls = $pdo->prepare("SELECT es.admission_id, es.exam_status, es.exam_date,
                                TRIM(CONCAT(a.first_name,' ',COALESCE(a.middle_name,''),' ',a.last_name)) AS student_name,
                                a.roll_no, a.registration_id, a.course, a.photo, a.mobile
                         FROM exam_schedules es
                         JOIN admissions a ON a.id = es.admission_id
                         WHERE es.atc_id = ?
                         ORDER BY es.exam_date DESC");
    $ls->execute([$atcId]);
    $localStatuses = $ls->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Build flat results array ──────────────────────────────────────────────────
$flatResults = [];

// From API
if (!empty($examResults)) {
    foreach ($examResults as $r) {
        $flatResults[] = [
            'student_name'   => $r['student_name'] ?? $r['name'] ?? '—',
            'identifier'     => $r['identifier'] ?? $r['registration_id'] ?? '—',
            'course'         => $r['subject'] ?? $r['course'] ?? $r['exam_title'] ?? '—',
            'exam_title'     => $r['exam_title'] ?? $r['title'] ?? '—',
            'exam_date'      => $r['completed_at'] ?? $r['exam_date'] ?? $r['submitted_at'] ?? '—',
            'marks_obtained' => $r['score'] ?? $r['marks_obtained'] ?? $r['total_score'] ?? 0,
            'total_marks'    => $r['max_score'] ?? $r['total_marks'] ?? $r['out_of'] ?? 100,
            'percentage'     => $r['percentage'] ?? null,
            'result'         => $r['result'] ?? $r['status'] ?? (($r['score'] ?? 0) >= ($r['passing_score'] ?? 40) ? 'Passed' : 'Failed'),
            'photo'          => $r['photo'] ?? '',
            'source'         => 'api',
        ];
    }
}

// From local (only if not already in API results)
foreach ($localStatuses as $l) {
    $alreadyFromApi = false;
    foreach ($flatResults as $fr) {
        if ($fr['identifier'] === $l['registration_id']) { $alreadyFromApi = true; break; }
    }
    if (!$alreadyFromApi && in_array($l['exam_status'], ['Passed', 'Failed'])) {
        $flatResults[] = [
            'student_name'   => $l['student_name'],
            'identifier'     => $l['registration_id'],
            'course'         => $l['course'],
            'exam_title'     => $l['course'] . ' Final Exam',
            'exam_date'      => $l['exam_date'],
            'marks_obtained' => '—',
            'total_marks'    => '—',
            'percentage'     => null,
            'result'         => $l['exam_status'],
            'photo'          => $l['photo'] ?? '',
            'source'         => 'local',
        ];
    }
}

// ── Group by student (identifier) ─────────────────────────────────────────────
$grouped = []; // key=identifier, value=['info'=>..., 'exams'=>[...]]
foreach ($flatResults as $r) {
    $key = $r['identifier'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'student_name' => $r['student_name'],
            'identifier'   => $r['identifier'],
            'course'       => $r['course'],
            'photo'        => $r['photo'],
            'exams'        => [],
        ];
    }
    // Keep the best photo if one exists
    if (!empty($r['photo']) && empty($grouped[$key]['photo'])) {
        $grouped[$key]['photo'] = $r['photo'];
    }
    $grouped[$key]['exams'][] = $r;
}

// Sort exams within each student by date descending
foreach ($grouped as &$g) {
    usort($g['exams'], function($a, $b) {
        return strtotime($b['exam_date'] ?? '1970-01-01') - strtotime($a['exam_date'] ?? '1970-01-01');
    });
    // Determine the latest/best result for the student row
    $hasPassed = false;
    $bestPct   = null;
    foreach ($g['exams'] as $ex) {
        if (strtolower($ex['result']) === 'passed') $hasPassed = true;
        $p = $ex['percentage'];
        if ($p === null && is_numeric($ex['marks_obtained']) && is_numeric($ex['total_marks']) && $ex['total_marks'] > 0)
            $p = round(($ex['marks_obtained'] / $ex['total_marks']) * 100, 1);
        if ($p !== null && ($bestPct === null || $p > $bestPct)) $bestPct = $p;
    }
    $g['latest_result']  = $hasPassed ? 'Passed' : ($g['exams'][0]['result'] ?? '—');
    $g['best_percentage']= $bestPct;
    $g['total_attempts'] = count($g['exams']);
    $g['latest_exam']    = $g['exams'][0];
}
unset($g);

// ── Course filter ─────────────────────────────────────────────────────────────
$courseFilter = trim($_GET['course'] ?? 'all');
$allCourses  = array_values(array_unique(array_filter(array_column($grouped, 'course'))));
sort($allCourses);

if ($courseFilter !== 'all') {
    $grouped = array_filter($grouped, fn($g) => $g['course'] === $courseFilter);
}
$grouped = array_values($grouped);

// Stats (count unique students, not individual results)
$totalStudents = count($grouped);
$passedStudents = count(array_filter($grouped, fn($g) => strtolower($g['latest_result']) === 'passed'));
$failedStudents = count(array_filter($grouped, fn($g) => strtolower($g['latest_result']) === 'failed'));
$totalAttempts  = array_sum(array_column($grouped, 'total_attempts'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Student Marks — ATC Login | Gyanam India</title>
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<?php if (file_exists(__DIR__.'/../assets/css/notifications.css')): ?>
<link rel="stylesheet" href="../assets/css/notifications.css">
<?php endif; ?>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📊</text></svg>">
<style>
:root { --sm-brand:#4361ee; --sm-brand-dk:#3730a3; --sm-brand-lt:#eef1fd;
        --sm-green:#10b981; --sm-green-lt:#ecfdf5; --sm-red:#ef4444; --sm-red-lt:#fef2f2;
        --sm-amber:#f59e0b; --sm-amber-lt:#fffbeb; --sm-violet:#7c3aed; --sm-violet-lt:#f5f3ff; }

/* KPI */
.sm-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:1rem;margin-bottom:1.5rem}
.sm-kpi-card{background:#fff;border:1.5px solid var(--border-color);border-radius:14px;padding:1.1rem 1.3rem;display:flex;align-items:center;gap:.8rem;border-left:4px solid transparent}
.sm-kpi-card.brand{border-left-color:var(--sm-brand)}.sm-kpi-card.green{border-left-color:var(--sm-green)}
.sm-kpi-card.red{border-left-color:var(--sm-red)}.sm-kpi-card.amber{border-left-color:var(--sm-amber)}.sm-kpi-card.violet{border-left-color:var(--sm-violet)}
.sm-kpi-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sm-kpi-icon svg{width:18px;height:18px;stroke:currentColor;fill:none}
.sm-kpi-card.brand .sm-kpi-icon{background:var(--sm-brand-lt);color:var(--sm-brand)}
.sm-kpi-card.green .sm-kpi-icon{background:var(--sm-green-lt);color:var(--sm-green)}
.sm-kpi-card.red .sm-kpi-icon{background:var(--sm-red-lt);color:var(--sm-red)}
.sm-kpi-card.amber .sm-kpi-icon{background:var(--sm-amber-lt);color:var(--sm-amber)}
.sm-kpi-card.violet .sm-kpi-icon{background:var(--sm-violet-lt);color:var(--sm-violet)}
.sm-kpi-val{font-size:1.5rem;font-weight:800;color:var(--text-primary);line-height:1}
.sm-kpi-lbl{font-size:.7rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.04em;margin-top:.15rem}

/* Toolbar */
.sm-toolbar{display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-bottom:1.25rem}
.sm-toolbar-left{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
.sm-filter{padding:.55rem .85rem;border:1.5px solid var(--border-color);border-radius:9px;font-size:.82rem;font-weight:600;font-family:inherit;outline:none;cursor:pointer}
.sm-filter:focus{border-color:var(--sm-brand)}
.sm-search{padding:.55rem 1rem;border:1.5px solid var(--border-color);border-radius:9px;font-size:.82rem;font-family:inherit;outline:none;width:220px}
.sm-search:focus{border-color:var(--sm-brand)}

/* Table card */
.sm-card{background:#fff;border:1.5px solid var(--border-color);border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04)}
.sm-card-head{padding:1rem 1.25rem;border-bottom:1px solid var(--border-color);display:flex;align-items:center;gap:.75rem;background:#fafbfc}
.sm-card-head-icon{width:36px;height:36px;border-radius:9px;background:linear-gradient(135deg,var(--sm-brand),var(--sm-violet));display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sm-card-head-icon svg{width:16px;height:16px;stroke:#fff;fill:none}
.sm-card-head-title{font-weight:800;font-size:.92rem;color:var(--text-primary)}
.sm-card-head-count{margin-left:auto;background:var(--sm-brand-lt);color:var(--sm-brand);border-radius:999px;font-size:.72rem;font-weight:800;padding:.15rem .6rem}

/* Main table */
.sm-table{width:100%;border-collapse:collapse;font-size:.85rem}
.sm-table thead th{padding:.75rem 1rem;text-align:left;font-size:.68rem;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.05em;border-bottom:1.5px solid var(--border-color);background:#fafbfc;white-space:nowrap}
.sm-table tbody tr.sm-master{border-bottom:1px solid #f3f4f6;transition:background .1s;cursor:pointer}
.sm-table tbody tr.sm-master:hover{background:#f8faff}
.sm-table tbody td{padding:.7rem 1rem;vertical-align:middle}

/* Student cell */
.sm-stu-cell{display:flex;align-items:center;gap:.6rem}
.sm-stu-photo{width:36px;height:36px;border-radius:8px;object-fit:cover;border:1px solid var(--border-color);flex-shrink:0}
.sm-stu-initials{width:36px;height:36px;border-radius:8px;background:linear-gradient(135deg,var(--sm-brand),var(--sm-violet));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.8rem;flex-shrink:0}
.sm-stu-name{font-weight:700;font-size:.82rem;color:var(--text-primary)}
.sm-stu-sub{font-size:.7rem;color:var(--text-secondary);margin-top:.05rem}

/* Result badge */
.sm-badge{display:inline-flex;align-items:center;gap:.25rem;padding:.22rem .65rem;border-radius:999px;font-size:.7rem;font-weight:700}
.sm-badge-dot{width:5px;height:5px;border-radius:50%;background:currentColor}
.sm-badge-passed{background:var(--sm-green-lt);color:#065f46;border:1px solid #a7f3d0}
.sm-badge-failed{background:var(--sm-red-lt);color:#991b1b;border:1px solid #fecaca}

/* Percentage bar */
.sm-pct-bar{width:80px;height:6px;background:#f1f5f9;border-radius:99px;overflow:hidden;display:inline-block;margin-right:.4rem;vertical-align:middle}
.sm-pct-fill{height:100%;border-radius:99px;transition:width .3s ease}
.sm-pct-fill.high{background:linear-gradient(90deg,var(--sm-green),#059669)}
.sm-pct-fill.mid{background:linear-gradient(90deg,var(--sm-amber),#d97706)}
.sm-pct-fill.low{background:linear-gradient(90deg,var(--sm-red),#dc2626)}

/* Attempts badge */
.sm-attempts{display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .55rem;border-radius:6px;font-size:.7rem;font-weight:700;background:var(--sm-violet-lt);color:var(--sm-violet);border:1px solid #c4b5fd;cursor:pointer;transition:all .15s}
.sm-attempts:hover{background:#ede9fe}

/* Expand toggle */
.sm-expand-icon{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:6px;background:#f1f5f9;color:var(--text-secondary);transition:all .15s;font-size:.75rem;flex-shrink:0;border:none;cursor:pointer}
.sm-expand-icon:hover{background:var(--sm-brand-lt);color:var(--sm-brand)}
.sm-expand-icon.open{background:var(--sm-brand);color:#fff;transform:rotate(90deg)}

/* Expanded sub-row */
.sm-sub-row{display:none}
.sm-sub-row.open{display:table-row}
.sm-sub-row td{padding:0 1rem .75rem 1rem;background:#fafbff}
.sm-sub-table{width:100%;border-collapse:collapse;font-size:.8rem;border:1.5px solid #e0e7ff;border-radius:10px;overflow:hidden}
.sm-sub-table thead th{padding:.55rem .75rem;font-size:.65rem;font-weight:700;color:var(--sm-brand-dk);text-transform:uppercase;letter-spacing:.04em;background:var(--sm-brand-lt);border-bottom:1px solid #c7d2fe;text-align:left}
.sm-sub-table tbody td{padding:.55rem .75rem;border-bottom:1px solid #eef1fd;font-size:.8rem}
.sm-sub-table tbody tr:last-child td{border-bottom:none}
.sm-sub-label{font-size:.72rem;font-weight:700;color:var(--sm-brand-dk);text-transform:uppercase;letter-spacing:.03em;margin-bottom:.5rem;display:flex;align-items:center;gap:.4rem;padding-top:.5rem}

/* Empty */
.sm-empty{text-align:center;padding:3rem 1.5rem;color:var(--text-secondary)}
.sm-empty svg{width:40px;height:40px;stroke:#d1d5db;display:block;margin:0 auto .75rem}

/* Error */
.sm-error{background:var(--sm-red-lt);border:1px solid #fecaca;color:#991b1b;padding:1rem 1.25rem;border-radius:10px;font-size:.85rem;font-weight:600;margin-bottom:1.25rem}
.sm-not-configured{text-align:center;padding:4rem 2rem;background:#fff;border:1.5px solid var(--border-color);border-radius:14px}
.sm-not-configured svg{width:48px;height:48px;stroke:#d1d5db;display:block;margin:0 auto 1rem}
.sm-not-configured h4{font-size:1.1rem;font-weight:700;color:#374151;margin-bottom:.5rem}
.sm-not-configured p{font-size:.875rem;color:#6b7280;max-width:500px;margin:0 auto;line-height:1.6}

@media(max-width:768px){
    .sm-kpi{grid-template-columns:repeat(2,1fr)}
    .sm-toolbar{flex-direction:column;align-items:stretch}
    .sm-search{width:100%}
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
                <h2>Student Marks</h2>
                <p>View exam results, marks & performance — grouped by student</p>
            </div>
        </div>
        <div class="header-right">
            <?php if (file_exists(__DIR__.'/../includes/notification_bell.php')) include __DIR__.'/../includes/notification_bell.php'; ?>
            <?php include __DIR__.'/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">

        <!-- KPI -->
        <div class="sm-kpi">
            <div class="sm-kpi-card brand">
                <div class="sm-kpi-icon"><svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
                <div><div class="sm-kpi-val"><?= $totalStudents ?></div><div class="sm-kpi-lbl">Students</div></div>
            </div>
            <div class="sm-kpi-card green">
                <div class="sm-kpi-icon"><svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></div>
                <div><div class="sm-kpi-val"><?= $passedStudents ?></div><div class="sm-kpi-lbl">Passed</div></div>
            </div>
            <div class="sm-kpi-card red">
                <div class="sm-kpi-icon"><svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></div>
                <div><div class="sm-kpi-val"><?= $failedStudents ?></div><div class="sm-kpi-lbl">Failed</div></div>
            </div>
            <div class="sm-kpi-card violet">
                <div class="sm-kpi-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
                <div><div class="sm-kpi-val"><?= $totalAttempts ?></div><div class="sm-kpi-lbl">Total Attempts</div></div>
            </div>
            <div class="sm-kpi-card amber">
                <div class="sm-kpi-icon"><svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></div>
                <div><div class="sm-kpi-val"><?= $totalStudents > 0 ? round(($passedStudents / $totalStudents) * 100) . '%' : '—' ?></div><div class="sm-kpi-lbl">Pass Rate</div></div>
            </div>
        </div>

        <?php if ($fetchError): ?>
        <div class="sm-error">⚠️ <?= htmlspecialchars($fetchError) ?></div>
        <?php endif; ?>

        <!-- Toolbar -->
        <div class="sm-toolbar">
            <div class="sm-toolbar-left">
                <select class="sm-filter" onchange="location='?course='+this.value">
                    <option value="all" <?= $courseFilter==='all'?'selected':'' ?>>All Courses</option>
                    <?php foreach ($allCourses as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?= $courseFilter===$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" class="sm-search" id="smSearch" placeholder="Search by name, reg ID…" autocomplete="off">
            </div>
        </div>

        <?php if (!$integrationReady && empty($grouped)): ?>
        <div class="sm-not-configured">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            <h4>Exam Portal Not Configured</h4>
            <p>Results will appear here once the Exam Portal integration is set up, or when exam statuses are updated locally through the Exam Schedules page.</p>
        </div>
        <?php else: ?>

        <!-- Results Table — One Row Per Student -->
        <div class="sm-card">
            <div class="sm-card-head">
                <div class="sm-card-head-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                </div>
                <div class="sm-card-head-title">Student Results & Marks</div>
                <div class="sm-card-head-count"><?= $totalStudents ?> student(s)</div>
            </div>
            <div style="overflow-x:auto">
            <table class="sm-table" id="smTable">
                <thead><tr>
                    <th style="width:30px"></th>
                    <th>#</th>
                    <th>Student</th>
                    <th>Reg ID</th>
                    <th>Course</th>
                    <th>Latest Exam</th>
                    <th>Best Score</th>
                    <th>Result</th>
                    <th>Attempts</th>
                </tr></thead>
                <tbody>
                <?php if (empty($grouped)): ?>
                    <tr><td colspan="9">
                        <div class="sm-empty">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                            <p>No results found<?= $courseFilter !== 'all' ? ' for this course' : '' ?>.</p>
                        </div>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($grouped as $i => $g):
                        $hasPhoto = !empty($g['photo']);
                        $initial  = strtoupper(substr($g['student_name'], 0, 1));
                        $isPassed = strtolower($g['latest_result']) === 'passed';
                        $bestPct  = $g['best_percentage'];
                        $pctClass = $bestPct !== null ? ($bestPct >= 75 ? 'high' : ($bestPct >= 40 ? 'mid' : 'low')) : '';
                        $latest   = $g['latest_exam'];
                        $latestDate = '—';
                        if (($latest['exam_date'] ?? '') && $latest['exam_date'] !== '—') {
                            try { $latestDate = date('d M Y', strtotime($latest['exam_date'])); } catch (Exception $e) { $latestDate = $latest['exam_date']; }
                        }
                        $rowId = 'sub_' . $i;
                    ?>
                    <!-- Master row -->
                    <tr class="sm-master" data-search="<?= htmlspecialchars(strtolower($g['student_name'] . ' ' . $g['identifier'] . ' ' . $g['course'])) ?>" onclick="toggleSub('<?= $rowId ?>', this)">
                        <td>
                            <button class="sm-expand-icon" id="icon_<?= $rowId ?>">▸</button>
                        </td>
                        <td style="color:var(--text-secondary);font-size:.78rem"><?= $i + 1 ?></td>
                        <td>
                            <div class="sm-stu-cell">
                                <?php if ($hasPhoto): ?>
                                    <img src="../<?= htmlspecialchars($g['photo']) ?>" class="sm-stu-photo" alt="">
                                <?php else: ?>
                                    <div class="sm-stu-initials"><?= $initial ?></div>
                                <?php endif; ?>
                                <div>
                                    <div class="sm-stu-name"><?= htmlspecialchars($g['student_name']) ?></div>
                                </div>
                            </div>
                        </td>
                        <td><code style="font-size:.75rem;background:#f1f5f9;padding:.15rem .4rem;border-radius:4px"><?= htmlspecialchars($g['identifier']) ?></code></td>
                        <td style="font-size:.82rem;max-width:160px"><?= htmlspecialchars($g['course']) ?></td>
                        <td style="font-size:.82rem"><?= $latestDate ?></td>
                        <td>
                            <?php if ($bestPct !== null): ?>
                            <div style="display:flex;align-items:center;gap:.3rem">
                                <div class="sm-pct-bar"><div class="sm-pct-fill <?= $pctClass ?>" style="width:<?= min($bestPct, 100) ?>%"></div></div>
                                <span style="font-size:.78rem;font-weight:700;color:<?= $pctClass==='high'?'var(--sm-green)':($pctClass==='mid'?'var(--sm-amber)':'var(--sm-red)') ?>"><?= $bestPct ?>%</span>
                            </div>
                            <?php else: ?>
                                <span style="color:#94a3b8">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="sm-badge sm-badge-<?= $isPassed ? 'passed' : 'failed' ?>">
                                <span class="sm-badge-dot"></span>
                                <?= htmlspecialchars($g['latest_result']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="sm-attempts" title="Click to view all attempts">
                                📝 <?= $g['total_attempts'] ?> attempt<?= $g['total_attempts'] > 1 ? 's' : '' ?>
                            </span>
                        </td>
                    </tr>
                    <!-- Expandable sub-row with all exam history -->
                    <tr class="sm-sub-row" id="<?= $rowId ?>">
                        <td colspan="9">
                            <div class="sm-sub-label">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                Exam History — <?= htmlspecialchars($g['student_name']) ?>
                            </div>
                            <table class="sm-sub-table">
                                <thead><tr>
                                    <th>#</th>
                                    <th>Exam Title</th>
                                    <th>Subject / Course</th>
                                    <th>Date</th>
                                    <th>Marks</th>
                                    <th>Percentage</th>
                                    <th>Result</th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($g['exams'] as $ei => $ex):
                                    $ePct = $ex['percentage'];
                                    if ($ePct === null && is_numeric($ex['marks_obtained']) && is_numeric($ex['total_marks']) && $ex['total_marks'] > 0)
                                        $ePct = round(($ex['marks_obtained'] / $ex['total_marks']) * 100, 1);
                                    $ePctClass = $ePct !== null ? ($ePct >= 75 ? 'high' : ($ePct >= 40 ? 'mid' : 'low')) : '';
                                    $ePass = strtolower($ex['result']) === 'passed';
                                    $eDate = '—';
                                    if (($ex['exam_date'] ?? '') && $ex['exam_date'] !== '—') {
                                        try { $eDate = date('d M Y', strtotime($ex['exam_date'])); } catch (Exception $e2) { $eDate = $ex['exam_date']; }
                                    }
                                ?>
                                <tr>
                                    <td style="color:var(--text-secondary)"><?= $ei + 1 ?></td>
                                    <td style="font-weight:600"><?= htmlspecialchars($ex['exam_title']) ?></td>
                                    <td><?= htmlspecialchars($ex['course']) ?></td>
                                    <td><?= $eDate ?></td>
                                    <td style="font-weight:700">
                                        <?php if (is_numeric($ex['marks_obtained'])): ?>
                                            <span style="color:<?= $ePass ? 'var(--sm-green)' : 'var(--sm-red)' ?>"><?= $ex['marks_obtained'] ?></span><span style="color:var(--text-secondary)"> / <?= $ex['total_marks'] ?></span>
                                        <?php else: ?>
                                            <span style="color:#94a3b8">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($ePct !== null): ?>
                                        <div style="display:flex;align-items:center;gap:.3rem">
                                            <div class="sm-pct-bar"><div class="sm-pct-fill <?= $ePctClass ?>" style="width:<?= min($ePct, 100) ?>%"></div></div>
                                            <span style="font-size:.75rem;font-weight:700;color:<?= $ePctClass==='high'?'var(--sm-green)':($ePctClass==='mid'?'var(--sm-amber)':'var(--sm-red)') ?>"><?= $ePct ?>%</span>
                                        </div>
                                        <?php else: ?>
                                            <span style="color:#94a3b8">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="sm-badge sm-badge-<?= $ePass ? 'passed' : 'failed' ?>">
                                            <span class="sm-badge-dot"></span><?= htmlspecialchars($ex['result']) ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>

        <?php endif; ?>

    </div>
</main>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
// ── Toggle sub-row ──────────────────────────────────────────────────────────
function toggleSub(id, masterRow) {
    const subRow = document.getElementById(id);
    const icon   = document.getElementById('icon_' + id);
    if (!subRow) return;
    const isOpen = subRow.classList.contains('open');
    subRow.classList.toggle('open');
    icon.classList.toggle('open');
    icon.textContent = isOpen ? '▸' : '▾';
}

// ── Search ──────────────────────────────────────────────────────────────────
document.getElementById('smSearch').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#smTable tbody tr.sm-master').forEach(r => {
        const match = (r.dataset.search || '').includes(q);
        r.style.display = match ? '' : 'none';
        // Also hide the sub-row if master is hidden
        const subId = r.querySelector('.sm-expand-icon')?.id?.replace('icon_', '');
        if (subId) {
            const sub = document.getElementById(subId);
            if (sub && !match) { sub.classList.remove('open'); sub.style.display = 'none'; }
            else if (sub && match) { sub.style.display = ''; }
        }
    });
});
</script>
</body>
</html>
