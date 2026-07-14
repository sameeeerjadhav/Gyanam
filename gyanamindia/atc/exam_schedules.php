<?php
/**
 * Gyanam Portal — ATC: Exam Schedules & Assignments
 * Tabular layout with bulk slot/date/time assignment.
 * Uses local exam_schedules table + optional Exam Portal API sync.
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
if (file_exists(__DIR__ . '/../includes/notifications.php'))
    require_once __DIR__ . '/../includes/notifications.php';
if (file_exists(__DIR__ . '/../includes/exam_integration.php'))
    require_once __DIR__ . '/../includes/exam_integration.php';

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$atcId = $_SESSION['atc_id'] ?? null;

// Fetch ATC centre name for WhatsApp messages
$atcName = 'Gyanam ATC';
try {
    $atcNameStmt = $pdo->prepare("SELECT name FROM atc_centers WHERE id = ?");
    $atcNameStmt->execute([$atcId]);
    $atcName = $atcNameStmt->fetchColumn() ?: 'Gyanam ATC';
} catch (Exception $e) {}

// ATC details
$atcDetails = [];
try {
    $s = $pdo->prepare("SELECT * FROM atc_centers WHERE id = ?");
    $s->execute([$atcId]);
    $atcDetails = $s->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
}

// ── Fetch available exams from Exam Portal ───────────────────────────────────
$portalExams = [];
try {
    if (function_exists('fetchAvailableExams') && defined('EXAM_API_TOKEN') && EXAM_API_TOKEN !== 'PASTE_YOUR_TOKEN_HERE') {
        $res = fetchAvailableExams();
        if ($res['success'] && !empty($res['data'])) {
            $portalExams = $res['data'];
        }
    }
} catch (Exception $e) {}

// ── Auto-create & auto-migrate exam_schedules table ─────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS exam_schedules (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        admission_id     INT NOT NULL,
        atc_id           INT NOT NULL,
        exam_date        DATE DEFAULT NULL,
        exam_time        TIME NOT NULL DEFAULT '10:00:00',
        exam_slot        ENUM('Morning','Afternoon','Evening') NOT NULL DEFAULT 'Morning',
        exam_hall        VARCHAR(100) DEFAULT NULL,
        exam_name        VARCHAR(255) DEFAULT NULL,
        exam_portal_id   INT DEFAULT NULL,
        allowed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 1,
        exam_status      ENUM('Scheduled','Appeared','Passed','Failed','Absent') NOT NULL DEFAULT 'Scheduled',
        notes            TEXT DEFAULT NULL,
        created_at       DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_atc_id      (atc_id),
        INDEX idx_admission_id(admission_id),
        INDEX idx_exam_date   (exam_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Add columns if they don't exist (migration for older installs)
    try {
        $pdo->query("SELECT exam_slot FROM exam_schedules LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE exam_schedules ADD COLUMN exam_slot ENUM('Morning','Afternoon','Evening') NOT NULL DEFAULT 'Morning' AFTER exam_time");
    }
    try {
        $pdo->query("SELECT exam_status FROM exam_schedules LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE exam_schedules ADD COLUMN exam_status ENUM('Scheduled','Appeared','Passed','Failed','Absent') NOT NULL DEFAULT 'Scheduled' AFTER exam_hall");
    }
    try {
        $pdo->query("SELECT exam_name FROM exam_schedules LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE exam_schedules ADD COLUMN exam_name VARCHAR(255) DEFAULT NULL AFTER exam_hall");
    }
    try {
        $pdo->query("SELECT exam_portal_id FROM exam_schedules LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE exam_schedules ADD COLUMN exam_portal_id INT DEFAULT NULL AFTER exam_name");
    }
    // Migrate: add allowed_attempts if missing
    try {
        $pdo->query("SELECT allowed_attempts FROM exam_schedules LIMIT 1");
    } catch (Exception $e) {
        $pdo->exec("ALTER TABLE exam_schedules ADD COLUMN allowed_attempts TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER exam_portal_id");
    }
} catch (Exception $e) {
}

// ── AJAX Handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        switch ($_POST['action']) {

            case 'schedule_single':
                $admId = intval($_POST['admission_id'] ?? 0);
                $date = trim($_POST['exam_date'] ?? '');
                $time = trim($_POST['exam_time'] ?? '10:00');
                $slot = trim($_POST['exam_slot'] ?? 'Morning');
                $hall = trim($_POST['exam_hall'] ?? '');
                $examName = trim($_POST['exam_name'] ?? '');
                $examPortalId = intval($_POST['exam_portal_id'] ?? 0) ?: null;
                $allowedAttempts = max(1, min(10, intval($_POST['allowed_attempts'] ?? 1)));
                if (!$admId || !$date) {
                    echo json_encode(['success' => false, 'message' => 'Missing fields.']);
                    exit;
                }
                // Verify student belongs to this ATC
                $chk = $pdo->prepare("SELECT id, registration_id FROM admissions WHERE id = ? AND atc_id = ?");
                $chk->execute([$admId, $atcId]);
                $admRow = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$admRow) {
                    echo json_encode(['success' => false, 'message' => 'Student not found.']);
                    exit;
                }
                // Upsert
                $ex = $pdo->prepare("SELECT id FROM exam_schedules WHERE admission_id = ? AND atc_id = ?");
                $ex->execute([$admId, $atcId]);
                if ($row = $ex->fetch()) {
                    $pdo->prepare("UPDATE exam_schedules SET exam_date=?,exam_time=?,exam_slot=?,exam_hall=?,exam_name=?,exam_portal_id=?,allowed_attempts=? WHERE id=?")
                        ->execute([$date, $time, $slot, $hall, $examName, $examPortalId, $allowedAttempts, $row['id']]);
                } else {
                    $pdo->prepare("INSERT INTO exam_schedules(admission_id,atc_id,exam_date,exam_time,exam_slot,exam_hall,exam_name,exam_portal_id,allowed_attempts) VALUES(?,?,?,?,?,?,?,?,?)")
                        ->execute([$admId, $atcId, $date, $time, $slot, $hall, $examName, $examPortalId, $allowedAttempts]);
                }
                // Sync to Exam Portal: assign exam to student
                if ($examPortalId && !empty($admRow['registration_id'])) {
                    try {
                        if (function_exists('fetchExamStudents') && defined('EXAM_API_TOKEN') && EXAM_API_TOKEN !== 'PASTE_YOUR_TOKEN_HERE') {
                            $esRes = fetchExamStudents();
                            if ($esRes['success'] && !empty($esRes['data'])) {
                                foreach ($esRes['data'] as $es) {
                                    if (($es['identifier'] ?? '') === $admRow['registration_id'] && !empty($es['id'])) {
                                        assignExamToStudent(intval($es['id']), $examPortalId);
                                        break;
                                    }
                                }
                            }
                        }
                    } catch (Exception $e) { /* silently fail sync */ }
                }
                echo json_encode(['success' => true, 'message' => 'Schedule saved.']);
                exit;

            case 'update_attempts':
                $admId = intval($_POST['admission_id'] ?? 0);
                $attempts = max(1, min(10, intval($_POST['allowed_attempts'] ?? 1)));
                if (!$admId) { echo json_encode(['success' => false, 'message' => 'Missing student.']); exit; }
                $ex = $pdo->prepare("SELECT id FROM exam_schedules WHERE admission_id = ? AND atc_id = ?");
                $ex->execute([$admId, $atcId]);
                if ($row = $ex->fetch()) {
                    $pdo->prepare("UPDATE exam_schedules SET allowed_attempts = ? WHERE id = ?")->execute([$attempts, $row['id']]);
                    echo json_encode(['success' => true, 'message' => "Attempts set to $attempts."]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Student not yet scheduled.']);
                }
                exit;

            case 'bulk_schedule':
                $ids = json_decode($_POST['student_ids'] ?? '[]', true);
                $date = trim($_POST['exam_date'] ?? '');
                $time = trim($_POST['exam_time'] ?? '10:00');
                $slot = trim($_POST['exam_slot'] ?? 'Morning');
                $hall = trim($_POST['exam_hall'] ?? '');
                $examName = trim($_POST['exam_name'] ?? '');
                $examPortalId = intval($_POST['exam_portal_id'] ?? 0) ?: null;
                $allowedAttempts = max(1, min(10, intval($_POST['allowed_attempts'] ?? 1)));
                if (empty($ids) || !$date) {
                    echo json_encode(['success' => false, 'message' => 'Select students and date.']);
                    exit;
                }
                $count = 0;
                $syncIds = []; // collect registration IDs for portal sync
                foreach ($ids as $admId) {
                    $admId = intval($admId);
                    $chk = $pdo->prepare("SELECT id, registration_id FROM admissions WHERE id = ? AND atc_id = ?");
                    $chk->execute([$admId, $atcId]);
                    $admRow = $chk->fetch(PDO::FETCH_ASSOC);
                    if (!$admRow)
                        continue;
                    $ex = $pdo->prepare("SELECT id FROM exam_schedules WHERE admission_id = ? AND atc_id = ?");
                    $ex->execute([$admId, $atcId]);
                    if ($row = $ex->fetch()) {
                        $pdo->prepare("UPDATE exam_schedules SET exam_date=?,exam_time=?,exam_slot=?,exam_hall=?,exam_name=?,exam_portal_id=?,allowed_attempts=? WHERE id=?")
                            ->execute([$date, $time, $slot, $hall, $examName, $examPortalId, $allowedAttempts, $row['id']]);
                    } else {
                        $pdo->prepare("INSERT INTO exam_schedules(admission_id,atc_id,exam_date,exam_time,exam_slot,exam_hall,exam_name,exam_portal_id,allowed_attempts) VALUES(?,?,?,?,?,?,?,?,?)")
                            ->execute([$admId, $atcId, $date, $time, $slot, $hall, $examName, $examPortalId, $allowedAttempts]);
                    }
                    if (!empty($admRow['registration_id'])) {
                        $syncIds[$admRow['registration_id']] = true;
                    }
                    $count++;
                }
                // Bulk sync to Exam Portal
                if ($examPortalId && !empty($syncIds)) {
                    try {
                        if (function_exists('fetchExamStudents') && defined('EXAM_API_TOKEN') && EXAM_API_TOKEN !== 'PASTE_YOUR_TOKEN_HERE') {
                            $esRes = fetchExamStudents();
                            if ($esRes['success'] && !empty($esRes['data'])) {
                                $portalStudentIds = [];
                                foreach ($esRes['data'] as $es) {
                                    if (isset($syncIds[$es['identifier'] ?? '']) && !empty($es['id'])) {
                                        $portalStudentIds[] = intval($es['id']);
                                    }
                                }
                                // Use bulk assign if available
                                if (!empty($portalStudentIds)) {
                                    examApi_request('POST', '/assignments/bulk-assign', [
                                        'student_ids' => $portalStudentIds,
                                        'exam_id' => $examPortalId,
                                        'max_attempts' => 1,
                                    ]);
                                }
                            }
                        }
                    } catch (Exception $e) { /* silently fail sync */ }
                }
                echo json_encode(['success' => true, 'message' => "Scheduled $count student(s)."]);
                exit;

            case 'bulk_slot':
                $ids = json_decode($_POST['student_ids'] ?? '[]', true);
                $slot = trim($_POST['exam_slot'] ?? '');
                if (empty($ids) || !$slot) {
                    echo json_encode(['success' => false, 'message' => 'Select students and slot.']);
                    exit;
                }
                $count = 0;
                foreach ($ids as $admId) {
                    $admId = intval($admId);
                    $pdo->prepare("UPDATE exam_schedules SET exam_slot = ? WHERE admission_id = ? AND atc_id = ?")
                        ->execute([$slot, $admId, $atcId]);
                    $count += $pdo->prepare("SELECT ROW_COUNT()")->execute() ? 1 : 0;
                }
                echo json_encode(['success' => true, 'message' => "Updated slot for $count student(s)."]);
                exit;

            case 'bulk_timing':
                $ids = json_decode($_POST['student_ids'] ?? '[]', true);
                $time = trim($_POST['exam_time'] ?? '');
                if (empty($ids) || !$time) {
                    echo json_encode(['success' => false, 'message' => 'Select students and time.']);
                    exit;
                }
                $count = 0;
                foreach ($ids as $admId) {
                    $admId = intval($admId);
                    $pdo->prepare("UPDATE exam_schedules SET exam_time = ? WHERE admission_id = ? AND atc_id = ?")
                        ->execute([$time, $admId, $atcId]);
                    $count++;
                }
                echo json_encode(['success' => true, 'message' => "Updated timing for $count student(s)."]);
                exit;

            case 'remove_schedule':
                $admId = intval($_POST['admission_id'] ?? 0);
                $pdo->prepare("DELETE FROM exam_schedules WHERE admission_id = ? AND atc_id = ?")->execute([$admId, $atcId]);
                echo json_encode(['success' => true, 'message' => 'Schedule removed.']);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ── Fetch all active students with their exam schedule status ─────────────────
$courseFilter = trim($_GET['course'] ?? 'all');
$statusFilter = trim($_GET['status'] ?? 'all');

// ── Build set of student IDs whose share has been paid ──────────────────────
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

$sql = "SELECT a.id, a.roll_no, a.registration_id,
               a.first_name, a.middle_name, a.last_name,
               a.course, a.photo, a.mobile,
               es.exam_date, es.exam_time, es.exam_slot, es.exam_hall, es.exam_name, es.exam_portal_id, es.exam_status,
               COALESCE(es.allowed_attempts, 1) AS allowed_attempts
        FROM admissions a
        LEFT JOIN exam_schedules es ON es.admission_id = a.id AND es.atc_id = a.atc_id
        WHERE a.atc_id = ? AND a.status = 'Active'";
$params = [$atcId];

if ($courseFilter !== 'all') {
    $sql .= " AND a.course = ?";
    $params[] = $courseFilter;
}

$sql .= " ORDER BY a.roll_no ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allStudents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Only show students whose share is paid ──────────────────────────────────
foreach ($allStudents as &$s) {
    $s['share_paid'] = isset($paidStudentIds[intval($s['id'])]);
}
unset($s);
$allStudents = array_values(array_filter($allStudents, fn($s) => $s['share_paid']));

// Apply status filter in PHP (since LEFT JOIN makes null = unscheduled)
if ($statusFilter === 'scheduled') {
    $allStudents = array_values(array_filter($allStudents, fn($s) => !empty($s['exam_date'])));
} elseif ($statusFilter === 'unscheduled') {
    $allStudents = array_values(array_filter($allStudents, fn($s) => empty($s['exam_date'])));
} elseif ($statusFilter === 'passed') {
    $allStudents = array_values(array_filter($allStudents, fn($s) => ($s['exam_status'] ?? '') === 'Passed'));
} elseif ($statusFilter === 'failed') {
    $allStudents = array_values(array_filter($allStudents, fn($s) => ($s['exam_status'] ?? '') === 'Failed'));
}

// Courses for filter
$courses = [];
try {
    $cStmt = $pdo->prepare("SELECT DISTINCT course FROM admissions WHERE atc_id = ? AND status = 'Active' ORDER BY course");
    $cStmt->execute([$atcId]);
    $courses = $cStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
}

// KPIs (from full set before status filter)
$kpiAll = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE atc_id = ? AND status = 'Active'");
$kpiAll->execute([$atcId]);
$kpiTotal = $kpiAll->fetchColumn();

$kpiSch = $pdo->prepare("SELECT COUNT(*) FROM exam_schedules WHERE atc_id = ? AND exam_date IS NOT NULL");
$kpiSch->execute([$atcId]);
$kpiScheduled = $kpiSch->fetchColumn();

$kpiPassed = 0;
$kpiFailed = 0;
try {
    $kpiP = $pdo->prepare("SELECT COUNT(*) FROM exam_schedules WHERE atc_id = ? AND exam_status = 'Passed'");
    $kpiP->execute([$atcId]);
    $kpiPassed = $kpiP->fetchColumn();
    $kpiF = $pdo->prepare("SELECT COUNT(*) FROM exam_schedules WHERE atc_id = ? AND exam_status = 'Failed'");
    $kpiF->execute([$atcId]);
    $kpiFailed = $kpiF->fetchColumn();
} catch (Exception $e) {
}

$kpiUnscheduled = $kpiTotal - $kpiScheduled;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Schedules — ATC Login | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <?php if (file_exists(__DIR__ . '/../assets/css/notifications.css')): ?>
        <link rel="stylesheet" href="../assets/css/notifications.css">
    <?php endif; ?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="icon"
        href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📝</text></svg>">
    <style>
        :root {
            --es-brand: #4f46e5;
            --es-brand-dk: #3730a3;
            --es-brand-lt: #eef2ff;
            --es-green: #059669;
            --es-green-lt: #ecfdf5;
            --es-amber: #d97706;
            --es-amber-lt: #fffbeb;
            --es-red: #dc2626;
            --es-red-lt: #fef2f2;
            --es-violet: #7c3aed;
            --es-violet-lt: #f5f3ff;
            --es-radius: 14px;
            --es-shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
            --es-shadow-md: 0 4px 16px rgba(0,0,0,.06), 0 2px 4px rgba(0,0,0,.04);
            --es-shadow-lg: 0 12px 40px rgba(0,0,0,.1);
        }

        .page-content { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }

        /* KPI */
        .es-kpi {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(185px, 1fr));
            gap: 1rem;
            margin-bottom: 1.75rem
        }

        .es-kpi-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: var(--es-radius);
            padding: 1.25rem 1.4rem;
            display: flex;
            align-items: center;
            gap: .9rem;
            position: relative;
            overflow: hidden;
            transition: all .25s cubic-bezier(.4,0,.2,1);
            box-shadow: var(--es-shadow-sm)
        }

        .es-kpi-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            border-radius: 0 4px 4px 0
        }

        .es-kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--es-shadow-md)
        }

        .es-kpi-card.brand::before { background: linear-gradient(180deg, var(--es-brand), #818cf8) }
        .es-kpi-card.green::before { background: linear-gradient(180deg, var(--es-green), #34d399) }
        .es-kpi-card.amber::before { background: linear-gradient(180deg, var(--es-amber), #fbbf24) }
        .es-kpi-card.red::before { background: linear-gradient(180deg, var(--es-red), #f87171) }
        .es-kpi-card.violet::before { background: linear-gradient(180deg, var(--es-violet), #a78bfa) }

        .es-kpi-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0
        }

        .es-kpi-icon svg { width: 20px; height: 20px; stroke: currentColor; fill: none; stroke-width: 2 }

        .es-kpi-card.brand .es-kpi-icon { background: var(--es-brand-lt); color: var(--es-brand) }
        .es-kpi-card.green .es-kpi-icon { background: var(--es-green-lt); color: var(--es-green) }
        .es-kpi-card.amber .es-kpi-icon { background: var(--es-amber-lt); color: var(--es-amber) }
        .es-kpi-card.red .es-kpi-icon { background: var(--es-red-lt); color: var(--es-red) }
        .es-kpi-card.violet .es-kpi-icon { background: var(--es-violet-lt); color: var(--es-violet) }

        .es-kpi-val {
            font-size: 1.65rem;
            font-weight: 800;
            color: #111827;
            line-height: 1;
            letter-spacing: -.02em
        }

        .es-kpi-lbl {
            font-size: .68rem;
            font-weight: 600;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .06em;
            margin-top: .2rem
        }

        /* Toolbar */
        .es-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
            margin-bottom: 1.25rem
        }

        .es-toolbar-left, .es-toolbar-right {
            display: flex;
            align-items: center;
            gap: .6rem;
            flex-wrap: wrap
        }

        .es-filter-select {
            padding: .55rem 1rem .55rem .85rem;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-size: .82rem;
            font-weight: 600;
            font-family: 'Inter', inherit;
            outline: none;
            background: #fff;
            color: #374151;
            cursor: pointer;
            transition: all .2s
        }

        .es-filter-select:focus {
            border-color: var(--es-brand);
            box-shadow: 0 0 0 3px rgba(79,70,229,.1)
        }

        .es-search-input {
            padding: .55rem 1rem;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-size: .82rem;
            font-family: 'Inter', inherit;
            outline: none;
            width: 240px;
            transition: all .2s;
            color: #374151
        }

        .es-search-input::placeholder { color: #9ca3af }

        .es-search-input:focus {
            border-color: var(--es-brand);
            box-shadow: 0 0 0 3px rgba(79,70,229,.1)
        }

        /* Status tabs */
        .es-tabs {
            display: flex;
            gap: .4rem;
            margin-bottom: 1.25rem;
            flex-wrap: wrap;
            padding: 4px;
            background: #f3f4f6;
            border-radius: 12px;
            width: fit-content
        }

        .es-tab {
            padding: .5rem 1.1rem;
            font-size: .8rem;
            font-weight: 600;
            color: #6b7280;
            border: none;
            border-radius: 9px;
            background: transparent;
            cursor: pointer;
            font-family: 'Inter', inherit;
            transition: all .2s;
            text-decoration: none
        }

        .es-tab:hover {
            background: #fff;
            color: #374151;
            box-shadow: var(--es-shadow-sm)
        }

        .es-tab.active {
            background: #fff;
            color: var(--es-brand);
            box-shadow: var(--es-shadow-sm);
            font-weight: 700
        }

        .es-tab .tab-count {
            font-size: .65rem;
            font-weight: 800;
            background: #e5e7eb;
            padding: .1rem .45rem;
            border-radius: 999px;
            margin-left: .35rem;
            color: #6b7280
        }

        .es-tab.active .tab-count {
            background: var(--es-brand-lt);
            color: var(--es-brand)
        }

        /* Table */
        .es-card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: var(--es-radius);
            overflow: hidden;
            box-shadow: var(--es-shadow-sm)
        }

        .es-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .84rem
        }

        .es-table thead th {
            padding: .8rem 1rem;
            text-align: left;
            font-size: .67rem;
            font-weight: 700;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: .06em;
            border-bottom: 1px solid #e5e7eb;
            background: #f9fafb;
            white-space: nowrap
        }

        .es-table tbody tr {
            border-bottom: 1px solid #f3f4f6;
            transition: all .15s
        }

        .es-table tbody tr:last-child { border-bottom: none }

        .es-table tbody tr:hover {
            background: #f8fafc
        }

        .es-table tbody tr.selected { background: var(--es-brand-lt) }

        .es-table tbody td {
            padding: .75rem 1rem;
            vertical-align: middle
        }

        /* Student name cell */
        .es-stu-cell {
            display: flex;
            align-items: center;
            gap: .65rem
        }

        .es-stu-photo {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            object-fit: cover;
            border: 1.5px solid #e5e7eb;
            flex-shrink: 0
        }

        .es-stu-initials {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--es-brand), var(--es-violet));
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: .75rem;
            flex-shrink: 0
        }

        .es-stu-name {
            font-weight: 600;
            font-size: .82rem;
            color: #111827
        }

        .es-stu-roll {
            font-size: .7rem;
            color: #9ca3af;
            margin-top: .1rem
        }

        /* Badges */
        .es-badge {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            padding: .25rem .65rem;
            border-radius: 999px;
            font-size: .67rem;
            font-weight: 700;
            white-space: nowrap;
            letter-spacing: .01em
        }

        .es-badge-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor
        }

        .es-badge-scheduled { background: var(--es-brand-lt); color: var(--es-brand); border: 1px solid #c7d2fe }
        .es-badge-passed { background: var(--es-green-lt); color: #065f46; border: 1px solid #a7f3d0 }
        .es-badge-failed { background: var(--es-red-lt); color: #991b1b; border: 1px solid #fecaca }
        .es-badge-absent { background: var(--es-amber-lt); color: #92400e; border: 1px solid #fde68a }
        .es-badge-unscheduled { background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb }

        /* Action buttons */
        .es-btn {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            padding: .4rem .8rem;
            border: 1.5px solid #e5e7eb;
            border-radius: 9px;
            font-size: .73rem;
            font-weight: 600;
            cursor: pointer;
            background: #fff;
            color: #4b5563;
            transition: all .2s cubic-bezier(.4,0,.2,1);
            font-family: 'Inter', inherit;
            white-space: nowrap
        }

        .es-btn:hover {
            border-color: var(--es-brand);
            color: var(--es-brand);
            box-shadow: 0 2px 6px rgba(79,70,229,.1)
        }

        .es-btn svg { width: 13px; height: 13px }

        .es-btn[style*="dcfce7"]:hover {
            background: #25D366 !important;
            border-color: #25D366 !important;
            color: #fff !important;
            box-shadow: 0 4px 12px rgba(37,211,102,.35);
            transform: translateY(-1px)
        }

        .es-btn-primary {
            background: linear-gradient(135deg, var(--es-brand), var(--es-brand-dk));
            color: #fff;
            border-color: transparent;
            box-shadow: 0 2px 8px rgba(79,70,229,.25)
        }

        .es-btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 20px rgba(79,70,229,.3);
            color: #fff
        }

        .es-btn-danger {
            border-color: #fecaca;
            color: var(--es-red);
            background: var(--es-red-lt)
        }

        .es-btn-danger:hover {
            background: #fee2e2;
            box-shadow: 0 2px 6px rgba(220,38,38,.1)
        }

        /* Bulk action bar */
        .es-bulk-bar {
            display: none;
            align-items: center;
            gap: .75rem;
            padding: .75rem 1.25rem;
            background: linear-gradient(135deg, var(--es-brand-lt), #e0e7ff);
            border: 1px solid #c7d2fe;
            border-radius: 12px;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            animation: slideDown .25s cubic-bezier(.4,0,.2,1)
        }

        .es-bulk-bar.visible { display: flex }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-8px) }
            to { opacity: 1; transform: translateY(0) }
        }

        .es-bulk-count {
            font-size: .82rem;
            font-weight: 800;
            color: var(--es-brand)
        }

        .es-bulk-sep {
            width: 1px;
            height: 24px;
            background: #c7d2fe
        }

        .es-bulk-group {
            display: flex;
            align-items: center;
            gap: .4rem
        }

        .es-bulk-group label {
            font-size: .72rem;
            font-weight: 700;
            color: var(--es-brand-dk);
            text-transform: uppercase;
            letter-spacing: .03em;
            white-space: nowrap
        }

        .es-bulk-input {
            padding: .4rem .65rem;
            border: 1.5px solid #c7d2fe;
            border-radius: 8px;
            font-size: .8rem;
            font-family: 'Inter', inherit;
            background: #fff
        }

        .es-bulk-input:focus {
            border-color: var(--es-brand);
            outline: none;
            box-shadow: 0 0 0 3px rgba(79,70,229,.1)
        }

        .es-bulk-btn {
            padding: .45rem .9rem;
            border: none;
            border-radius: 8px;
            font-size: .78rem;
            font-weight: 700;
            cursor: pointer;
            font-family: 'Inter', inherit;
            transition: all .2s
        }

        .es-bulk-btn-primary {
            background: linear-gradient(135deg, var(--es-brand), var(--es-brand-dk));
            color: #fff;
            box-shadow: 0 2px 6px rgba(79,70,229,.2)
        }

        .es-bulk-btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(79,70,229,.3)
        }

        .es-bulk-btn-slot {
            background: linear-gradient(135deg, var(--es-violet), #6d28d9);
            color: #fff
        }

        .es-bulk-btn-time {
            background: linear-gradient(135deg, var(--es-green), #059669);
            color: #fff
        }

        /* Empty */
        .es-empty {
            text-align: center;
            padding: 3.5rem 1.5rem;
            color: #9ca3af
        }

        .es-empty svg {
            width: 48px;
            height: 48px;
            stroke: #d1d5db;
            display: block;
            margin: 0 auto .85rem
        }

        .es-empty p {
            font-size: .88rem;
            font-weight: 500
        }

        /* Modal */
        .es-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, .45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            backdrop-filter: blur(6px);
            -webkit-backdrop-filter: blur(6px)
        }

        .es-overlay.open { display: flex }

        .es-modal {
            background: #fff;
            border-radius: 20px;
            width: 100%;
            max-width: 540px;
            margin: 1rem;
            box-shadow: var(--es-shadow-lg), 0 0 0 1px rgba(0,0,0,.03);
            animation: esSlideUp .3s cubic-bezier(.4,0,.2,1);
            overflow: hidden
        }

        @keyframes esSlideUp {
            from { opacity: 0; transform: translateY(20px) scale(.98) }
            to { opacity: 1; transform: translateY(0) scale(1) }
        }

        .es-modal-head {
            padding: 1.35rem 1.5rem;
            background: linear-gradient(135deg, #f8faff, var(--es-brand-lt));
            border-bottom: 1px solid #e0e7ff;
            display: flex;
            align-items: center;
            gap: .75rem
        }

        .es-modal-head-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--es-brand), var(--es-brand-dk));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(79,70,229,.3)
        }

        .es-modal-head-icon svg { width: 20px; height: 20px; stroke: #fff; fill: none }

        .es-modal-title {
            font-size: 1.05rem;
            font-weight: 800;
            color: #1e1b4b;
            letter-spacing: -.01em
        }

        .es-modal-sub {
            font-size: .78rem;
            color: var(--es-brand);
            margin-top: .1rem;
            font-weight: 500
        }

        .es-modal-close {
            margin-left: auto;
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 6px;
            border-radius: 8px;
            display: flex;
            transition: all .2s
        }

        .es-modal-close:hover {
            color: #1e293b;
            background: rgba(0,0,0,.05)
        }

        .es-modal-close svg { width: 18px; height: 18px }

        .es-modal-body { padding: 1.5rem }

        .es-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-bottom: 1rem
        }

        .es-form-row.full { grid-template-columns: 1fr }

        .es-form-label {
            display: block;
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: #6b7280;
            margin-bottom: .4rem
        }

        .es-form-input,
        .es-form-select {
            width: 100%;
            padding: .6rem .9rem;
            border: 1.5px solid #e5e7eb;
            border-radius: 10px;
            font-size: .85rem;
            font-family: 'Inter', inherit;
            outline: none;
            box-sizing: border-box;
            color: #374151;
            transition: all .2s
        }

        .es-form-input:focus,
        .es-form-select:focus {
            border-color: var(--es-brand);
            box-shadow: 0 0 0 3px rgba(79,70,229,.08)
        }

        .es-modal-footer {
            padding: 1rem 1.5rem;
            background: #f9fafb;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: .65rem
        }

        /* Checkbox */
        .es-cb {
            width: 16px;
            height: 16px;
            accent-color: var(--es-brand);
            cursor: pointer;
            border-radius: 4px
        }

        /* Toast */
        #esToastWrap {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            z-index: 99999;
            display: flex;
            flex-direction: column;
            gap: .5rem
        }

        @media(max-width:768px) {
            .es-toolbar { flex-direction: column; align-items: stretch }
            .es-toolbar-left, .es-toolbar-right { flex-direction: column }
            .es-search-input { width: 100% }
            .es-kpi { grid-template-columns: repeat(2, 1fr) }
            .es-bulk-bar { flex-direction: column; align-items: stretch }
            .es-tabs { width: 100% }
            .es-form-row { grid-template-columns: 1fr }
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
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <line x1="3" y1="12" x2="21" y2="12" />
                            <line x1="3" y1="6" x2="21" y2="6" />
                            <line x1="3" y1="18" x2="21" y2="18" />
                        </svg>
                    </button>
                    <div class="header-greeting">
                        <h2>Exam Schedules</h2>
                        <p>Assign exam dates, slots & timings for your students</p>
                    </div>
                </div>
                <div class="header-right">
                    <?php if (file_exists(__DIR__ . '/../includes/notification_bell.php'))
                        include __DIR__ . '/../includes/notification_bell.php'; ?>
                    <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
                </div>
            </header>

            <div class="page-content">

                <!-- KPI -->
                <div class="es-kpi">
                    <div class="es-kpi-card brand">
                        <div class="es-kpi-icon"><svg viewBox="0 0 24 24">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                                <circle cx="9" cy="7" r="4" />
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                                <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                            </svg></div>
                        <div>
                            <div class="es-kpi-val"><?= $kpiTotal ?></div>
                            <div class="es-kpi-lbl">Total Students</div>
                        </div>
                    </div>
                    <div class="es-kpi-card amber">
                        <div class="es-kpi-icon"><svg viewBox="0 0 24 24">
                                <rect x="3" y="4" width="18" height="18" rx="2" />
                                <line x1="16" y1="2" x2="16" y2="6" />
                                <line x1="8" y1="2" x2="8" y2="6" />
                                <line x1="3" y1="10" x2="21" y2="10" />
                            </svg></div>
                        <div>
                            <div class="es-kpi-val"><?= $kpiScheduled ?></div>
                            <div class="es-kpi-lbl">Scheduled</div>
                        </div>
                    </div>
                    <div class="es-kpi-card violet">
                        <div class="es-kpi-icon"><svg viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="10" />
                                <path d="M12 8v4l3 3" />
                            </svg></div>
                        <div>
                            <div class="es-kpi-val"><?= $kpiUnscheduled ?></div>
                            <div class="es-kpi-lbl">Unscheduled</div>
                        </div>
                    </div>
                    <div class="es-kpi-card green">
                        <div class="es-kpi-icon"><svg viewBox="0 0 24 24">
                                <polyline points="20 6 9 17 4 12" />
                            </svg></div>
                        <div>
                            <div class="es-kpi-val"><?= $kpiPassed ?></div>
                            <div class="es-kpi-lbl">Passed</div>
                        </div>
                    </div>
                    <div class="es-kpi-card red">
                        <div class="es-kpi-icon"><svg viewBox="0 0 24 24">
                                <line x1="18" y1="6" x2="6" y2="18" />
                                <line x1="6" y1="6" x2="18" y2="18" />
                            </svg></div>
                        <div>
                            <div class="es-kpi-val"><?= $kpiFailed ?></div>
                            <div class="es-kpi-lbl">Failed</div>
                        </div>
                    </div>
                </div>

                <!-- Status Tabs -->
                <div class="es-tabs">
                    <a href="?course=<?= urlencode($courseFilter) ?>&status=all"
                        class="es-tab <?= $statusFilter === 'all' ? 'active' : '' ?>">All<span
                            class="tab-count"><?= $kpiTotal ?></span></a>
                    <a href="?course=<?= urlencode($courseFilter) ?>&status=scheduled"
                        class="es-tab <?= $statusFilter === 'scheduled' ? 'active' : '' ?>">📅 Scheduled<span
                            class="tab-count"><?= $kpiScheduled ?></span></a>
                    <a href="?course=<?= urlencode($courseFilter) ?>&status=unscheduled"
                        class="es-tab <?= $statusFilter === 'unscheduled' ? 'active' : '' ?>">⏳ Unscheduled<span
                            class="tab-count"><?= $kpiUnscheduled ?></span></a>
                    <a href="?course=<?= urlencode($courseFilter) ?>&status=passed"
                        class="es-tab <?= $statusFilter === 'passed' ? 'active' : '' ?>">✅ Passed<span
                            class="tab-count"><?= $kpiPassed ?></span></a>
                    <a href="?course=<?= urlencode($courseFilter) ?>&status=failed"
                        class="es-tab <?= $statusFilter === 'failed' ? 'active' : '' ?>">❌ Failed<span
                            class="tab-count"><?= $kpiFailed ?></span></a>
                </div>

                <!-- Toolbar -->
                <div class="es-toolbar">
                    <div class="es-toolbar-left">
                        <select class="es-filter-select" id="esCourseFilter"
                            onchange="location='?course='+this.value+'&status=<?= urlencode($statusFilter) ?>'">
                            <option value="all" <?= $courseFilter === 'all' ? 'selected' : '' ?>>All Courses</option>
                            <?php foreach ($courses as $c): ?>
                                <option value="<?= htmlspecialchars($c) ?>" <?= $courseFilter === $c ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="text" class="es-search-input" id="esSearch" placeholder="Search by name, roll no…"
                            autocomplete="off">
                        <button class="es-btn" id="selectAllCourseBtn" onclick="selectAllVisible()" title="Select all visible students">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><polyline points="20 6 9 17 4 12"/></svg>
                            Select All
                        </button>
                    </div>
                    <div class="es-toolbar-right">
                        <button class="es-btn es-btn-primary" onclick="openBulkModal()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" />
                                <line x1="16" y1="2" x2="16" y2="6" />
                                <line x1="8" y1="2" x2="8" y2="6" />
                                <line x1="3" y1="10" x2="21" y2="10" />
                            </svg>
                            Bulk Schedule
                        </button>
                    </div>
                </div>

                <!-- Bulk Action Bar -->
                <div class="es-bulk-bar" id="esBulkBar">
                    <span class="es-bulk-count" id="esBulkCount">0 selected</span>
                    <span class="es-bulk-sep"></span>

                    <div class="es-bulk-group">
                        <label>Slot:</label>
                        <select class="es-bulk-input" id="bulkSlotSelect" style="width:130px">
                            <option value="Morning">🌅 Morning</option>
                            <option value="Afternoon">☀️ Afternoon</option>
                            <option value="Evening">🌙 Evening</option>
                        </select>
                        <button class="es-bulk-btn es-bulk-btn-slot" onclick="bulkAssignSlot()">Assign Slot</button>
                    </div>
                    <span class="es-bulk-sep"></span>

                    <div class="es-bulk-group">
                        <label>Time:</label>
                        <input type="time" class="es-bulk-input" id="bulkTimeInput" value="10:00" style="width:110px">
                        <button class="es-bulk-btn es-bulk-btn-time" onclick="bulkAssignTiming()">Set Time</button>
                    </div>
                </div>

                <!-- Table -->
                <div class="es-card">
                    <div style="overflow-x:auto">
                        <table class="es-table" id="esTable">
                            <thead>
                                <tr>
                                    <th style="width:36px"><input type="checkbox" class="es-cb" id="selectAll"
                                            onclick="toggleSelectAll(this)"></th>
                                    <th>#</th>
                                    <th>Student</th>
                                    <th>Reg ID</th>
                                    <th>Course</th>
                                    <th>Exam</th>
                                    <th>Exam Date</th>
                                    <th>Time</th>
                                    <th>Slot</th>
                                    <th>Attempts</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($allStudents)): ?>
                                    <tr>
                                        <td colspan="12">
                                            <div class="es-empty">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                    stroke-width="1.5">
                                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                                                    <circle cx="9" cy="7" r="4" />
                                                </svg>
                                                <p>No students
                                                    found<?= $courseFilter !== 'all' ? ' for this course' : '' ?>.</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($allStudents as $i => $stu):
                                        $fullName = trim($stu['first_name'] . ' ' . ($stu['middle_name'] ? $stu['middle_name'] . ' ' : '') . $stu['last_name']);
                                        $hasPhoto = !empty($stu['photo']);
                                        $initial = strtoupper(substr($stu['first_name'], 0, 1));
                                        $isScheduled = !empty($stu['exam_date']);
                                        $status = $stu['exam_status'] ?? '';

                                        if (!$status && !$isScheduled)
                                            $statusClass = 'unscheduled';
                                        elseif ($status === 'Passed')
                                            $statusClass = 'passed';
                                        elseif ($status === 'Failed')
                                            $statusClass = 'failed';
                                        elseif ($status === 'Absent')
                                            $statusClass = 'absent';
                                        else
                                            $statusClass = 'scheduled';

                                        $statusText = $isScheduled ? ($status ?: 'Scheduled') : 'Unscheduled';
                                        ?>
                                        <tr data-id="<?= $stu['id'] ?>"
                                            data-course="<?= htmlspecialchars(strtolower($stu['course'] ?? '')) ?>"
                                            data-search="<?= htmlspecialchars(strtolower($fullName . ' ' . $stu['roll_no'] . ' ' . $stu['registration_id'])) ?>">
                                            <td><input type="checkbox" class="es-cb stu-cb" value="<?= $stu['id'] ?>"
                                                    onchange="updateBulkBar()"></td>
                                            <td style="color:var(--text-secondary);font-size:.78rem"><?= $i + 1 ?></td>
                                            <td>
                                                <div class="es-stu-cell">
                                                    <?php if ($hasPhoto): ?>
                                                        <img src="../<?= htmlspecialchars($stu['photo']) ?>" class="es-stu-photo">
                                                    <?php else: ?>
                                                        <div class="es-stu-initials"><?= $initial ?></div>
                                                    <?php endif; ?>
                                                    <div>
                                                        <div class="es-stu-name"><?= htmlspecialchars($fullName) ?></div>
                                                        <div class="es-stu-roll"><?= htmlspecialchars($stu['roll_no'] ?? '—') ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td><code
                                                    style="font-size:.75rem;background:#f1f5f9;padding:.15rem .4rem;border-radius:4px"><?= htmlspecialchars($stu['registration_id'] ?? '—') ?></code>
                                            </td>
                                            <td style="font-size:.8rem;max-width:150px">
                                                <?= htmlspecialchars($stu['course'] ?? '—') ?>
                                            </td>
                                            <td style="font-size:.78rem;max-width:160px">
                                                <?php if (!empty($stu['exam_name'])): ?>
                                                    <span style="display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .6rem;border-radius:6px;font-size:.72rem;font-weight:700;background:#eef2ff;color:#4338ca;border:1px solid #c7d2fe">
                                                        📝 <?= htmlspecialchars($stu['exam_name']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span style="color:#94a3b8">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-size:.82rem;font-weight:600">
                                                <?= $isScheduled ? date('d M Y', strtotime($stu['exam_date'])) : '<span style="color:#94a3b8">—</span>' ?>
                                            </td>
                                            <td style="font-size:.82rem">
                                                <?= $isScheduled ? date('h:i A', strtotime($stu['exam_time'])) : '<span style="color:#94a3b8">—</span>' ?>
                                            </td>
                                            <td><?php
                                            if ($isScheduled):
                                                $slotVal = !empty($stu['exam_slot']) ? $stu['exam_slot'] : 'Morning';
                                                $slotColors = ['Morning' => '#0ea5e9', 'Afternoon' => '#f59e0b', 'Evening' => '#7c3aed'];
                                                $slotBg = ['Morning' => '#f0f9ff', 'Afternoon' => '#fffbeb', 'Evening' => '#f5f3ff'];
                                                $slotBorder = ['Morning' => '#bae6fd', 'Afternoon' => '#fde68a', 'Evening' => '#c4b5fd'];
                                                $sc = $slotColors[$slotVal] ?? '#4361ee';
                                                $sb = $slotBg[$slotVal] ?? '#eef1fd';
                                                $sbr = $slotBorder[$slotVal] ?? '#c7d2fe';
                                                ?><span
                                                        style="display:inline-flex;align-items:center;gap:.25rem;padding:.25rem .7rem;border-radius:999px;font-size:.72rem;font-weight:800;background:<?= $sb ?>;color:<?= $sc ?>;border:1px solid <?= $sbr ?>"><?= htmlspecialchars($slotVal) ?></span><?php else: ?><span
                                                        style="color:#94a3b8">—</span><?php endif; ?></td>
                                            <td style="text-align:center">
                                                <?php if ($isScheduled): ?>
                                                <div style="display:inline-flex;align-items:center;gap:.3rem">
                                                    <input type="number" min="1" max="10" value="<?= intval($stu['allowed_attempts']) ?>" style="width:52px;padding:.25rem .4rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.8rem;font-weight:700;text-align:center;font-family:inherit;outline:none;transition:border-color .2s" onfocus="this.style.borderColor='#4f46e5'" onblur="this.style.borderColor='#e2e8f0'" onchange="updateAttempts(<?= $stu['id'] ?>, this.value, this)" title="Allowed Attempts">
                                                    <span style="font-size:.68rem;color:#94a3b8">att.</span>
                                                </div>
                                                <?php else: ?><span style="color:#94a3b8">—</span><?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="es-badge es-badge-<?= $statusClass ?>">
                                                    <span class="es-badge-dot"></span><?= $statusText ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div style="display:flex;gap:.3rem">
                                                    <button class="es-btn"
                                                        onclick="openScheduleModal(<?= $stu['id'] ?>, '<?= htmlspecialchars(addslashes($fullName)) ?>', '<?= htmlspecialchars(addslashes($stu['exam_date'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($stu['exam_time'] ?? '10:00')) ?>', '<?= htmlspecialchars(addslashes($stu['exam_slot'] ?? 'Morning')) ?>', '<?= htmlspecialchars(addslashes($stu['exam_hall'] ?? '')) ?>', <?= intval($stu['exam_portal_id'] ?? 0) ?>, <?= intval($stu['allowed_attempts'] ?? 1) ?>)"
                                                        title="<?= $isScheduled ? 'Edit' : 'Schedule' ?>">
                                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                            stroke-width="2">
                                                            <rect x="3" y="4" width="18" height="18" rx="2" />
                                                            <line x1="16" y1="2" x2="16" y2="6" />
                                                            <line x1="8" y1="2" x2="8" y2="6" />
                                                            <line x1="3" y1="10" x2="21" y2="10" />
                                                        </svg>
                                                        <?= $isScheduled ? 'Edit' : 'Schedule' ?>
                                                    </button>
                                                    <?php if ($isScheduled): ?>
                                                        <button class="es-btn es-btn-danger"
                                                            onclick="removeSchedule(<?= $stu['id'] ?>)" title="Remove">
                                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                                stroke-width="2">
                                                                <line x1="18" y1="6" x2="6" y2="18" />
                                                                <line x1="6" y1="6" x2="18" y2="18" />
                                                            </svg>
                                                        </button>
                                                        <?php if (!empty($stu['mobile'])): ?>
                                                        <button class="es-btn" style="background:#dcfce7;border-color:#86efac;color:#16a34a"
                                                            onclick="sendExamNotify('<?= htmlspecialchars(addslashes($fullName)) ?>', '<?= htmlspecialchars($stu['mobile']) ?>', '<?= htmlspecialchars(addslashes($stu['exam_date'])) ?>', '<?= htmlspecialchars(addslashes($stu['exam_time'] ?? '10:00')) ?>', '<?= htmlspecialchars(addslashes(!empty($stu['exam_slot']) ? $stu['exam_slot'] : 'Morning')) ?>', '<?= htmlspecialchars(addslashes($stu['exam_hall'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($stu['exam_name'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($stu['registration_id'] ?? '')) ?>')"
                                                            title="Send WhatsApp Exam Notification">
                                                            <svg viewBox="0 0 24 24" fill="currentColor" style="width:13px;height:13px">
                                                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                                                            </svg>
                                                        </button>
                                                        <?php endif; ?>
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

            </div>
        </main>
    </div>

    <!-- Schedule Single Modal -->
    <div class="es-overlay" id="scheduleOverlay">
        <div class="es-modal">
            <div class="es-modal-head">
                <div class="es-modal-head-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" />
                        <line x1="16" y1="2" x2="16" y2="6" />
                        <line x1="8" y1="2" x2="8" y2="6" />
                        <line x1="3" y1="10" x2="21" y2="10" />
                    </svg>
                </div>
                <div>
                    <div class="es-modal-title">Schedule Exam</div>
                    <div class="es-modal-sub" id="schedModalSub">—</div>
                </div>
                <button class="es-modal-close" onclick="closeScheduleModal()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>
            <div class="es-modal-body">
                <input type="hidden" id="schedAdmId">
                <?php if (!empty($portalExams)): ?>
                <div class="es-form-row full">
                    <div>
                        <label class="es-form-label">Select Exam <span style="color:#ef4444">*</span></label>
                        <select class="es-form-select" id="schedExam">
                            <option value="" data-id="0">— Select an exam —</option>
                            <?php foreach ($portalExams as $pe): ?>
                                <option value="<?= htmlspecialchars($pe['title'] ?? $pe['exam_id'] ?? '') ?>" data-id="<?= intval($pe['id'] ?? 0) ?>">
                                    <?= htmlspecialchars(($pe['title'] ?? 'Untitled') . ' — ' . ($pe['subject'] ?? '') . ' (' . ($pe['duration'] ?? '?') . ' min)') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php endif; ?>
                <div class="es-form-row">
                    <div>
                        <label class="es-form-label">Exam Date <span style="color:#ef4444">*</span></label>
                        <input type="date" class="es-form-input" id="schedDate">
                    </div>
                    <div>
                        <label class="es-form-label">Exam Time</label>
                        <input type="time" class="es-form-input" id="schedTime" value="10:00">
                    </div>
                </div>
                <div class="es-form-row">
                    <div>
                        <label class="es-form-label">Slot</label>
                        <select class="es-form-select" id="schedSlot">
                            <option value="Morning">🌅 Morning</option>
                            <option value="Afternoon">☀️ Afternoon</option>
                            <option value="Evening">🌙 Evening</option>
                        </select>
                    </div>
                    <div>
                        <label class="es-form-label">Exam Hall</label>
                        <input type="text" class="es-form-input" id="schedHall" placeholder="e.g. Hall A">
                    </div>
                </div>
                <div class="es-form-row">
                    <div>
                        <label class="es-form-label">Allowed Attempts <span style="font-size:.72rem;color:#64748b;font-weight:500">(max tries student can take this exam)</span></label>
                        <input type="number" class="es-form-input" id="schedAttempts" min="1" max="10" value="1" style="width:100px">
                    </div>
                    <div style="display:flex;align-items:flex-end;padding-bottom:.15rem">
                        <p style="font-size:.78rem;color:#64748b;line-height:1.5">Set to <strong>1</strong> for standard single-attempt exams. Increase for re-attempt scenarios.</p>
                    </div>
                </div>
            </div>
            <div class="es-modal-footer">
                <button class="es-btn" onclick="closeScheduleModal()">Cancel</button>
                <button class="es-btn es-btn-primary" id="schedSaveBtn" onclick="saveSingleSchedule()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        style="width:13px;height:13px">
                        <polyline points="20 6 9 17 4 12" />
                    </svg>
                    Save Schedule
                </button>
            </div>
        </div>
    </div>

    <!-- Bulk Schedule Modal -->
    <div class="es-overlay" id="bulkOverlay">
        <div class="es-modal">
            <div class="es-modal-head">
                <div class="es-modal-head-icon" style="background:linear-gradient(135deg,var(--es-violet),#6d28d9)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                        <circle cx="9" cy="7" r="4" />
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                        <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                    </svg>
                </div>
                <div>
                    <div class="es-modal-title">Bulk Schedule Exam</div>
                    <div class="es-modal-sub" id="bulkModalCount">Select students from the table first</div>
                </div>
                <button class="es-modal-close" onclick="closeBulkModal()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>
            <div class="es-modal-body">
                <div
                    style="background:var(--es-amber-lt);border:1px solid #fde68a;border-radius:8px;padding:.65rem 1rem;font-size:.8rem;color:#92400e;font-weight:600;margin-bottom:1.25rem">
                    ⚡ This will set the same exam, date, time, slot & hall for all selected students.
                </div>
                <?php if (!empty($portalExams)): ?>
                <div class="es-form-row full">
                    <div>
                        <label class="es-form-label">Select Exam <span style="color:#ef4444">*</span></label>
                        <select class="es-form-select" id="bulkExam">
                            <option value="" data-id="0">— Select an exam —</option>
                            <?php foreach ($portalExams as $pe): ?>
                                <option value="<?= htmlspecialchars($pe['title'] ?? $pe['exam_id'] ?? '') ?>" data-id="<?= intval($pe['id'] ?? 0) ?>">
                                    <?= htmlspecialchars(($pe['title'] ?? 'Untitled') . ' — ' . ($pe['subject'] ?? '') . ' (' . ($pe['duration'] ?? '?') . ' min)') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php endif; ?>
                <div class="es-form-row">
                    <div>
                        <label class="es-form-label">Exam Date <span style="color:#ef4444">*</span></label>
                        <input type="date" class="es-form-input" id="bulkDate">
                    </div>
                    <div>
                        <label class="es-form-label">Exam Time</label>
                        <input type="time" class="es-form-input" id="bulkTime" value="10:00">
                    </div>
                </div>
                <div class="es-form-row">
                    <div>
                        <label class="es-form-label">Slot</label>
                        <select class="es-form-select" id="bulkSlot">
                            <option value="Morning">🌅 Morning</option>
                            <option value="Afternoon">☀️ Afternoon</option>
                            <option value="Evening">🌙 Evening</option>
                        </select>
                    </div>
                    <div>
                        <label class="es-form-label">Exam Hall</label>
                        <input type="text" class="es-form-input" id="bulkHall" placeholder="e.g. Hall A">
                    </div>
                </div>
            </div>
            <div class="es-modal-footer">
                <button class="es-btn" onclick="closeBulkModal()">Cancel</button>
                <button class="es-btn es-btn-primary" onclick="executeBulkSchedule()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        style="width:13px;height:13px">
                        <polyline points="20 6 9 17 4 12" />
                    </svg>
                    Schedule Selected
                </button>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div id="esToastWrap"></div>

    <script src="../assets/js/dashboard.js"></script>
    <script>
        // ── Search ──────────────────────────────────────────────────────────────────
        document.getElementById('esSearch').addEventListener('input', function () {
            const q = this.value.toLowerCase();
            document.querySelectorAll('#esTable tbody tr[data-id]').forEach(r => {
                r.style.display = (r.dataset.search || '').includes(q) ? '' : 'none';
            });
        });

        // ── Select All / Bulk Bar ───────────────────────────────────────────────────
        function toggleSelectAll(el) {
            const cbs = document.querySelectorAll('.stu-cb');
            cbs.forEach(cb => {
                if (cb.closest('tr').style.display !== 'none') cb.checked = el.checked;
            });
            updateBulkBar();
        }

        function getSelectedIds() {
            return [...document.querySelectorAll('.stu-cb:checked')].map(cb => cb.value);
        }

        function updateBulkBar() {
            const ids = getSelectedIds();
            const bar = document.getElementById('esBulkBar');
            const count = document.getElementById('esBulkCount');
            if (ids.length > 0) {
                bar.classList.add('visible');
                count.textContent = ids.length + ' selected';
            } else {
                bar.classList.remove('visible');
            }
        }

        // Select all visible students (useful after course filter)
        function selectAllVisible() {
            const cbs = document.querySelectorAll('.stu-cb');
            let anyUnchecked = false;
            cbs.forEach(cb => {
                if (cb.closest('tr').style.display !== 'none' && !cb.checked) anyUnchecked = true;
            });
            cbs.forEach(cb => {
                if (cb.closest('tr').style.display !== 'none') cb.checked = anyUnchecked;
            });
            document.getElementById('selectAll').checked = anyUnchecked;
            updateBulkBar();
        }

        // ── Single Schedule Modal ───────────────────────────────────────────────────
        function openScheduleModal(admId, name, date, time, slot, hall, examPortalId, attempts) {
            document.getElementById('schedAdmId').value = admId;
            document.getElementById('schedModalSub').textContent = name;
            document.getElementById('schedDate').value = date || '';
            document.getElementById('schedTime').value = time || '10:00';
            document.getElementById('schedSlot').value = slot || 'Morning';
            const hallEl = document.getElementById('schedHall');
            if (hallEl) hallEl.value = hall || '';
            const attEl = document.getElementById('schedAttempts');
            if (attEl) attEl.value = attempts || 1;
            // Pre-select exam dropdown if it exists
            const examSel = document.getElementById('schedExam');
            if (examSel && examPortalId) {
                for (let i = 0; i < examSel.options.length; i++) {
                    if (parseInt(examSel.options[i].dataset.id) === parseInt(examPortalId)) {
                        examSel.selectedIndex = i;
                        break;
                    }
                }
            } else if (examSel) {
                examSel.selectedIndex = 0;
            }
            document.getElementById('scheduleOverlay').classList.add('open');
        }

        function closeScheduleModal() {
            document.getElementById('scheduleOverlay').classList.remove('open');
        }

        document.getElementById('scheduleOverlay').addEventListener('click', function (e) {
            if (e.target === this) closeScheduleModal();
        });

        async function saveSingleSchedule() {
            const admId = document.getElementById('schedAdmId').value;
            const date = document.getElementById('schedDate').value;
            const time = document.getElementById('schedTime').value;
            const slot = document.getElementById('schedSlot').value;
            const hall = document.getElementById('schedHall')?.value || '';
            if (!date) { esToast('Please select an exam date.', 'error'); return; }

            // Get exam portal data from dropdown
            const examSel = document.getElementById('schedExam');
            let examName = '';
            let examPortalId = 0;
            if (examSel) {
                const opt = examSel.options[examSel.selectedIndex];
                examName = examSel.value || '';
                examPortalId = parseInt(opt?.dataset?.id || 0);
            }

            const btn = document.getElementById('schedSaveBtn');
            btn.disabled = true; btn.textContent = 'Saving…';

            const fd = new FormData();
            fd.append('action', 'schedule_single');
            fd.append('admission_id', admId);
            fd.append('exam_date', date);
            fd.append('exam_time', time);
            fd.append('exam_slot', slot);
            fd.append('exam_hall', hall);
            fd.append('exam_name', examName);
            fd.append('exam_portal_id', examPortalId);
            fd.append('allowed_attempts', document.getElementById('schedAttempts')?.value || 1);

            try {
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    esToast('✅ ' + data.message);
                    closeScheduleModal();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    esToast(data.message, 'error');
                }
            } catch (e) { esToast('Network error.', 'error'); }
            finally {
                btn.disabled = false;
                btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><polyline points="20 6 9 17 4 12"/></svg>Save Schedule';
            }
        }

        // ── Bulk Schedule Modal ─────────────────────────────────────────────────────
        function openBulkModal() {
            const ids = getSelectedIds();
            if (ids.length === 0) {
                esToast('Select students first using checkboxes.', 'error');
                return;
            }
            document.getElementById('bulkModalCount').textContent = ids.length + ' student(s) selected';
            document.getElementById('bulkOverlay').classList.add('open');
        }

        function closeBulkModal() {
            document.getElementById('bulkOverlay').classList.remove('open');
        }

        document.getElementById('bulkOverlay').addEventListener('click', function (e) {
            if (e.target === this) closeBulkModal();
        });

        async function executeBulkSchedule() {
            const ids = getSelectedIds();
            const date = document.getElementById('bulkDate').value;
            const time = document.getElementById('bulkTime').value;
            const slot = document.getElementById('bulkSlot').value;
            const hall = document.getElementById('bulkHall').value;
            if (!date) { esToast('Please select a date.', 'error'); return; }

            // Get exam portal data from dropdown
            const examSel = document.getElementById('bulkExam');
            let examName = '';
            let examPortalId = 0;
            if (examSel) {
                const opt = examSel.options[examSel.selectedIndex];
                examName = examSel.value || '';
                examPortalId = parseInt(opt?.dataset?.id || 0);
            }

            const fd = new FormData();
            fd.append('action', 'bulk_schedule');
            fd.append('student_ids', JSON.stringify(ids));
            fd.append('exam_date', date);
            fd.append('exam_time', time);
            fd.append('exam_slot', slot);
            fd.append('exam_hall', hall);
            fd.append('exam_name', examName);
            fd.append('exam_portal_id', examPortalId);

            try {
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                if (data.success) {
                    esToast('✅ ' + data.message);
                    closeBulkModal();
                    setTimeout(() => location.reload(), 1000);
                } else {
                    esToast(data.message, 'error');
                }
            } catch (e) { esToast('Network error.', 'error'); }
        }

        // ── Bulk Slot Assign ────────────────────────────────────────────────────────
        async function bulkAssignSlot() {
            const ids = getSelectedIds();
            const slot = document.getElementById('bulkSlotSelect').value;
            if (ids.length === 0) { esToast('Select students first.', 'error'); return; }

            const fd = new FormData();
            fd.append('action', 'bulk_slot');
            fd.append('student_ids', JSON.stringify(ids));
            fd.append('exam_slot', slot);

            try {
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                esToast(data.success ? '✅ ' + data.message : data.message, data.success ? 'success' : 'error');
                if (data.success) setTimeout(() => location.reload(), 1000);
            } catch (e) { esToast('Network error.', 'error'); }
        }

        // ── Bulk Timing Assign ──────────────────────────────────────────────────────
        async function bulkAssignTiming() {
            const ids = getSelectedIds();
            const time = document.getElementById('bulkTimeInput').value;
            if (ids.length === 0) { esToast('Select students first.', 'error'); return; }
            if (!time) { esToast('Set a time first.', 'error'); return; }

            const fd = new FormData();
            fd.append('action', 'bulk_timing');
            fd.append('student_ids', JSON.stringify(ids));
            fd.append('exam_time', time);

            try {
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                esToast(data.success ? '✅ ' + data.message : data.message, data.success ? 'success' : 'error');
                if (data.success) setTimeout(() => location.reload(), 1000);
            } catch (e) { esToast('Network error.', 'error'); }
        }

        // ── Remove Schedule ─────────────────────────────────────────────────────────
        async function removeSchedule(admId) {
            if (!confirm('Remove exam schedule for this student?')) return;
            const fd = new FormData();
            fd.append('action', 'remove_schedule');
            fd.append('admission_id', admId);
            try {
                const res = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();
                esToast(data.success ? '✅ Removed.' : data.message, data.success ? 'success' : 'error');
                if (data.success) setTimeout(() => location.reload(), 800);
            } catch (e) { esToast('Network error.', 'error'); }
        }

        // ── Toast ───────────────────────────────────────────────────────────────────
        function esToast(msg, type = 'success') {
            const t = document.createElement('div');
            const bg = type === 'success' ? 'linear-gradient(135deg,#059669,#047857)' : 'linear-gradient(135deg,#dc2626,#b91c1c)';
            t.style.cssText = `background:${bg};color:#fff;padding:.85rem 1.35rem;border-radius:12px;font-size:.84rem;font-weight:600;box-shadow:0 8px 24px rgba(0,0,0,.18);animation:esSlideUp .3s cubic-bezier(.4,0,.2,1);max-width:400px;font-family:'Inter',sans-serif;letter-spacing:-.01em`;
            t.textContent = msg;
            document.getElementById('esToastWrap').appendChild(t);
            setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; }, 3000);
            setTimeout(() => t.remove(), 3400);
        }

        // Escape key
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') { closeScheduleModal(); closeBulkModal(); }
        });

        // -- WhatsApp Exam Notification --
        function sendExamNotify(name, mobile, date, time, slot, hall, examName, regId) {
            const atcName = <?= json_encode($atcName) ?>;
            const dateStr = date ? new Date(date + 'T00:00:00').toLocaleDateString('en-IN', { day: '2-digit', month: 'long', year: 'numeric' }) : 'To be confirmed';
            const timeStr = time ? new Date('2000-01-01T' + time).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true }) : 'To be confirmed';

            let msg = 'Dear ' + name + ',\n\n';
            msg += '*EXAM SCHEDULE NOTIFICATION*\n';
            msg += '--------------------------------\n\n';
            msg += 'Your exam has been scheduled. Please find the details below:\n\n';
            if (examName) msg += '*Exam:* ' + examName + '\n';
            msg += '*Date:* ' + dateStr + '\n';
            msg += '*Time:* ' + timeStr + '\n';
            msg += '*Slot:* ' + slot + '\n';
            if (hall) msg += '*Hall:* ' + hall + '\n';
            msg += '*Centre:* ' + atcName + '\n\n';
            msg += '--------------------------------\n';
            msg += '*Exam Portal Login Details*\n';
            if (regId) msg += '   User ID: *' + regId + '*\n';
            msg += '   Password: *password*\n\n';
            msg += '*Important:*\n';
            msg += '- Please arrive 15 minutes before the scheduled time.\n';
            msg += '- Carry a valid ID proof.\n';
            msg += '- Contact us if you have any queries.\n\n';
            msg += 'Best of luck!\n';
            msg += '_Team ' + atcName + '_';

            const num = mobile.replace(/\D/g, '');
            const prefix = num.length === 10 ? '91' : '';
            window.open('https://wa.me/' + prefix + num + '?text=' + encodeURIComponent(msg), '_blank');
        }

        // ── Inline attempts editor ──────────────────────────────────────────────────
        let _attDebounce = null;
        function updateAttempts(admId, value, inputEl) {
            const attempts = Math.max(1, Math.min(10, parseInt(value) || 1));
            inputEl.value = attempts; // clamp displayed value
            clearTimeout(_attDebounce);
            _attDebounce = setTimeout(async () => {
                inputEl.style.borderColor = '#a5b4fc';
                const fd = new FormData();
                fd.append('action', 'update_attempts');
                fd.append('admission_id', admId);
                fd.append('allowed_attempts', attempts);
                try {
                    const res = await fetch('', { method: 'POST', body: fd });
                    const data = await res.json();
                    if (data.success) {
                        inputEl.style.borderColor = '#4ade80';
                        esToast('Attempts updated to ' + attempts + '.');
                        setTimeout(() => inputEl.style.borderColor = '#e2e8f0', 1500);
                    } else {
                        inputEl.style.borderColor = '#f87171';
                        esToast(data.message, 'error');
                        setTimeout(() => inputEl.style.borderColor = '#e2e8f0', 1500);
                    }
                } catch (e) {
                    esToast('Network error.', 'error');
                    inputEl.style.borderColor = '#e2e8f0';
                }
            }, 600);
        }
        
    </script>
</body>

</html>