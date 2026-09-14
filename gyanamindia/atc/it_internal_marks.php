<?php
/**
 * ATC: Enter / edit internal marks out of 60 for IT (GIIT) students.
 * Exam marks (/40) come from the exam portal; ATC cannot download cert/marksheet.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
if (file_exists(__DIR__ . '/../includes/exam_integration.php')) {
    require_once __DIR__ . '/../includes/exam_integration.php';
}

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$atcId = (int)($_SESSION['atc_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$flashOk = '';
$flashErr = '';

ensureAdmissionAtcMarksSchema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_atc_marks'])) {
    $admissionId = (int)($_POST['admission_id'] ?? 0);
    $marks = (int)($_POST['atc_marks'] ?? -1);
    if ($admissionId <= 0) {
        $flashErr = 'Invalid student.';
    } elseif ($marks < 0 || $marks > 60) {
        $flashErr = 'ATC marks must be between 0 and 60.';
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
        } elseif (!isGiitItCourse($row['course_type'] ?? null, $row['course'] ?? null)) {
            $flashErr = 'ATC marks out of 60 apply only to IT courses.';
        } elseif (upsertAdmissionAtcMarks($pdo, $admissionId, $marks, $atcId, $userId, 'ATC CENTER')) {
            $flashOk = 'ATC marks saved successfully.';
        } else {
            $flashErr = 'Could not save marks. Please try again.';
        }
    }
}

$integrationReady = function_exists('examIntegrationReady') && examIntegrationReady();
$passIndex = [];
if ($integrationReady) {
    $res = fetchAllExamResultsComplete();
    if ($res['success'] && isset($res['data']['submissions'])) {
        $passIndex = buildExamPassIndex($res['data']['submissions']);
    }
}

$students = [];
try {
    $st = $pdo->prepare("
        SELECT a.id, a.registration_id, a.roll_no, a.first_name, a.middle_name, a.last_name,
               a.course, a.photo, c.course_type
        FROM admissions a
        LEFT JOIN courses c ON c.course_name = a.course AND c.status = 'Active'
        WHERE a.atc_id = ? AND a.status = 'Active'
        ORDER BY a.first_name ASC, a.last_name ASC
    ");
    $st->execute([$atcId]);
    $all = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($all as $s) {
        if (!isGiitItCourse($s['course_type'] ?? null, $s['course'] ?? null)) {
            continue;
        }
        $students[] = $s;
    }
} catch (Exception $e) {
    $students = [];
}

$marksMap = getAdmissionAtcMarksMap($pdo, array_column($students, 'id'));

$rows = [];
foreach ($students as $s) {
    $regId = trim((string)($s['registration_id'] ?? ''));
    if ($regId === '') {
        $regId = trim((string)($s['roll_no'] ?? ''));
    }
    $pass = ($regId !== '' && isset($passIndex[$regId])) ? $passIndex[$regId] : null;
    $atcMarks = array_key_exists((int)$s['id'], $marksMap) ? $marksMap[(int)$s['id']] : null;
    $composed = composeItCertificateScores($pass, $atcMarks);
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
        'exam_pct' => $pass ? (int)$pass['score'] : null,
        'exam_40' => $pass ? (int)($pass['exam_40'] ?? 0) : null,
        'exam_passed' => $pass !== null,
        'atc_marks' => $atcMarks,
        'total' => $composed['total'],
        'grade' => $composed['grade'],
        'complete' => $composed['complete'],
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>IT Internal Marks — ATC | Gyanam India</title>
    <?php include __DIR__ . '/../includes/head_fonts.php'; ?>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <style>
        .im-note {
            background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;border-radius:12px;
            padding:.85rem 1rem;font-size:.88rem;font-weight:600;margin-bottom:1.15rem;line-height:1.45;
        }
        .im-flash-ok { background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:10px;padding:.7rem 1rem;margin-bottom:1rem;font-weight:700; }
        .im-flash-err { background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:.7rem 1rem;margin-bottom:1rem;font-weight:700; }
        .im-table-wrap { background:#fff;border:1.5px solid var(--border-color);border-radius:14px;overflow:auto }
        .im-table { width:100%;border-collapse:collapse;font-size:.84rem }
        .im-table th { padding:.75rem .9rem;text-align:left;font-size:.68rem;font-weight:800;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;background:#fafbfc;border-bottom:1px solid var(--border-color);white-space:nowrap }
        .im-table td { padding:.7rem .9rem;border-bottom:1px solid #f3f4f6;vertical-align:middle }
        .im-table tr:last-child td { border-bottom:none }
        .im-input { width:72px;height:36px;border:1.5px solid #e2e8f0;border-radius:8px;padding:0 .5rem;font-weight:700;text-align:center }
        .im-btn { height:36px;padding:0 .85rem;border:none;border-radius:8px;background:#059669;color:#fff;font-weight:800;font-size:.78rem;cursor:pointer }
        .im-btn:hover { background:#047857 }
        .badge { display:inline-block;padding:.2rem .55rem;border-radius:999px;font-size:.72rem;font-weight:800 }
        .badge-ok { background:#d1fae5;color:#065f46 }
        .badge-wait { background:#fef3c7;color:#92400e }
        .badge-no { background:#f3f4f6;color:#6b7280 }
        .stu-name { font-weight:800 }
        .stu-id { font-size:.72rem;color:#9ca3af;font-family:monospace }
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
                    <h2>IT Internal Marks</h2>
                    <p>Enter ATC marks out of 60 (Exam is out of 40)</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">
            <div class="im-note">
                For <strong>IT courses only</strong>: Main Exam = marks out of <strong>40</strong> (from exam portal).
                You enter internal marks out of <strong>60</strong>. Total = Exam + ATC (100).
                Soft-copy certificate / marksheet are issued by Admin only.
            </div>

            <?php if ($flashOk !== ''): ?><div class="im-flash-ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
            <?php if ($flashErr !== ''): ?><div class="im-flash-err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

            <div class="im-table-wrap">
                <table class="im-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Course</th>
                            <th>Exam %</th>
                            <th>Exam /40</th>
                            <th>ATC /60</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="8" style="padding:2rem;text-align:center;color:#6b7280;font-weight:600">No IT students found for this ATC.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td>
                                <div class="stu-name"><?= htmlspecialchars($r['name']) ?></div>
                                <div class="stu-id"><?= htmlspecialchars($r['reg_id'] ?: ('#' . $r['id'])) ?></div>
                            </td>
                            <td><?= htmlspecialchars($r['course']) ?></td>
                            <td><?= $r['exam_pct'] !== null ? ((int)$r['exam_pct'] . '%') : '—' ?></td>
                            <td><?= $r['exam_40'] !== null ? ((int)$r['exam_40'] . '/40') : '—' ?></td>
                            <td>
                                <form method="post" style="display:flex;align-items:center;gap:.4rem;margin:0">
                                    <input type="hidden" name="save_atc_marks" value="1">
                                    <input type="hidden" name="admission_id" value="<?= (int)$r['id'] ?>">
                                    <input class="im-input" type="number" name="atc_marks" min="0" max="60" required
                                           value="<?= $r['atc_marks'] !== null ? (int)$r['atc_marks'] : '' ?>"
                                           placeholder="0–60">
                            </td>
                            <td>
                                <?php if ($r['total'] !== null): ?>
                                    <strong><?= (int)$r['total'] ?></strong>
                                    <?php if ($r['grade'] !== ''): ?> (<?= htmlspecialchars($r['grade']) ?>)<?php endif; ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($r['complete']): ?>
                                    <span class="badge badge-ok">Ready</span>
                                <?php elseif ($r['exam_passed'] && $r['atc_marks'] === null): ?>
                                    <span class="badge badge-wait">Need ATC marks</span>
                                <?php elseif (!$r['exam_passed'] && $r['atc_marks'] !== null): ?>
                                    <span class="badge badge-wait">Need exam pass</span>
                                <?php else: ?>
                                    <span class="badge badge-no">Incomplete</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                    <button type="submit" class="im-btn">Save</button>
                                </form>
                            </td>
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
</body>
</html>
