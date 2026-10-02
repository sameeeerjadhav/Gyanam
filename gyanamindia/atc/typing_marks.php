<?php
/**
 * ATC: Enter typing Statement of Marks particulars (same maxes for all typing courses).
 * Soft-copy cert/marksheet remain Admin-only.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$atcId = (int)($_SESSION['atc_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$flashOk = '';
$flashErr = '';

ensureAdmissionTypingMarksSchema($pdo);
$partsTemplate = typingMarksheetParticulars(30, 9000);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_typing_marks'])) {
    $admissionId = (int)($_POST['admission_id'] ?? 0);
    $raw = [];
    foreach ($partsTemplate as $p) {
        $key = (string)$p['key'];
        $raw[$key] = $_POST['marks'][$key] ?? '';
    }
    if ($admissionId <= 0) {
        $flashErr = 'Invalid student.';
    } else {
        $chk = $pdo->prepare("
            SELECT a.id, a.course, c.course_type
            FROM admissions a
            LEFT JOIN courses c ON c.course_name = a.course AND c.status = 'Active'
            WHERE a.id = ? AND a.atc_id = ? AND a.status = 'Active'
            LIMIT 1
        ");
        $chk->execute([$admissionId, $atcId]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $flashErr = 'Student not found for this ATC.';
        } elseif (!isTypingCourse($row['course_type'] ?? null, $row['course'] ?? null)) {
            $flashErr = 'These particulars apply only to Typing courses.';
        } else {
            $speeds = typingMarksheetSpeedDefaults($row['course'] ?? '');
            if (upsertAdmissionTypingMarks($pdo, $admissionId, $raw, $atcId, $userId, 'ATC CENTER', $speeds['wpm'], $speeds['kph'])) {
                $flashOk = 'Typing marks saved successfully.';
            } else {
                $flashErr = 'Could not save marks. Check each field is within its max.';
            }
        }
    }
}

$students = [];
try {
    $st = $pdo->prepare("
        SELECT a.id, a.registration_id, a.roll_no, a.first_name, a.middle_name, a.last_name,
               a.course, c.course_type
        FROM admissions a
        LEFT JOIN courses c ON c.course_name = a.course AND c.status = 'Active'
        WHERE a.atc_id = ? AND a.status = 'Active'
        ORDER BY a.first_name ASC, a.last_name ASC
    ");
    $st->execute([$atcId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) {
        if (isTypingCourse($s['course_type'] ?? null, $s['course'] ?? null)) {
            $students[] = $s;
        }
    }
} catch (Exception $e) {
    $students = [];
}

$marksMap = getAdmissionTypingMarksMap($pdo, array_column($students, 'id'));
$rows = [];
foreach ($students as $s) {
    $regId = trim((string)($s['registration_id'] ?? ''));
    if ($regId === '') {
        $regId = trim((string)($s['roll_no'] ?? ''));
    }
    $speeds = typingMarksheetSpeedDefaults($s['course'] ?? '');
    $saved = $marksMap[(int)$s['id']] ?? null;
    $fullName = trim(
        ($s['first_name'] ?? '') . ' ' .
        (!empty($s['middle_name']) ? $s['middle_name'] . ' ' : '') .
        ($s['last_name'] ?? '')
    );
    $rows[] = [
        'id' => (int)$s['id'],
        'name' => $fullName,
        'reg_id' => $regId,
        'course' => (string)($s['course'] ?? ''),
        'wpm' => $speeds['wpm'],
        'kph' => $speeds['kph'],
        'by_key' => $saved['by_key'] ?? [],
        'total' => $saved['total'] ?? null,
        'grade' => $saved['grade'] ?? '',
        'complete' => $saved !== null,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Typing Marks — ATC | Gyanam India</title>
    <?php include __DIR__ . '/../includes/head_fonts.php'; ?>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <style>
        .tm-note { background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;border-radius:12px;padding:.85rem 1rem;font-size:.84rem;font-weight:600;margin-bottom:1rem;line-height:1.45 }
        .tm-ok { background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:10px;padding:.7rem 1rem;margin-bottom:1rem;font-weight:700 }
        .tm-err { background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:.7rem 1rem;margin-bottom:1rem;font-weight:700 }
        .tm-kpi { display:grid;grid-template-columns:repeat(3,1fr);gap:.75rem;margin-bottom:1rem }
        .tm-kpi div { background:#fff;border:1px solid #e6eaf3;border-radius:14px;padding:.9rem 1rem }
        .tm-kpi strong { display:block;font-size:1.45rem;font-weight:800;color:#111827 }
        .tm-kpi span { font-size:.72rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#64748b }
        .tm-card { background:#fff;border:1px solid #e6eaf3;border-radius:14px;overflow:hidden }
        .tm-head { display:flex;align-items:center;justify-content:space-between;gap:.75rem;padding:.85rem 1rem;border-bottom:1px solid #eef1fd }
        .tm-head h3 { margin:0;font-size:.95rem;font-weight:800 }
        .tm-search { height:36px;border:1.5px solid #e2e8f0;border-radius:8px;padding:0 .7rem;font-weight:600;min-width:220px }
        .tm-table { width:100%;border-collapse:collapse;font-size:.8rem }
        .tm-table th { text-align:left;font-size:.68rem;letter-spacing:.03em;text-transform:uppercase;color:#64748b;padding:.65rem .5rem;background:#f8fafc;white-space:nowrap }
        .tm-table td { padding:.55rem .5rem;border-top:1px solid #f1f5f9;vertical-align:middle }
        .tm-name { font-weight:800;color:#111827 }
        .tm-sub { font-size:.72rem;color:#64748b;font-weight:600 }
        .tm-table input[type=number] { width:64px;height:32px;border:1.5px solid #e2e8f0;border-radius:8px;padding:0 .35rem;font-weight:700;text-align:center }
        .tm-btn { height:32px;padding:0 .75rem;border:none;border-radius:8px;background:#059669;color:#fff;font-weight:800;cursor:pointer }
        .tm-total { font-weight:800;color:#111827 }
        .badge { display:inline-block;padding:.15rem .45rem;border-radius:999px;font-size:.68rem;font-weight:800 }
        .badge-ok { background:#d1fae5;color:#065f46 }
        .badge-wait { background:#fef3c7;color:#92400e }
        @media (max-width:900px) { .tm-kpi { grid-template-columns:1fr } }
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
                    <h2>Typing Marks</h2>
                    <p>Statement of Marks particulars — total 100</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>
        <div class="page-content">
            <?php
            $tmSaved = 0;
            foreach ($rows as $r) {
                if (!empty($r['complete'])) {
                    $tmSaved++;
                }
            }
            $tmPending = count($rows) - $tmSaved;
            ?>
            <div class="tm-kpi">
                <div><strong><?= count($rows) ?></strong><span>Typing students</span></div>
                <div><strong><?= $tmSaved ?></strong><span>Marks saved</span></div>
                <div><strong><?= $tmPending ?></strong><span>Marks pending</span></div>
            </div>
            <div class="tm-note">
                Typing courses only. Six particulars, same maximums for every typing course:
                Speed 20 · Data Entry 30 · E-Mail 5 · Letter 15 · Statement 10 · Computer Basics 20.
                Total is out of 100. Certificate printouts stay with Admin.
            </div>
            <?php if ($flashOk !== ''): ?><div class="tm-ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
            <?php if ($flashErr !== ''): ?><div class="tm-err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

            <div class="tm-card">
                <div class="tm-head">
                    <h3>Typing Statement of Marks</h3>
                    <input type="search" class="tm-search" id="tmSearch" placeholder="Search name or reg ID" autocomplete="off">
                </div>
                <?php if (empty($rows)): ?>
                    <div style="padding:2rem 1rem;color:#64748b;font-weight:600">No typing students found for this centre.</div>
                <?php else: ?>
                <div style="overflow-x:auto" id="tmScroll">
                <table class="tm-table" id="tmTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Speed /20</th>
                            <th>Data Entry /30</th>
                            <th>E-Mail /5</th>
                            <th>Letter /15</th>
                            <th>Statement /10</th>
                            <th>Basics /20</th>
                            <th>Total</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $i => $r):
                        $parts = typingMarksheetParticulars((int)$r['wpm'], (int)$r['kph']);
                    ?>
                        <tr data-search="<?= htmlspecialchars(mb_strtolower($r['name'] . ' ' . $r['reg_id'] . ' ' . $r['course'])) ?>">
                            <td><?= $i + 1 ?></td>
                            <td>
                                <div class="tm-name"><?= htmlspecialchars($r['name']) ?></div>
                                <div class="tm-sub"><?= htmlspecialchars($r['reg_id'] ?: ('#' . $r['id'])) ?> · <?= htmlspecialchars($r['course']) ?></div>
                            </td>
                            <?php foreach ($parts as $p):
                                $key = (string)$p['key'];
                                $val = $r['by_key'][$key] ?? '';
                            ?>
                            <td>
                                <input form="tm-<?= (int)$r['id'] ?>" type="number" name="marks[<?= htmlspecialchars($key) ?>]"
                                       min="0" max="<?= (int)$p['max'] ?>" required
                                       title="<?= htmlspecialchars((string)$p['label']) ?> (max <?= (int)$p['max'] ?>)"
                                       value="<?= $val !== '' ? (int)$val : '' ?>">
                            </td>
                            <?php endforeach; ?>
                            <td class="tm-total"><?= $r['complete'] ? ((int)$r['total'] . ' · ' . htmlspecialchars((string)$r['grade'])) : '—' ?></td>
                            <td>
                                <form id="tm-<?= (int)$r['id'] ?>" method="post">
                                    <input type="hidden" name="save_typing_marks" value="1">
                                    <input type="hidden" name="admission_id" value="<?= (int)$r['id'] ?>">
                                    <button type="submit" class="tm-btn">Save</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<script src="../assets/js/dashboard.js"></script>
<script src="../assets/js/list-pager.js"></script>
<script>
const tmPager = initListPager({
    rows: '#tmTable tbody tr',
    mount: '#tmScroll',
    label: 'students',
    match: function (row) {
        const q = (document.getElementById('tmSearch')?.value || '').trim().toLowerCase();
        return !q || (row.dataset.search || '').indexOf(q) !== -1;
    }
});
document.getElementById('tmSearch')?.addEventListener('input', function () {
    tmPager.refresh(true);
});
</script>
</body>
</html>
