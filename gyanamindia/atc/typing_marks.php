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
        .tm-note { background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;border-radius:12px;padding:.85rem 1rem;font-size:.88rem;font-weight:600;margin-bottom:1.15rem;line-height:1.45 }
        .tm-ok { background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:10px;padding:.7rem 1rem;margin-bottom:1rem;font-weight:700 }
        .tm-err { background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:.7rem 1rem;margin-bottom:1rem;font-weight:700 }
        .tm-card { background:#fff;border:1.5px solid var(--border-color);border-radius:14px;padding:1.1rem 1.2rem;margin-bottom:1rem }
        .tm-card h3 { margin:0 0 .35rem;font-size:1rem;font-weight:800 }
        .tm-meta { font-size:.78rem;color:#64748b;font-weight:600;margin-bottom:.85rem }
        .tm-grid { display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.65rem }
        .tm-field label { display:block;font-size:.72rem;font-weight:700;color:#475569;margin-bottom:.25rem }
        .tm-field input { width:100%;height:38px;border:1.5px solid #e2e8f0;border-radius:8px;padding:0 .6rem;font-weight:700 }
        .tm-actions { display:flex;align-items:center;gap:.75rem;margin-top:.9rem;flex-wrap:wrap }
        .tm-btn { height:38px;padding:0 1rem;border:none;border-radius:8px;background:#059669;color:#fff;font-weight:800;cursor:pointer }
        .badge { display:inline-block;padding:.2rem .55rem;border-radius:999px;font-size:.72rem;font-weight:800 }
        .badge-ok { background:#d1fae5;color:#065f46 }
        .badge-wait { background:#fef3c7;color:#92400e }
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
                    <p>Enter Statement of Marks particulars (same for all typing courses)</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>
        <div class="page-content">
            <div class="tm-note">
                Fill all six particulars for each typing student. Max marks:
                Speed 20 · Data Entry 30 · E-Mail 5 · Letter 15 · Statement 10 · Basics 20 (total 100).
                Certificate / marksheet printouts are issued by Admin only.
            </div>
            <?php if ($flashOk !== ''): ?><div class="tm-ok"><?= htmlspecialchars($flashOk) ?></div><?php endif; ?>
            <?php if ($flashErr !== ''): ?><div class="tm-err"><?= htmlspecialchars($flashErr) ?></div><?php endif; ?>

            <?php if (empty($rows)): ?>
                <div class="tm-card" style="color:#64748b;font-weight:600">No typing students found for this ATC.</div>
            <?php else: ?>
                <?php foreach ($rows as $r):
                    $parts = typingMarksheetParticulars((int)$r['wpm'], (int)$r['kph']);
                ?>
                <div class="tm-card">
                    <h3><?= htmlspecialchars($r['name']) ?></h3>
                    <div class="tm-meta">
                        <?= htmlspecialchars($r['reg_id'] ?: ('#' . $r['id'])) ?>
                        · <?= htmlspecialchars($r['course']) ?>
                        <?php if ($r['complete']): ?>
                            · <span class="badge badge-ok">Saved · <?= (int)$r['total'] ?> (<?= htmlspecialchars($r['grade']) ?>)</span>
                        <?php else: ?>
                            · <span class="badge badge-wait">Not saved</span>
                        <?php endif; ?>
                    </div>
                    <form method="post">
                        <input type="hidden" name="save_typing_marks" value="1">
                        <input type="hidden" name="admission_id" value="<?= (int)$r['id'] ?>">
                        <div class="tm-grid">
                            <?php foreach ($parts as $p):
                                $key = (string)$p['key'];
                                $val = $r['by_key'][$key] ?? '';
                            ?>
                            <div class="tm-field">
                                <label><?= htmlspecialchars((string)$p['label']) ?> (max <?= (int)$p['max'] ?>)</label>
                                <input type="number" name="marks[<?= htmlspecialchars($key) ?>]"
                                       min="0" max="<?= (int)$p['max'] ?>" required
                                       value="<?= $val !== '' ? (int)$val : '' ?>">
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="tm-actions">
                            <button type="submit" class="tm-btn">Save marks</button>
                        </div>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </main>
</div>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
