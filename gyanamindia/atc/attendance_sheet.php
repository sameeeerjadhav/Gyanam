<?php
/**
 * ATC — exam-day attendance sheet.
 * Students scheduled on the chosen date, then print / save as PDF.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
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

$date = trim((string)($_GET['date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

$dates = [];
try {
    $ds = $pdo->prepare("SELECT exam_date, COUNT(*) AS n
                         FROM exam_schedules
                         WHERE atc_id = ? AND exam_date IS NOT NULL
                         GROUP BY exam_date
                         ORDER BY exam_date DESC");
    $ds->execute([$atcId]);
    $dates = $ds->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $dates = [];
}

$rows = [];
try {
    $q = $pdo->prepare("SELECT a.roll_no, a.registration_id, a.photo
                        FROM exam_schedules es
                        JOIN admissions a ON a.id = es.admission_id AND a.atc_id = es.atc_id
                        WHERE es.atc_id = ? AND es.exam_date = ? AND a.status = 'Active'
                        ORDER BY a.roll_no ASC, a.registration_id ASC");
    $q->execute([$atcId, $date]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $student) {
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
} catch (Exception $e) {
    $rows = [];
}

$dateLabel = date('d F Y, l', strtotime($date));
$sheetMode = isset($_GET['sheet']) && $_GET['sheet'] === '1';
if ($sheetMode) {
    renderAttendanceSheetDocument([
        'date_label' => $dateLabel,
        'institute' => $institute,
        'code' => $code,
        'rows' => $rows,
        'print' => isset($_GET['print']) && $_GET['print'] === '1',
        'toolbar' => !(isset($_GET['iframe']) && $_GET['iframe'] === '1'),
    ]);
    exit;
}

$sheetUrl = 'attendance_sheet.php?sheet=1&date=' . rawurlencode($date);
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
        .as-bar { display: flex; flex-wrap: wrap; gap: .75rem; align-items: flex-end; background: #fff; border: 1.5px solid #e5e7eb; border-radius: 14px; padding: 1rem 1.1rem; margin-bottom: 1rem; }
        .as-bar label { display: block; font-size: .72rem; font-weight: 800; color: #6b7280; text-transform: uppercase; letter-spacing: .04em; margin-bottom: .3rem; }
        .as-bar input, .as-bar select { height: 40px; border: 1.5px solid #e5e7eb; border-radius: 9px; padding: 0 .75rem; font-family: inherit; font-size: .88rem; background: #fff; }
        .as-bar button, .as-bar a.btn { height: 40px; padding: 0 1rem; border-radius: 9px; border: none; background: #1e3a8a; color: #fff; font-weight: 800; font-size: .82rem; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; }
        .as-bar a.ghost { background: #fff; color: #1e3a8a; border: 1.5px solid #c7d2fe; }
        .as-note { font-size: .82rem; color: #64748b; margin: 0 0 1rem; }
        .as-frame { width: 100%; height: 1220px; border: 1.5px solid #e5e7eb; border-radius: 14px; background: #e5e7eb; }
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
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>
        <div class="as-wrap">
            <form class="as-bar" method="get" action="attendance_sheet.php">
                <div>
                    <label for="asDate">Exam date</label>
                    <input type="date" id="asDate" name="date" value="<?= htmlspecialchars($date) ?>" required>
                </div>
                <?php if ($dates): ?>
                <div>
                    <label for="asKnown">Scheduled days</label>
                    <select id="asKnown" onchange="if (this.value) { document.getElementById('asDate').value = this.value; this.form.submit(); }">
                        <option value="">Pick a day with exams</option>
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
                <a class="btn ghost" href="<?= htmlspecialchars($sheetUrl) ?>" target="_blank" rel="noopener">Open sheet</a>
            </form>
            <p class="as-note"><?= count($rows) ?> student<?= count($rows) === 1 ? '' : 's' ?> scheduled on <?= htmlspecialchars($dateLabel) ?> at <?= htmlspecialchars($institute) ?>.</p>
            <iframe class="as-frame" title="Attendance sheet preview" src="<?= htmlspecialchars($sheetUrl . '&iframe=1') ?>"></iframe>
        </div>
    </main>
</div>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
