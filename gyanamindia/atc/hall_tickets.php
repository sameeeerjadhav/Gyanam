<?php
/**
 * Gyanam Portal — ATC: Hall Tickets
 * Task I: Only share-paid students shown; photo-missing students get an upload panel.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
if (file_exists(__DIR__ . '/../includes/exam_integration.php')) {
    require_once __DIR__ . '/../includes/exam_integration.php';
}

requireLogin(['ATC CENTER']);

// ── AJAX: Upload student photo (Task I) ───────────────────────────────────────
if (isset($_POST['ajax_upload_photo'])) {
    header('Content-Type: application/json');
    $pdo   = getDBConnection();
    $sid   = intval($_POST['student_id'] ?? 0);
    $aId   = $_SESSION['atc_id'] ?? null;

    // Verify student belongs to this ATC
    $chk = $pdo->prepare('SELECT id FROM admissions WHERE id=? AND atc_id=?');
    $chk->execute([$sid, $aId]);
    if (!$chk->fetch()) {
        echo json_encode(['success'=>false,'message'=>'Unauthorized']);
        exit;
    }

    if (empty($_FILES['photo']['tmp_name'])) {
        echo json_encode(['success'=>false,'message'=>'No file selected.']);
        exit;
    }

    $allowed = ['image/jpeg','image/png','image/gif','image/webp'];
    if (!in_array($_FILES['photo']['type'], $allowed)) {
        echo json_encode(['success'=>false,'message'=>'Only JPG/PNG/GIF/WebP allowed.']);
        exit;
    }

    $ext  = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
    $filename = 'student_' . $sid . '_' . time() . '.' . $ext;
    $dir  = __DIR__ . '/../uploads/photos/';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $dest = $dir . $filename;

    if (move_uploaded_file($_FILES['photo']['tmp_name'], $dest)) {
        $relPath = 'uploads/photos/' . $filename;
        $upd = $pdo->prepare('UPDATE admissions SET photo=? WHERE id=? AND atc_id=?');
        $upd->execute([$relPath, $sid, $aId]);
        echo json_encode(['success'=>true,'message'=>'Photo uploaded successfully!']);
    } else {
        echo json_encode(['success'=>false,'message'=>'Upload failed. Check folder permissions.']);
    }
    exit;
}

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$atcId = $_SESSION['atc_id'] ?? null;

// ── Build set of student IDs whose share has been paid by this ATC ──────────
$paidStudentIds = [];
try {
    $sp = $pdo->prepare("SELECT student_ids FROM share_payments WHERE atc_id = ? AND status = 'Completed'");
    $sp->execute([$atcId]);
    foreach ($sp->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $ids = json_decode($json, true);
        if (is_array($ids)) {
            foreach ($ids as $id) {
                $paidStudentIds[intval($id)] = true;
            }
        }
    }
} catch (Exception $e) { /* share_payments table may not exist yet */ }

// ── Fetch students ────────────────────────────────────────────────────────────
$searchTerm   = $_GET['search'] ?? '';
$courseFilter = $_GET['course'] ?? 'all';

$sql    = "SELECT a.*, es.exam_date AS sched_exam_date, es.exam_time AS sched_exam_time, es.exam_slot AS sched_exam_slot, es.exam_hall AS sched_exam_hall
           FROM admissions a
           LEFT JOIN exam_schedules es ON es.admission_id = a.id AND es.atc_id = a.atc_id
           WHERE a.atc_id = ? AND a.status = 'Active'";
$params = [$atcId];

if ($searchTerm) {
    $sql .= " AND (a.roll_no LIKE ? OR a.first_name LIKE ? OR a.last_name LIKE ?)";
    $searchParam = "%$searchTerm%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam]);
}

if ($courseFilter !== 'all') {
    $sql .= " AND a.course = ?";
    $params[] = $courseFilter;
}

$sql .= " ORDER BY a.roll_no ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Annotate each student with share_paid flag, then FILTER to share-paid only (Task I)
foreach ($students as &$s) {
    $s['share_paid'] = isset($paidStudentIds[intval($s['id'])]);
}
unset($s);

// Task I: Only show students whose share is paid
$students = array_values(array_filter($students, fn($s) => $s['share_paid']));

// ── Counts for KPI cards ─────────────────────────────────────────────────────
$sharePaidCount   = count(array_filter($students, fn($s) => $s['share_paid']));
$shareUnpaidCount = count($students) - $sharePaidCount;
$noPhotoCount     = count(array_filter($students, fn($s) => empty($s['photo'])));
// Eligible = share paid AND has photo
$eligibleCount    = count(array_filter($students, fn($s) => $s['share_paid'] && !empty($s['photo'])));

// ── Get courses for filter ────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT DISTINCT course FROM admissions WHERE atc_id = ? ORDER BY course");
$stmt->execute([$atcId]);
$courses = $stmt->fetchAll(PDO::FETCH_COLUMN);

// ── Get ATC details ───────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM atc_centers WHERE id = ?");
$stmt->execute([$atcId]);
$atcDetails = $stmt->fetch(PDO::FETCH_ASSOC);

