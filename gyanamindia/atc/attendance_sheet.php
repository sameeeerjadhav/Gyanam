<?php
/**
 * ATC — exam-day attendance sheet.
 * Students scheduled on the chosen date, then print / save as PDF.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
require_once __DIR__ . '/../includes/attendance_sheet.php';

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$atcId = (int)($_SESSION['atc_id'] ?? 0);
$userName = sanitize(getUserName());

$atc = null;
try {
    $st = $pdo->prepare('SELECT id, name, atc_code FROM atc_centers WHERE id = ? LIMIT 1');
    $st->execute([$atcId]);
    $atc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {
    $atc = null;
}
$institute = trim((string)($atc['name'] ?? ''));
if ($institute === '') {
    $institute = $userName !== '' ? $userName : 'ATC Centre';
}
$code = trim((string)($atc['atc_code'] ?? ''));

$courseFilter = trim((string)($_GET['course'] ?? 'all'));
$slotFilter = trim((string)($_GET['slot'] ?? 'all'));
$allowedSlots = ['all', 'Morning', 'Afternoon', 'Evening'];
if (!in_array($slotFilter, $allowedSlots, true)) {
    $slotFilter = 'all';
}

$courses = [];
$dates = [];
try {
    $cs = $pdo->prepare("SELECT DISTINCT course
                         FROM admissions
                         WHERE atc_id = ? AND status = 'Active' AND course <> ''
                         ORDER BY course ASC");
    $cs->execute([$atcId]);
    $courses = $cs->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Exception $e) {
    $courses = [];
}
if ($courseFilter !== 'all' && !in_array($courseFilter, $courses, true)) {
    $courseFilter = 'all';
}

try {
    $ds = $pdo->prepare("SELECT es.exam_date, COUNT(*) AS n
                         FROM exam_schedules es
                         JOIN admissions a ON a.id = es.admission_id AND a.atc_id = es.atc_id
                         WHERE es.atc_id = ? AND a.status = 'Active' AND es.exam_date IS NOT NULL
                         GROUP BY es.exam_date
                         ORDER BY es.exam_date DESC");
    $ds->execute([$atcId]);
    $dates = $ds->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $dates = [];
}

$date = trim((string)($_GET['date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = '';
}
if ($date === '' && $dates) {
    $today = date('Y-m-d');
    $date = (string)$dates[0]['exam_date'];
    foreach (array_reverse($dates) as $d) {
        if ((string)$d['exam_date'] >= $today) {
            $date = (string)$d['exam_date'];
            break;
        }
    }
}
if ($date === '') {
    $date = date('Y-m-d');
}

$buildRows = static function (array $students, string $institute): array {
    $rows = [];
    foreach ($students as $student) {
        $roll = trim((string)($student['roll_no'] ?? ''));
        if ($roll === '') {
            $roll = trim((string)($student['registration_id'] ?? ''));
        }
        $photo = '';
        $rel = trim((string)($student['photo'] ?? ''));
        if ($rel !== '' && is_file(__DIR__ . '/../' . $rel)) {
            $photo = '../' . $rel;
        }
        $rows[] = [
            'roll' => $roll !== '' ? $roll : '—',
            'photo' => $photo,
            'institute' => $institute,
        ];
    }
    return $rows;
};

$rows = [];
$sheetNotice = '';
try {
    $sql = "SELECT a.roll_no, a.registration_id, a.photo
            FROM exam_schedules es
            JOIN admissions a ON a.id = es.admission_id AND a.atc_id = es.atc_id
            WHERE es.atc_id = ? AND es.exam_date = ? AND a.status = 'Active'";
    $params = [$atcId, $date];
    if ($courseFilter !== 'all') {
        $sql .= " AND a.course = ?";
        $params[] = $courseFilter;
    }
    if ($slotFilter !== 'all') {
        $sql .= " AND es.exam_slot = ?";
        $params[] = $slotFilter;
    }
    $sql .= " ORDER BY a.roll_no ASC, a.registration_id ASC";
    $q = $pdo->prepare($sql);
    $q->execute($params);
    $rows = $buildRows($q->fetchAll(PDO::FETCH_ASSOC) ?: [], $institute);
} catch (Exception $e) {
    $rows = [];
}

if ($rows === []) {
    $dayHasExam = false;
    foreach ($dates as $d) {
        if ((string)($d['exam_date'] ?? '') === $date) {
            $dayHasExam = true;
            break;
        }
    }
    $sheetNotice = $dayHasExam
        ? 'No students match the selected course and slot.'
        : 'No exam is scheduled for this day.';
}

$dateLabel = date('d F Y, l', strtotime($date));
$sheetMode = isset($_GET['sheet']) && $_GET['sheet'] === '1';
if ($sheetMode) {
    renderAttendanceSheetDocument([
        'date_label' => $dateLabel,
        'institute' => $institute,
        'code' => $code,
        'course_label' => $courseFilter !== 'all' ? $courseFilter : '',
        'slot_label' => $slotFilter !== 'all' ? $slotFilter : '',
        'notice' => $sheetNotice,
        'rows' => $rows,
        'print' => isset($_GET['print']) && $_GET['print'] === '1',
        'toolbar' => !(isset($_GET['iframe']) && $_GET['iframe'] === '1'),
    ]);
    exit;
}

$sheetQuery = http_build_query([
    'sheet' => '1',
    'date' => $date,
    'course' => $courseFilter,
    'slot' => $slotFilter,
]);
$sheetUrl = 'attendance_sheet?' . $sheetQuery;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Sheet — ATC | Gyanam India</title>
    <?php include __DIR__ . '/../includes/head_fonts.php'; ?>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <style>
        .as-wrap { padding: 1.5rem 1.75rem 2rem; }
        .as-bar { display: flex; flex-wrap: wrap; gap: .75rem; align-items: flex-end; background: #fff; border: 1.5px solid #c7d2fe; border-radius: 14px; padding: 1rem 1.1rem; margin-bottom: 1rem; box-shadow: 0 1px 3px rgba(15,23,42,.06); }
        .as-bar label { display: block; font-size: .72rem; font-weight: 800; color: #4338ca; margin-bottom: .3rem; }
        .as-bar input, .as-bar select { height: 40px; min-width: 180px; border: 1.5px solid #e5e7eb; border-radius: 9px; padding: 0 .75rem; font-family: inherit; font-size: .88rem; background: #fff; color: #111827; }
        .as-bar button, .as-bar a.btn { height: 40px; padding: 0 1rem; border-radius: 9px; border: none; background: #1e3a8a; color: #fff; font-weight: 800; font-size: .82rem; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
        .as-bar a.ghost { background: #fff; color: #1e3a8a; border: 1.5px solid #c7d2fe; }
        .as-note { font-size: .82rem; color: #334155; margin: 0 0 1rem; background: #eef2ff; border: 1px solid #c7d2fe; border-radius: 10px; padding: .7rem .9rem; }
        .as-preview { border-radius: 14px; overflow: auto; }
        .as-preview .as-page,
        .as-preview .as-page * { font-family: "Times New Roman", Times, serif !important; }
        .as-preview td.roll, .as-preview .as-nophoto { font-family: Arial, Helvetica, sans-serif !important; }
        .as-preview .as-page { margin: 16px auto; }
        <?= attendanceSheetCss() ?>
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
                    <h2>Attendance Sheet</h2>
                    <p>Roll number, photo, institute, and signature for one exam day</p>
                </div>
            </div>
            <div class="header-right">
                <?php try { include __DIR__ . '/../includes/notification_bell.php'; } catch (Throwable $e) {} ?>
                <?php try { include __DIR__ . '/../includes/profile_dropdown.php'; } catch (Throwable $e) {} ?>
            </div>
        </header>
        <div class="as-wrap">
            <form class="as-bar" method="get" action="attendance_sheet">
                <div>
                    <label for="asDate">Exam Date</label>
                    <input type="date" id="asDate" name="date" value="<?= htmlspecialchars($date) ?>" required>
                </div>
                <div>
                    <label for="asCourse">Course</label>
                    <select id="asCourse" name="course">
                        <option value="all">All Courses</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?= htmlspecialchars((string)$course) ?>" <?= $courseFilter === (string)$course ? 'selected' : '' ?>><?= htmlspecialchars((string)$course) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="asSlot">Slot</label>
                    <select id="asSlot" name="slot">
                        <?php foreach (['all' => 'All Slots', 'Morning' => 'Morning', 'Afternoon' => 'Afternoon', 'Evening' => 'Evening'] as $slotValue => $slotLabel): ?>
                            <option value="<?= htmlspecialchars($slotValue) ?>" <?= $slotFilter === $slotValue ? 'selected' : '' ?>><?= htmlspecialchars($slotLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($dates): ?>
                <div>
                    <label for="asKnown">Scheduled Days</label>
                    <select id="asKnown" onchange="if (this.value) { document.getElementById('asDate').value = this.value; }">
                        <option value="">Choose A Scheduled Day</option>
                        <?php foreach ($dates as $d): ?>
                            <option value="<?= htmlspecialchars((string)$d['exam_date']) ?>" <?= (string)$d['exam_date'] === $date ? 'selected' : '' ?>>
                                <?= htmlspecialchars(date('d M Y', strtotime((string)$d['exam_date']))) ?> (<?= (int)$d['n'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <button type="submit">Generate</button>
                <a class="btn" href="<?= htmlspecialchars($sheetUrl . '&print=1') ?>" target="_blank" rel="noopener">Download PDF</a>
                <a class="btn ghost" href="<?= htmlspecialchars($sheetUrl) ?>" target="_blank" rel="noopener">Open Sheet</a>
            </form>
            <p class="as-note"><?= $sheetNotice !== '' ? htmlspecialchars($sheetNotice) : (count($rows) . ' student' . (count($rows) === 1 ? '' : 's') . ' scheduled on ' . htmlspecialchars($dateLabel) . '.') ?></p>
            <div class="as-sheet as-preview">
                <?php renderAttendanceSheetPages([
                    'date_label' => $dateLabel,
                    'institute' => $institute,
                    'code' => $code,
                    'course_label' => $courseFilter !== 'all' ? $courseFilter : '',
                    'slot_label' => $slotFilter !== 'all' ? $slotFilter : '',
                    'notice' => $sheetNotice,
                    'rows' => $rows,
                ]); ?>
            </div>
        </div>
    </main>
</div>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