// ── Fetch exam assignments from Exam Portal for hall ticket schedule ──────────
$examAssignments = []; // keyed by student identifier
try {
    if (function_exists('fetchExamStudents') && defined('EXAM_API_TOKEN') && EXAM_API_TOKEN !== 'PASTE_YOUR_TOKEN_HERE') {
        $esRes = fetchExamStudents();
        if ($esRes['success'] && !empty($esRes['data'])) {
            foreach ($esRes['data'] as $es) {
                $id = $es['identifier'] ?? '';
                if ($id && !empty($es['assignments'])) {
                    $examAssignments[$id] = $es['assignments'];
                }
            }
        }
    }
} catch (Exception $e) { /* silently fail */ }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hall Tickets — ATC Center | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎫</text></svg>">
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
                    <h2>Hall Tickets</h2>
                    <p>Generate examination hall tickets</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="ht-container">
            <!-- KPI Cards -->
            <!-- Share payment status notice -->
            <?php if (empty($paidStudentIds)): ?>
            <div style="display:flex;align-items:flex-start;gap:.75rem;background:#fef3c7;border:1.5px solid #fcd34d;border-radius:12px;padding:1rem 1.25rem;margin-bottom:1.5rem">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2" width="22" height="22" style="flex-shrink:0;margin-top:.1rem"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <div>
                    <div style="font-weight:700;font-size:.9rem;color:#92400e">No Share Payment Found</div>
                    <div style="font-size:.82rem;color:#78350f;margin-top:.2rem">Hall tickets cannot be generated until you have at least one <strong>completed share payment</strong> to Head Office. Go to <a href="pay_share.php" style="color:#d97706;font-weight:700">Pay Share</a> to submit payment.</div>
                </div>
            </div>
            <?php endif; ?>

            <div class="ht-kpi-grid">
                <div class="ht-kpi-card ht-kpi-blue">
                    <div class="ht-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
                    </div>
                    <div class="ht-kpi-content">
                        <div class="ht-kpi-value"><?= count($students) ?></div>
                        <div class="ht-kpi-label">Total Students</div>
                    </div>
                </div>

                <div class="ht-kpi-card ht-kpi-green">
                    <div class="ht-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div class="ht-kpi-content">
                        <div class="ht-kpi-value"><?= $eligibleCount ?></div>
                        <div class="ht-kpi-label">Eligible (Share Paid + Photo)</div>
                    </div>
                </div>

                <div class="ht-kpi-card ht-kpi-amber">
                    <div class="ht-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <div class="ht-kpi-content">
                        <div class="ht-kpi-value"><?= $shareUnpaidCount ?></div>
                        <div class="ht-kpi-label">Share Not Paid Yet</div>
                    </div>
                </div>

                <div class="ht-kpi-card ht-kpi-purple">
                    <div class="ht-kpi-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    </div>
                    <div class="ht-kpi-content">
                        <div class="ht-kpi-value"><?= $noPhotoCount ?></div>
                        <div class="ht-kpi-label">No Photo Uploaded</div>
                    </div>
                </div>
            </div>

            <!-- Toolbar -->
            <div class="ht-toolbar">
                <h3 class="ht-toolbar-title">
                    Students List
                    <span class="ht-toolbar-badge"><?= count($students) ?></span>
                </h3>
                <form method="GET" class="ht-toolbar-actions">
                    <select name="course" class="ht-select" onchange="this.form.submit()">
                        <option value="all">All Courses</option>
                        <?php foreach ($courses as $course): ?>
                            <option value="<?= htmlspecialchars($course) ?>" <?= $courseFilter === $course ? 'selected' : '' ?>>
                                <?= htmlspecialchars($course) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="ht-search-box">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" name="search" placeholder="Search by roll no, name..." value="<?= htmlspecialchars($searchTerm) ?>">
                    </div>
                    <button type="submit" class="ht-btn-search">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        Search
                    </button>
                </form>
            </div>

            <!-- Students Table -->
            <div class="ht-table-wrapper">
                <table class="ht-table">
                    <thead>
                        <tr>
                            <th>Roll No</th>
                            <th>Student Name</th>
                            <th>Course</th>
                            <th>Mobile</th>
                            <th>Share Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="6" class="ht-table-empty">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                    <p>No students found.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($students as $student): ?>
                                <?php
                                    $sharePaid = !empty($student['share_paid']);
                                    $hasPhoto  = !empty($student['photo']) && file_exists(__DIR__ . '/../' . $student['photo']);
                                    $canGenerate = $sharePaid && $hasPhoto;
                                    $fullName = $student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name'];
                                ?>
                                <tr>
                                    <td>
                                        <span class="ht-roll-badge"><?= htmlspecialchars($student['roll_no']) ?></span>
                                    </td>
                                    <td>
                                        <div class="ht-student-name"><?= htmlspecialchars($fullName) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($student['course']) ?></td>
                                    <td class="ht-mobile"><?= htmlspecialchars($student['mobile']) ?></td>
                                    <td>
                                        <?php if ($sharePaid): ?>
                                            <span style="display:inline-flex;align-items:center;gap:.3rem;font-size:.72rem;font-weight:700;color:#059669;background:#d1fae5;padding:.25rem .6rem;border-radius:999px">
                                                ✅ Share Paid
                                            </span>
                                        <?php else: ?>
                                            <span style="display:inline-flex;align-items:center;gap:.3rem;font-size:.72rem;font-weight:700;color:#d97706;background:#fef3c7;padding:.25rem .6rem;border-radius:999px">
                                                ⏳ Share Pending
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="ht-action-cell">
                                        <div style="display:flex;gap:.35rem;align-items:center;flex-wrap:wrap">
                                        <?php if ($canGenerate): ?>
                                            <button onclick="generateHallTicket(<?= $student['id'] ?>)" class="ht-btn-generate">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                                Generate
                                            </button>
                                            <button onclick="openExamNotify(<?= $student['id'] ?>)" class="ht-btn-wa-notify" title="Send WhatsApp Exam Notification">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                            </button>
                                        <?php else: ?>
                                            <!-- Share paid but no photo: open upload panel (Task I) -->
                                            <a href="edit_student.php?id=<?= $student['id'] ?>" class="ht-btn-nophoto" title="Go to Edit Student to upload photo">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                                                Upload Photo
                                            </a>
                                        <?php endif; ?>
                                        </div>
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

<!-- Hall Ticket Modal -->
<div class="ht-modal-overlay" id="hallTicketModal">
    <div class="ht-modal-card">
        <div class="ht-modal-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                Examination Hall Ticket
            </h3>
            <button type="button" onclick="closeHallTicketModal()" class="ht-modal-close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        
        <div id="hallTicketContent" class="ht-modal-body">
            <!-- Hall ticket will be generated here -->
        </div>
        
        <div class="ht-modal-footer">
            <button type="button" onclick="closeHallTicketModal()" class="ht-btn-cancel">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                Close
            </button>
            <button type="button" onclick="printHallTicket()" class="ht-btn-print">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                Print Hall Ticket
            </button>
        </div>
    </div>
</div>

<!-- ═══ WHATSAPP EXAM NOTIFY MODAL ═══ -->
<div id="examNotifyModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center;padding:1rem" onclick="if(event.target===this)closeExamNotify()">
    <div style="background:#fff;border-radius:20px;width:min(560px,96vw);overflow:hidden;box-shadow:0 24px 80px rgba(0,0,0,.25);max-height:92vh;overflow-y:auto">
        <!-- Header -->
        <div style="background:linear-gradient(135deg,#25D366,#128C7E);padding:1.25rem 1.5rem;display:flex;align-items:center;justify-content:space-between">
            <div style="display:flex;align-items:center;gap:.75rem">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="#fff" width="24" height="24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                <div>
                    <div style="color:#fff;font-size:1rem;font-weight:800">Send Exam Notification</div>
                    <div id="enStudentBadge" style="color:rgba(255,255,255,.8);font-size:.78rem;margin-top:.1rem"></div>
                </div>
            </div>
            <button onclick="closeExamNotify()" style="border:none;background:rgba(255,255,255,.2);border-radius:8px;color:#fff;padding:.35rem .75rem;cursor:pointer;font-size:1rem;font-weight:700">✕</button>
        </div>
        <!-- Body -->
        <div style="padding:1.5rem;display:flex;flex-direction:column;gap:1rem">
            <input type="hidden" id="enStudentId">
            <!-- Info banner -->
            <div style="background:#f0fdf4;border:1.5px solid #86efac;border-radius:10px;padding:.85rem 1rem;font-size:.8rem;color:#15803d;line-height:1.5">
                📢 Fill in the exam details below. The WhatsApp message will be pre-filled and ready to send to the student's registered mobile number.
            </div>
            <!-- Row 1: Exam topic + Subject -->
            <div style="display:grid;grid-template-columns:1fr;gap:.75rem">
                <div style="display:flex;flex-direction:column;gap:.3rem">
                    <label style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#6b7280">Exam Topic / Notice Title <span style="color:#ef4444">*</span></label>
                    <input type="text" id="enExamTopic" placeholder="e.g. Tally Prime Final Online Exam" style="height:42px;border:1.5px solid #e5e7eb;border-radius:10px;padding:0 .9rem;font-size:.9rem;font-family:inherit;outline:none;width:100%;box-sizing:border-box" onfocus="this.style.borderColor='#25D366'" onblur="this.style.borderColor='#e5e7eb'">
                </div>
                <div style="display:flex;flex-direction:column;gap:.3rem">
                    <label style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#6b7280">Full Course / Exam Name <span style="color:#ef4444">*</span></label>
                    <input type="text" id="enCourseName" placeholder="e.g. Advance Certificate Course in Financial Accounting Final Online Exam" style="height:42px;border:1.5px solid #e5e7eb;border-radius:10px;padding:0 .9rem;font-size:.9rem;font-family:inherit;outline:none;width:100%;box-sizing:border-box" onfocus="this.style.borderColor='#25D366'" onblur="this.style.borderColor='#e5e7eb'">
                </div>
            </div>
            <!-- Row 2: Date + Time -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                <div style="display:flex;flex-direction:column;gap:.3rem">
                    <label style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#6b7280">Exam Date <span style="color:#ef4444">*</span></label>
                    <input type="date" id="enExamDate" style="height:42px;border:1.5px solid #e5e7eb;border-radius:10px;padding:0 .9rem;font-size:.9rem;font-family:inherit;outline:none;width:100%;box-sizing:border-box" onfocus="this.style.borderColor='#25D366'" onblur="this.style.borderColor='#e5e7eb'">
                </div>
                <div style="display:flex;flex-direction:column;gap:.3rem">
                    <label style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#6b7280">Exam Time <span style="color:#ef4444">*</span></label>
                    <input type="time" id="enExamTime" style="height:42px;border:1.5px solid #e5e7eb;border-radius:10px;padding:0 .9rem;font-size:.9rem;font-family:inherit;outline:none;width:100%;box-sizing:border-box" onfocus="this.style.borderColor='#25D366'" onblur="this.style.borderColor='#e5e7eb'">
                </div>
            </div>
            <!-- Row 3: Center + Director -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem">
                <div style="display:flex;flex-direction:column;gap:.3rem">
                    <label style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#6b7280">Exam Center <span style="color:#ef4444">*</span></label>
                    <input type="text" id="enCenter" placeholder="e.g. Aim Computers, Jalgaon" style="height:42px;border:1.5px solid #e5e7eb;border-radius:10px;padding:0 .9rem;font-size:.9rem;font-family:inherit;outline:none;width:100%;box-sizing:border-box" onfocus="this.style.borderColor='#25D366'" onblur="this.style.borderColor='#e5e7eb'">
                </div>
                <div style="display:flex;flex-direction:column;gap:.3rem">
                    <label style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#6b7280">Director Name</label>
                    <input type="text" id="enDirector" placeholder="e.g. Pravin Jadhav" style="height:42px;border:1.5px solid #e5e7eb;border-radius:10px;padding:0 .9rem;font-size:.9rem;font-family:inherit;outline:none;width:100%;box-sizing:border-box" onfocus="this.style.borderColor='#25D366'" onblur="this.style.borderColor='#e5e7eb'">
                </div>
            </div>
            <!-- Message Preview -->
            <div>
                <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:.4rem">Message Preview</div>
                <div id="enMsgPreview" style="background:#e9feee;border:1.5px solid #86efac;border-radius:10px;padding:1rem 1.1rem;font-size:.82rem;color:#1a3a1a;white-space:pre-line;line-height:1.6;min-height:80px;font-family:inherit">Fill in the fields above to preview the message…</div>
            </div>
        </div>
        <!-- Footer -->
        <div style="padding:1rem 1.5rem 1.25rem;border-top:1.5px solid #e5e7eb;display:flex;justify-content:flex-end;gap:.75rem">
            <button onclick="closeExamNotify()" style="border:1.5px solid #e5e7eb;background:#fff;border-radius:10px;padding:.55rem 1.25rem;font-size:.85rem;font-weight:700;cursor:pointer;color:#374151">Cancel</button>
            <button id="enSendBtn" onclick="sendExamNotify()" style="border:none;background:linear-gradient(135deg,#25D366,#128C7E);border-radius:10px;padding:.55rem 1.6rem;font-size:.85rem;font-weight:700;cursor:pointer;color:#fff;display:flex;align-items:center;gap:.5rem">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" width="16" height="16"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                Send via WhatsApp
            </button>
        </div>
    </div>
</div>

<!-- ═══ PHOTO UPLOAD MODAL (Task I) ═══ -->
<div id="photoUploadModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;padding:1rem" onclick="if(event.target===this)closePhotoUpload()">
    <div style="background:#fff;border-radius:20px;width:min(520px,95vw);overflow:hidden;box-shadow:0 24px 80px rgba(0,0,0,.25)">
        <!-- Header -->
        <div style="background:linear-gradient(135deg,#4361ee,#8b5cf6);padding:1.25rem 1.5rem;display:flex;align-items:center;justify-content:space-between">
            <h3 id="puModalTitle" style="color:#fff;font-size:1rem;font-weight:800;margin:0">Upload Student Photo</h3>
            <button onclick="closePhotoUpload()" style="border:none;background:rgba(255,255,255,.2);border-radius:8px;color:#fff;padding:.3rem .7rem;cursor:pointer;font-size:1rem;font-weight:700">✕</button>
        </div>
        <!-- Body -->
        <div style="padding:1.5rem">
            <input type="hidden" id="puStudentId">
            <!-- Read-only student details -->
            <div id="puDetails" style="background:#f9fafb;border:1.5px solid #e5e7eb;border-radius:12px;padding:1rem;margin-bottom:1.25rem"></div>
            <!-- Photo upload area -->
            <label style="display:block;font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;margin-bottom:.5rem">Select Photo <span style="color:#ef4444">*</span></label>
            <div style="border:2px dashed #c7d2fe;border-radius:12px;padding:1.25rem;text-align:center;background:#fafaff;cursor:pointer" onclick="document.getElementById('puFileInput').click()">
                <div id="puPreview">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#6366f1" stroke-width="1.5" width="40" height="40"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    <div style="font-size:.8rem;color:#6b7280;margin-top:.4rem">Click to choose student photo</div>
                    <div style="font-size:.7rem;color:#9ca3af;margin-top:.2rem">JPG, PNG, WebP · Max 5MB · Passport size preferred</div>
                </div>
                <input type="file" id="puFileInput" accept="image/*" style="display:none">
            </div>
            <div id="puMsg" style="margin-top:.75rem;font-size:.82rem;min-height:1.2em"></div>
        </div>
        <!-- Footer -->
        <div style="padding:1rem 1.5rem;border-top:1.5px solid #e5e7eb;display:flex;justify-content:flex-end;gap:.75rem">
            <button onclick="closePhotoUpload()" style="border:1.5px solid #e5e7eb;background:#fff;border-radius:10px;padding:.55rem 1.25rem;font-size:.85rem;font-weight:700;cursor:pointer;color:#374151">Cancel</button>
            <button id="puSubmitBtn" onclick="submitPhotoUpload()" style="border:none;background:linear-gradient(135deg,#4361ee,#8b5cf6);border-radius:10px;padding:.55rem 1.5rem;font-size:.85rem;font-weight:700;cursor:pointer;color:#fff">Upload &amp; Save</button>
        </div>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
const atcDetails = <?= json_encode($atcDetails) ?>;
const students = <?= json_encode($students) ?>;
const examAssignments = <?= json_encode($examAssignments) ?>;

// ── Task I: Photo Upload Panel ────────────────────────────────────────────────────────
function openPhotoUpload(studentId) {
    const s = students.find(x => x.id == studentId);
    if (!s) return;
    const fullName = [s.first_name, s.middle_name, s.last_name].filter(Boolean).join(' ');
    const dob = s.dob ? new Date(s.dob+'T00:00:00').toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—';
    document.getElementById('puStudentId').value = s.id;
    document.getElementById('puModalTitle').textContent = 'Upload Photo — ' + fullName;
    document.getElementById('puDetails').innerHTML = `
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem 1.2rem;font-size:.84rem">
            <div><span style="font-size:.68rem;font-weight:800;text-transform:uppercase;color:#9ca3af;letter-spacing:.05em">Roll No</span><br><strong style="color:#111827;font-family:monospace">${s.roll_no||'—'}</strong></div>
            <div><span style="font-size:.68rem;font-weight:800;text-transform:uppercase;color:#9ca3af;letter-spacing:.05em">Full Name</span><br><strong style="color:#111827">${fullName}</strong></div>
            <div><span style="font-size:.68rem;font-weight:800;text-transform:uppercase;color:#9ca3af;letter-spacing:.05em">DOB</span><br><span style="color:#4b5563">${dob}</span></div>
            <div><span style="font-size:.68rem;font-weight:800;text-transform:uppercase;color:#9ca3af;letter-spacing:.05em">Gender</span><br><span style="color:#4b5563">${s.gender||'—'}</span></div>
            <div><span style="font-size:.68rem;font-weight:800;text-transform:uppercase;color:#9ca3af;letter-spacing:.05em">Course</span><br><span style="color:#4b5563">${s.course||'—'}</span></div>
            <div><span style="font-size:.68rem;font-weight:800;text-transform:uppercase;color:#9ca3af;letter-spacing:.05em">Mobile</span><br><span style="color:#4b5563">${s.mobile||'—'}</span></div>
        </div>`;
    document.getElementById('puPreview').innerHTML = '';
    document.getElementById('puFileInput').value = '';
    document.getElementById('puMsg').innerHTML = '';
    document.getElementById('photoUploadModal').style.display = 'flex';
}

function closePhotoUpload() {
    document.getElementById('photoUploadModal').style.display = 'none';
}

document.addEventListener('DOMContentLoaded', () => {
    const fi = document.getElementById('puFileInput');
    if (fi) fi.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            const reader = new FileReader();
            reader.onload = e => {
                document.getElementById('puPreview').innerHTML =
                    `<img src="${e.target.result}" style="width:90px;height:110px;object-fit:cover;border-radius:8px;border:2px solid #4361ee;margin-top:.6rem">`;
            };
            reader.readAsDataURL(this.files[0]);
        }
    });
});

function submitPhotoUpload() {
    const sid  = document.getElementById('puStudentId').value;
    const fi   = document.getElementById('puFileInput');
    const msg  = document.getElementById('puMsg');
    const btn  = document.getElementById('puSubmitBtn');
    if (!fi.files || !fi.files[0]) { msg.innerHTML = '<span style="color:#ef4444">Please select a photo first.</span>'; return; }
    const fd = new FormData();
    fd.append('ajax_upload_photo', '1');
    fd.append('student_id', sid);
    fd.append('photo', fi.files[0]);
    btn.disabled = true;
    btn.textContent = 'Uploading…';
    fetch('hall_tickets.php', { method: 'POST', body: fd })
    .then(r => r.json()).then(data => {
        if (data.success) {
            msg.innerHTML = '<span style="color:#059669;font-weight:700">✅ ' + data.message + ' Refreshing…</span>';
            setTimeout(() => location.reload(), 1200);
        } else {
            msg.innerHTML = '<span style="color:#ef4444">' + data.message + '</span>';
            btn.disabled = false;
            btn.textContent = 'Upload & Save';
        }
    });
}

function showShareNotPaidAlert() {
    showBlockAlert(
        '🏦 Share Payment Required',
        'Hall ticket cannot be generated for this student because the Head Office share for this student has not been paid yet.\n\nPlease go to Pay Share and complete the share payment that includes this student first.\n\nNote: The student\'s own course fees do NOT affect hall ticket eligibility.',
        '#f59e0b'
    );
}

function showNoPhotoAlert() {
    showBlockAlert(
        '📷 Student Photo Required',
        'Hall ticket cannot be generated because the student\'s photo has not been uploaded.\n\nPlease upload the student photo from the Admissions page before generating the hall ticket.',
        '#8b5cf6'
    );
}

function showBlockAlert(title, message, color) {
    const existing = document.getElementById('htBlockAlert');
    if (existing) existing.remove();
    const overlay = document.createElement('div');
    overlay.id = 'htBlockAlert';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;display:flex;align-items:center;justify-content:center;padding:1rem;';
    overlay.innerHTML = `
        <div style="background:#fff;border-radius:16px;max-width:420px;width:100%;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.25);">
            <div style="background:${color};padding:1.25rem 1.5rem;display:flex;align-items:center;gap:.75rem;">
                <div style="font-size:1.5rem;">${title.split(' ')[0]}</div>
                <div style="color:#fff;font-size:1rem;font-weight:800;">${title.substring(title.indexOf(' ')+1)}</div>
            </div>
            <div style="padding:1.5rem;white-space:pre-line;font-size:.9rem;color:#374151;line-height:1.7;">${message}</div>
            <div style="padding:.75rem 1.5rem 1.25rem;display:flex;justify-content:flex-end;">
                <button onclick="document.getElementById('htBlockAlert').remove()" style="padding:.6rem 1.75rem;background:${color};color:#fff;border:none;border-radius:999px;font-weight:700;cursor:pointer;font-size:.9rem;">OK, Got It</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });
}

function closeHallTicketModal() {
    document.getElementById('hallTicketModal').style.display = 'none';
}

/* ── WhatsApp Exam Notification ─────────────────────────────── */
let _enStudent = null;

function openExamNotify(studentId) {
    const s = students.find(x => x.id == studentId);
    if (!s) return;
    _enStudent = s;

    const fullName = [s.first_name, s.middle_name, s.last_name].filter(Boolean).join(' ');
    document.getElementById('enStudentId').value = s.id;
    document.getElementById('enStudentBadge').textContent = fullName + ' · ' + (s.mobile || 'No mobile');

    // Pre-fill center from ATC details
    const atcCenter = (atcDetails.name || '') + (atcDetails.district ? ', ' + atcDetails.district : '');
    document.getElementById('enCenter').value = atcCenter;

    // Pre-fill course name from student course
    document.getElementById('enCourseName').value = (s.course || '') + ' Final Exam';

    // Attach live preview listeners
    ['enExamTopic','enCourseName','enExamDate','enExamTime','enCenter','enDirector'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.oninput = updateExamPreview;
            el.onchange = updateExamPreview;
        }
    });

    updateExamPreview();
    document.getElementById('examNotifyModal').style.display = 'flex';
}

function closeExamNotify() {
    document.getElementById('examNotifyModal').style.display = 'none';
    _enStudent = null;
}

function formatExamDate(dateStr) {
    if (!dateStr) return 'To Be Announced';
    const d = new Date(dateStr + 'T00:00:00');
    const days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    const day = d.getDate();
    const suffix = day === 1 || day === 21 || day === 31 ? 'st' : day === 2 || day === 22 ? 'nd' : day === 3 || day === 23 ? 'rd' : 'th';
    return `${days[d.getDay()]}, ${day}${suffix} ${months[d.getMonth()]} ${d.getFullYear()}`;
}

function formatExamTime(timeStr) {
    if (!timeStr) return 'To Be Announced';
    const [h, m] = timeStr.split(':').map(Number);
    const ampm = h >= 12 ? 'PM' : 'AM';
    const hour = h % 12 || 12;
    return `${String(hour).padStart(2,'0')}:${String(m).padStart(2,'0')} ${ampm} sharp`;
}

function buildExamMsg() {
    if (!_enStudent) return '';
    const fullName = [_enStudent.first_name, _enStudent.middle_name, _enStudent.last_name].filter(Boolean).join(' ');
    const topic    = document.getElementById('enExamTopic').value.trim() || '[Exam Topic]';
    const course   = document.getElementById('enCourseName').value.trim() || '[Course Name]';
    const dateStr  = document.getElementById('enExamDate').value;
    const timeStr  = document.getElementById('enExamTime').value;
    const center   = document.getElementById('enCenter').value.trim() || '[Center Name]';
    const director = document.getElementById('enDirector').value.trim();
    const atcLabel = atcDetails.name || center;

    return `📢 *Important Notice Regarding ${topic}* 🖥\n\nDear Student ${fullName},\n\nYour ${course} is scheduled on:\n\n🗓 *${formatExamDate(dateStr)}*\n📍 ${center}\n⏰ *At ${formatExamTime(timeStr)}*\n\n🎟 Hall tickets will be distributed before the exam.\n\n❗ Absence will not be permitted.\n\n🙏 Your presence is mandatory.\n\nRegards,\n${director ? director + '\nDirector, ' : ''}${atcLabel}`;
}

function updateExamPreview() {
    document.getElementById('enMsgPreview').textContent = buildExamMsg();
}

function sendExamNotify() {
    if (!_enStudent) return;
    const mobile = (_enStudent.mobile || '').replace(/\D/g, '');
    if (!mobile || mobile.length < 10) {
        alert('No valid mobile number found for this student.');
        return;
    }
    const topic = document.getElementById('enExamTopic').value.trim();
    const date  = document.getElementById('enExamDate').value;
    if (!topic || !date) {
        alert('Please fill in at least the Exam Topic and Exam Date.');
        return;
    }
    const waPhone = mobile.length === 10 ? '91' + mobile : mobile;
    const msg = buildExamMsg();
    window.open('https://wa.me/' + waPhone + '?text=' + encodeURIComponent(msg), '_blank');
}

function generateHallTicket(studentId) {
    const student = students.find(s => s.id == studentId);
    if (!student) { alert('Student not found'); return; }

    if (!student.share_paid) {
        showShareNotPaidAlert();
        return;
    }

    if (!student.photo || student.photo.trim() === '') {
        showNoPhotoAlert();
        return;
    }
    
    const fullName = `${student.first_name} ${student.middle_name ? student.middle_name + ' ' : ''}${student.last_name}`;
    const photoPath = student.photo ? `../${student.photo}` : '../assets/logo.png';
    const examAddr = [atcDetails.address, atcDetails.city, atcDetails.district, atcDetails.state].filter(Boolean).join(', ') + (atcDetails.pin_code ? ' - ' + atcDetails.pin_code : '');
    const regNo = student.registration_id || student.roll_no || '-';
    const examSchedule = getExamScheduleInfo(student);

    document.getElementById('hallTicketContent').innerHTML = `
        <div class="ht-print">
          <!-- HEADER -->
          <table class="ht-print-tbl">
            <tr>
              <td class="ht-p-center" style="width:20%">
                <img src="../assets/logo.png" style="height:70px;object-fit:contain">
              </td>
              <td class="ht-p-center ht-p-bold" style="width:60%">
                <div style="font-size:20px">EXAMINATION HALL TICKET</div>
                <div>${student.course || 'Examination'}</div>
              </td>
              <td style="width:20%"></td>
            </tr>
          </table>

          <br>

          <!-- CANDIDATE DETAILS -->
          <table class="ht-print-tbl">
            <tr>
              <th colspan="4" class="ht-p-center">Candidate Details</th>
            </tr>
            <tr>
              <td class="ht-p-bold" style="width:25%">Candidate Name</td>
              <td style="width:35%">${fullName}</td>
              <td rowspan="3" colspan="2" class="ht-p-center">
                <img src="${photoPath}" style="width:110px;height:140px;border:1px solid #000;object-fit:cover">
              </td>
            </tr>
            <tr>
              <td class="ht-p-bold">Registration Number</td>
              <td>${regNo}</td>
            </tr>
            <tr>
              <td class="ht-p-bold">Course</td>
              <td>${student.course || '-'}</td>
            </tr>
          </table>

          <br>

          <!-- EXAMINATION DETAILS -->
          <table class="ht-print-tbl">
            <tr>
              <th colspan="4" class="ht-p-center">Examination Details</th>
            </tr>
            <tr>
              <td class="ht-p-bold" style="width:25%">Examination Date</td>
              <td style="width:25%">${examSchedule.date}</td>
              <td class="ht-p-bold" style="width:25%">Exam Time</td>
              <td style="width:25%">${examSchedule.time}</td>
            </tr>
            <tr>
              <td class="ht-p-bold">Exam Slot</td>
              <td>${examSchedule.slot || '-'}</td>
              <td class="ht-p-bold">Exam User ID</td>
              <td>${student.exam_user_id || regNo + '@gyanamindia.in'}</td>
            </tr>
            <tr>
              <td class="ht-p-bold">Exam Centre</td>
              <td colspan="3">${atcDetails.name || '-'}</td>
            </tr>
            <tr>
              <td class="ht-p-bold">Centre Address</td>
              <td colspan="3">${examAddr || '-'}</td>
            </tr>
          </table>

          <br>

          <!-- SIGNATURES -->
          <table class="ht-print-tbl">
            <tr>
              <td class="ht-p-center ht-p-bold" style="height:90px">Student Signature</td>
              <td class="ht-p-center ht-p-bold" style="height:90px">Invigilator Signature</td>
            </tr>
          </table>

          <br>

          <!-- INSTRUCTIONS -->
          <table class="ht-print-tbl">
            <tr>
              <th class="ht-p-center">Instructions for Students</th>
            </tr>
            <tr>
              <td>
                <ol style="margin:0;padding-left:20px;font-size:13px;line-height:1.8">
                  <li style="margin-bottom:6px">Report to the exam centre at least 30 minutes before the exam time.</li>
                  <li style="margin-bottom:6px">Candidate must carry the hall ticket and original photo ID.</li>
                  <li style="margin-bottom:6px">If any mistake is found in candidate details, report immediately to centre staff.</li>
                  <li style="margin-bottom:6px">Candidate must sign the attendance sheet before the start of exam.</li>
                  <li style="margin-bottom:6px">Opening any other window during the exam will terminate the exam.</li>
                  <li style="margin-bottom:6px">Use <b>FINISH EXAM</b> option carefully as it will end the exam.</li>
                </ol>
              </td>
            </tr>
          </table>
        </div>
    `;
    
    printHallTicket();
}

function getExamScheduleInfo(student) {
    // Priority 1: Local exam_schedules data (set by ATC in Exam Schedules page)
    if (student.sched_exam_date) {
        const localDate = new Date(student.sched_exam_date + 'T00:00:00');
        const dateDisplay = localDate.toLocaleDateString('en-IN', {
            day: '2-digit', month: 'short', year: 'numeric', weekday: 'long'
        });

        let timeDisplay = 'To Be Announced';
        if (student.sched_exam_time) {
            const [h, m] = student.sched_exam_time.split(':').map(Number);
            const ampm = h >= 12 ? 'PM' : 'AM';
            const hour = h % 12 || 12;
            timeDisplay = String(hour).padStart(2, '0') + ':' + String(m).padStart(2, '0') + ' ' + ampm;
        }

        const slotDisplay = student.sched_exam_slot || '';

        return { date: dateDisplay, time: timeDisplay, slot: slotDisplay };
    }

    // Priority 2: External Exam Portal API data
    const regId = student.registration_id || '';
    const assignments = examAssignments[regId] || [];
    
    if (assignments.length === 0) {
        return { date: 'To Be Announced', time: 'To Be Announced', slot: '' };
    }
    
    const asgn = assignments[0];
    const scheduledDate = asgn.scheduled_date || '';
    const timeWindow = asgn.time_window || student.time_window || '';
    
    const dateDisplay = scheduledDate 
        ? new Date(scheduledDate + 'T00:00:00').toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric', weekday:'long'})
        : 'To Be Announced';
    const timeDisplay = timeWindow || 'To Be Announced';
    
    return { date: dateDisplay, time: timeDisplay, slot: '' };
}

function getExamScheduleRows(student) {
    const regId = student.registration_id || '';
    const assignments = examAssignments[regId] || [];
    
    if (assignments.length === 0) {
        return `
            <tr>
                <td class="label">Exam Schedule:</td>
                <td class="value" colspan="3" style="font-style:italic;color:#6b7280;">To Be Announced — Exam not yet assigned</td>
            </tr>
        `;
    }
    
    let rows = '';
    assignments.forEach((asgn, i) => {
        const examTitle = asgn.title || 'Exam';
        const subject = asgn.subject || '';
        const scheduledDate = asgn.scheduled_date || '';
        const timeWindow = asgn.time_window || student.time_window || '';
        const examSlot = student.exam_slot || 'SLOT1';
        
        const dateDisplay = scheduledDate 
            ? new Date(scheduledDate + 'T00:00:00').toLocaleDateString('en-IN', {day:'2-digit', month:'short', year:'numeric'})
            : 'To Be Announced';
        const timeDisplay = timeWindow || 'To Be Announced';
        
        rows += `
            <tr>
                <td class="label">Exam ${assignments.length > 1 ? (i+1) : ''}:</td>
                <td class="value" colspan="3"><strong>${examTitle}</strong>${subject ? ' (' + subject + ')' : ''}</td>
            </tr>
            <tr>
                <td class="label">Date:</td>
                <td class="value">${dateDisplay}</td>
                <td class="label">Time Slot:</td>
                <td class="value">${timeDisplay} — ${examSlot}</td>
            </tr>
        `;
    });
    
    return rows;
}

function printHallTicket() {
    const content = document.getElementById('hallTicketContent').innerHTML;
    const printWindow = window.open('', '', 'height=800,width=1000');
    printWindow.document.write('<html><head><title>Hall Ticket - Gyanam India</title>');
    printWindow.document.write('<style>');
    printWindow.document.write(`
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; padding: 20px; background: #f4f4f4; }
        .ht-print { max-width: 900px; margin: auto; background: #fff; border: 2px solid #000; padding: 15px; }
        .ht-print-tbl { width: 100%; border-collapse: collapse; font-size: 14px; }
        .ht-print-tbl td, .ht-print-tbl th { border: 1px solid #000; padding: 8px; vertical-align: top; }
        .ht-p-center { text-align: center; }
        .ht-p-bold { font-weight: bold; }
        @media print { body { padding: 0; background: #fff; } .ht-print { border: 2px solid #000; } }
    `);
    printWindow.document.write('</style></head><body>');
    printWindow.document.write(content);
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.print();
}
</script>

<style>
:root {
    --font: 'Sora', sans-serif;
    --mono: 'JetBrains Mono', monospace;
    --brand: #4361ee;
    --purple: #8b5cf6;
    --emerald: #10b981;
    --amber: #f59e0b;
    --rose: #f43f5e;
}

body { font-family: var(--font); }

/* Container */
.ht-container {
    padding: 1.75rem 2rem;
    width: 100%;
    box-sizing: border-box;
}

/* KPI Cards */
.ht-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1.25rem;
    margin-bottom: 2rem;
    width: 100%;
}

@media (max-width: 640px) {
    .ht-kpi-grid {
        grid-template-columns: 1fr 1fr;
    }
}

@media (max-width: 420px) {
    .ht-kpi-grid {
        grid-template-columns: 1fr;
    }
}

.ht-kpi-card {
    background: #ffffff;
    border-radius: 12px;
    padding: 1.25rem 1.5rem;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 1.25rem;
    box-shadow: 0 1px 3px rgba(0,0,0,.05);
    transition: all .2s ease;
    border: 1px solid #e5e7eb;
    border-left-width: 4px;
}

.ht-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,0,0,.08);
}

.ht-kpi-blue { border-left-color: var(--brand); }
.ht-kpi-green { border-left-color: var(--emerald); }
.ht-kpi-amber { border-left-color: var(--amber); }
.ht-kpi-purple { border-left-color: var(--rose); }

.ht-kpi-icon {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.ht-kpi-blue .ht-kpi-icon { background: #eef1fd; color: var(--brand); }
.ht-kpi-green .ht-kpi-icon { background: #d1fae5; color: var(--emerald); }
.ht-kpi-amber .ht-kpi-icon { background: #fef3c7; color: var(--amber); }
.ht-kpi-purple .ht-kpi-icon { background: #ffe4e6; color: var(--rose); }

.ht-kpi-icon svg {
    width: 20px;
    height: 20px;
    stroke: currentColor;
}

.ht-kpi-content {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.ht-kpi-label {
    font-size: .65rem;
    font-weight: 800;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: .05em;
    order: -1;
}

.ht-kpi-value {
    font-size: 1.6rem;
    font-weight: 800;
    line-height: 1;
    color: #111827;
    font-family: var(--font);
}

/* Toolbar */
.ht-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1.5rem;
    gap: 1rem;
    flex-wrap: wrap;
    width: 100%;
}

.ht-toolbar-title {
    display: flex;
    align-items: center;
    gap: .625rem;
    font-size: 1.125rem;
    font-weight: 800;
    color: #1f2937;
    margin: 0;
    letter-spacing: -0.02em;
}

.ht-toolbar-badge {
    padding: .25rem .75rem;
    border-radius: 999px;
    font-size: .8125rem;
    font-weight: 800;
    background: #e5e7eb;
    color: #6b7280;
}

.ht-toolbar-actions {
    display: flex;
    gap: 0.75rem;
    align-items: center;
}

.ht-select {
    height: 44px;
    padding: 0 1rem;
    border-radius: 10px;
    border: 1.5px solid #e5e7eb;
    background: #ffffff;
    font-size: .875rem;
    color: #1f2937;
    font-family: var(--font);
    font-weight: 500;
    cursor: pointer;
    transition: all .2s ease;
}

.ht-select:hover {
    border-color: #d1d5db;
}

.ht-select:focus {
    outline: none;
    border-color: var(--brand);
    box-shadow: 0 0 0 4px rgba(67, 97, 238, .1);
}

.ht-search-box {
    position: relative;
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: 0 1rem;
    background: #ffffff;
    border: 1.5px solid #e5e7eb;
    border-radius: 10px;
    height: 44px;
    min-width: 280px;
    flex: 1;
    max-width: 400px;
    transition: all .2s ease;
}

.ht-search-box:focus-within {
    border-color: var(--brand);
    box-shadow: 0 0 0 4px rgba(67, 97, 238, .1);
}

.ht-search-box svg {
    width: 18px;
    height: 18px;
    stroke: #9ca3af;
    flex-shrink: 0;
}

.ht-search-box input {
    flex: 1;
    border: none;
    outline: none;
    font-size: .875rem;
    font-weight: 500;
    background: transparent;
    color: #1f2937;
    font-family: var(--font);
}

.ht-btn-search {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: 0 1.5rem;
    height: 44px;
    border-radius: 10px;
    font-size: .875rem;
    font-weight: 800;
    color: #fff;
    background: linear-gradient(135deg, var(--brand), #3730a3);
    border: none;
    cursor: pointer;
    transition: all .2s ease;
    box-shadow: 0 2px 8px rgba(67, 97, 238, .25);
    white-space: nowrap;
    letter-spacing: -0.01em;
}

.ht-btn-search:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(67, 97, 238, .35);
}

.ht-btn-search svg {
    width: 16px;
    height: 16px;
}

/* Table */
.ht-table-wrapper {
    background: #ffffff;
    border: 1.5px solid #e5e7eb;
    border-radius: 18px;
    overflow-x: auto;
    box-shadow: 0 1px 3px rgba(0,0,0,.04);
    margin-bottom: 1.5rem;
    width: 100%;
}

.ht-table {
    width: 100%;
    min-width: 800px;
    border-collapse: collapse;
    font-size: .875rem;
}

.ht-table thead {
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    border-bottom: 1.5px solid #e5e7eb;
}

.ht-table th {
    padding: 1rem 1.25rem;
    text-align: left;
    font-size: .75rem;
    font-weight: 800;
    color: #6b7280;
    text-transform: uppercase;
    letter-spacing: .08em;
    white-space: nowrap;
}

.ht-table th:last-child {
    text-align: center;
}

.ht-table tbody tr {
    border-bottom: 1px solid #f3f4f6;
    transition: all .25s ease;
}

.ht-table tbody tr:hover {
    background: linear-gradient(135deg, #fafbfc, #f8f9fa);
    box-shadow: 0 2px 8px rgba(0,0,0,.04);
    transform: translateX(2px);
}

.ht-table td {
    padding: 1rem 1.25rem;
    vertical-align: middle;
}

.ht-roll-badge {
    display: inline-flex;
    align-items: center;
    padding: .35rem .875rem;
    border-radius: 999px;
    font-size: .75rem;
    font-weight: 800;
    font-family: var(--mono);
    background: linear-gradient(135deg, #eef1fd, #e0e7ff);
    color: var(--brand);
    border: 1px solid #c7d2fe;
}

.ht-student-name {
    font-weight: 700;
    font-size: .875rem;
    color: #1f2937;
}

.ht-mobile {
    font-family: var(--mono);
    font-size: .8125rem;
    color: #6b7280;
}

.ht-action-cell {
    text-align: center;
}

.ht-status-group {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .4rem;
}

.ht-btn-generate {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: .65rem 1.25rem;
    border-radius: 10px;
    font-size: .8125rem;
    font-weight: 800;
    color: #fff;
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    border: none;
    cursor: pointer;
    transition: all .2s ease;
    box-shadow: 0 2px 8px rgba(99, 102, 241, .25);
    white-space: nowrap;
    letter-spacing: -0.01em;
    font-family: var(--font);
}

.ht-btn-generate:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 16px rgba(99, 102, 241, .35);
}

.ht-btn-generate svg {
    width: 16px;
    height: 16px;
}

.ht-btn-wa-notify {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    border: none;
    cursor: pointer;
    background: #dcfce7;
    color: #16a34a;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all .2s ease;
    flex-shrink: 0;
}

.ht-btn-wa-notify:hover {
    background: #25D366;
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 4px 14px rgba(37,211,102,.4);
}

.ht-btn-pending {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: .65rem 1.25rem;
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    color: #92400e;
    border: 1.5px solid #fcd34d;
    border-radius: 10px;
    font-size: .8125rem;
    font-weight: 800;
    cursor: pointer;
    transition: all .2s ease;
    white-space: nowrap;
    font-family: var(--font);
}

.ht-btn-pending:hover {
    background: linear-gradient(135deg, #fde68a, #fcd34d);
    transform: translateY(-1px);
}

.ht-btn-pending svg {
    width: 16px;
    height: 16px;
}

.ht-fee-badge {
    display: inline-block;
    padding: .25rem .6rem;
    background: linear-gradient(135deg, var(--amber), #d97706);
    color: white;
    border-radius: 999px;
    font-size: .7rem;
    font-weight: 800;
    font-family: var(--mono);
}

.ht-btn-nophoto {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: .65rem 1.25rem;
    background: linear-gradient(135deg, #ede9fe, #ddd6fe);
    color: #7c3aed;
    border: 1.5px solid #c4b5fd;
    border-radius: 10px;
    font-size: .8125rem;
    font-weight: 800;
    cursor: pointer;
    transition: all .2s ease;
    white-space: nowrap;
    font-family: var(--font);
}

.ht-btn-nophoto:hover {
    background: linear-gradient(135deg, var(--purple), #7c3aed);
    color: #fff;
    transform: translateY(-1px);
}

.ht-btn-nophoto svg {
    width: 16px;
    height: 16px;
}

.ht-table-empty {
    padding: 3rem 1.5rem;
    text-align: center;
    color: #9ca3af;
}

.ht-table-empty svg {
    width: 48px;
    height: 48px;
    stroke: #d1d5db;
    margin-bottom: 1rem;
}

.ht-table-empty p {
    font-size: .9375rem;
    font-weight: 500;
}

/* Modal */
.ht-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 9999;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}

.ht-modal-card {
    background: #ffffff;
    border-radius: 20px;
    max-width: 900px;
    width: 92%;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    overflow: hidden;
    display: flex;
    flex-direction: column;
    max-height: 90vh;
}

.ht-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1.5rem 2rem;
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: white;
}

.ht-modal-header h3 {
    display: flex;
    align-items: center;
    gap: .75rem;
    font-size: 1.125rem;
    font-weight: 800;
    margin: 0;
    color: white;
}

.ht-modal-header svg {
    width: 22px;
    height: 22px;
}

.ht-modal-close {
    background: rgba(255,255,255,.15);
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255,255,255,.2);
    border-radius: 10px;
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    color: #fff;
    transition: all .2s ease;
}

.ht-modal-close:hover {
    background: rgba(255,255,255,.25);
    transform: rotate(90deg);
}

.ht-modal-close svg {
    width: 18px;
    height: 18px;
}

.ht-modal-body {
    padding: 0;
    max-height: calc(90vh - 180px);
    overflow-y: auto;
}

.ht-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: .875rem;
    padding: 1.25rem 2rem;
    border-top: 1.5px solid #e5e7eb;
    background: #ffffff;
}

.ht-btn-cancel {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: .75rem 1.75rem;
    border-radius: 10px;
    border: 1.5px solid #e5e7eb;
    background: #ffffff;
    color: #6b7280;
    font-size: .875rem;
    font-weight: 700;
    cursor: pointer;
    transition: all .2s ease;
    font-family: var(--font);
}

.ht-btn-cancel:hover {
    background: #f9fafb;
    color: #374151;
    border-color: #d1d5db;
    transform: translateY(-1px);
}

.ht-btn-cancel svg {
    width: 16px;
    height: 16px;
}

.ht-btn-print {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    padding: .75rem 2rem;
    border-radius: 10px;
    border: none;
    background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
    color: #fff;
    font-size: .875rem;
    font-weight: 800;
    cursor: pointer;
    transition: all .2s ease;
    box-shadow: 0 4px 14px rgba(99, 102, 241, .3);
    font-family: var(--font);
}

.ht-btn-print:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(99, 102, 241, .4);
}

.ht-btn-print svg {
    width: 16px;
    height: 16px;
}

/* Hall Ticket Design */
.hall-ticket {
    background: white;
    padding: 2rem;
}

.hall-ticket-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 1.5rem;
    border-bottom: 3px solid #6366f1;
    margin-bottom: 1.5rem;
}

.hall-ticket-logo img {
    width: 80px;
    height: 80px;
    object-fit: contain;
}

.hall-ticket-title {
    flex: 1;
    text-align: center;
    padding: 0 1.5rem;
}

.hall-ticket-title h1 {
    font-size: 1.5rem;
    color: #6366f1;
    margin-bottom: 0.5rem;
}

.hall-ticket-title h2 {
    font-size: 1.2rem;
    color: #8b5cf6;
    margin-bottom: 0.5rem;
}

.hall-ticket-center {
    font-size: 0.9rem;
    color: #666;
}

.hall-ticket-photo img {
    width: 100px;
    height: 120px;
    object-fit: cover;
    border: 2px solid #6366f1;
}

.hall-ticket-section {
    margin-bottom: 1.5rem;
}

.hall-ticket-section h3 {
    background: linear-gradient(135deg, #6366f1, #8b5cf6);
    color: white;
    padding: 0.6rem 1rem;
    font-size: 0.9rem;
    margin-bottom: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.hall-ticket-table {
    width: 100%;
    border-collapse: collapse;
}

.hall-ticket-table td {
    padding: 0.6rem;
    border: 1px solid #ddd;
    font-size: 0.9rem;
}

.hall-ticket-table td.label {
    background: #f9fafb;
    font-weight: 600;
    width: 25%;
    color: #374151;
}

.hall-ticket-instructions {
    padding-left: 1.5rem;
    font-size: 0.85rem;
    line-height: 1.8;
}

.hall-ticket-instructions li {
    margin-bottom: 0.4rem;
}

.hall-ticket-footer {
    border-top: 2px solid #6366f1;
    padding-top: 1.5rem;
    margin-top: 1.5rem;
}

.hall-ticket-signature {
    display: flex;
    justify-content: space-between;
    margin-bottom: 1.5rem;
}

.signature-box {
    text-align: center;
    flex: 1;
}

.signature-line {
    width: 150px;
    height: 50px;
    border-bottom: 2px solid #333;
    margin: 0 auto 0.5rem;
}

.signature-box p {
    font-size: 0.8rem;
    font-weight: 600;
}

.hall-ticket-note {
    text-align: center;
    font-size: 0.75rem;
    color: #666;
    line-height: 1.6;
}

/* Responsive */
@media (max-width: 1024px) {
    .ht-toolbar {
        flex-direction: column;
        align-items: stretch;
    }
    
    .ht-toolbar-actions {
        flex-wrap: wrap;
    }
    
    .ht-search-box {
        min-width: 100%;
        max-width: 100%;
    }
}

@media (max-width: 768px) {
    .ht-container {
        padding: 1.25rem 1rem;
    }
    
    .ht-kpi-card {
        padding: 1.25rem;
    }
    
    .ht-kpi-icon {
        width: 40px;
        height: 40px;
    }
    
    .ht-kpi-icon svg {
        width: 20px;
        height: 20px;
    }
    
    .ht-kpi-value {
        font-size: 1.75rem;
    }
    
    .ht-kpi-label {
        font-size: .75rem;
    }
    
    .ht-toolbar-actions {
        flex-direction: column;
        width: 100%;
    }
    
    .ht-select,
    .ht-btn-search {
        width: 100%;
    }
    
    .ht-table th,
    .ht-table td {
        padding: .75rem .875rem;
        font-size: .8125rem;
    }
    
    .ht-modal-card {
        width: 98%;
    }
    
    .ht-modal-header,
    .ht-modal-footer {
        padding: 1rem 1.25rem;
    }
}

@media (max-width: 640px) {
    .ht-container {
        padding: 1rem .75rem;
    }
    
    .ht-kpi-grid {
        gap: 1rem;
    }
    
    .ht-toolbar-title {
        font-size: 1rem;
    }
    
    .ht-btn-generate,
    .ht-btn-pending,
    .ht-btn-nophoto {
        padding: .5rem 1rem;
        font-size: .75rem;
    }
    
    .ht-status-group {
        gap: .3rem;
    }
    
    .ht-fee-badge {
        font-size: .65rem;
        padding: .2rem .5rem;
    }
}
</style>
</body>
</html>
