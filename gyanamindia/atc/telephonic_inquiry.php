<?php
/**
 * Gyanam Portal — ATC: Telephonic Inquiries Management
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$atcId = $_SESSION['atc_id'] ?? null;
$pageMode = $_GET['mode'] ?? '';

// Auto-migration: ensure ho_share_snapshot column exists
try { $pdo->exec("ALTER TABLE admissions ADD COLUMN IF NOT EXISTS ho_share_snapshot DECIMAL(10,2) DEFAULT NULL"); } catch (Exception $e) {}
ensureDualMaterialCourseSchema($pdo);

// getHoShareForCourse() is provided by includes/functions.php

// Full-page Add Inquiry submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'add_page')) {
    try {
        if (empty(trim($_POST['first_name'] ?? '')) || empty(trim($_POST['last_name'] ?? ''))) {
            header('Location: telephonic_inquiry.php?mode=add&error=name');
            exit;
        }
        if (!preg_match('/^[0-9]{10}$/', trim($_POST['mobile'] ?? ''))) {
            header('Location: telephonic_inquiry.php?mode=add&error=mobile');
            exit;
        }
        if (empty(trim($_POST['next_inform_date'] ?? '')) || empty(trim($_POST['next_inform_time'] ?? ''))) {
            header('Location: telephonic_inquiry.php?mode=add&error=followup');
            exit;
        }

        $stmt = $pdo->prepare("
            INSERT INTO telephonic_inquiries (
                atc_id, first_name, middle_name, last_name, interested_course,
                mobile, next_inform_date, next_inform_time, comment, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $atcId,
            $_POST['first_name'],
            $_POST['middle_name'] ?? '',
            $_POST['last_name'],
            $_POST['interested_course'],
            $_POST['mobile'],
            $_POST['next_inform_date'],
            $_POST['next_inform_time'],
            $_POST['comment'] ?? '',
            $_POST['status'] ?? 'New'
        ]);

        header('Location: telephonic_inquiry.php?saved=1');
        exit;
    } catch (Exception $e) {
        header('Location: telephonic_inquiry.php?mode=add&error=save');
        exit;
    }
}

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'add':
                $stmt = $pdo->prepare("
                    INSERT INTO telephonic_inquiries (
                        atc_id, first_name, middle_name, last_name, interested_course, 
                        mobile, next_inform_date, next_inform_time, comment, status
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $atcId,
                    $_POST['first_name'],
                    $_POST['middle_name'],
                    $_POST['last_name'],
                    $_POST['interested_course'],
                    $_POST['mobile'],
                    $_POST['next_inform_date'],
                    $_POST['next_inform_time'],
                    $_POST['comment'],
                    $_POST['status']
                ]);
                echo json_encode(['success' => true, 'message' => 'Telephonic inquiry added successfully']);
                exit;
                
            case 'edit':
                $stmt = $pdo->prepare("
                    UPDATE telephonic_inquiries 
                    SET first_name = ?, middle_name = ?, last_name = ?, interested_course = ?,
                        mobile = ?, next_inform_date = ?, next_inform_time = ?, comment = ?, status = ?
                    WHERE id = ? AND atc_id = ?
                ");
                $stmt->execute([
                    $_POST['first_name'],
                    $_POST['middle_name'],
                    $_POST['last_name'],
                    $_POST['interested_course'],
                    $_POST['mobile'],
                    $_POST['next_inform_date'],
                    $_POST['next_inform_time'],
                    $_POST['comment'],
                    $_POST['status'],
                    $_POST['id'],
                    $atcId
                ]);
                echo json_encode(['success' => true, 'message' => 'Telephonic inquiry updated successfully']);
                exit;
                
            case 'delete':
                $stmt = $pdo->prepare("DELETE FROM telephonic_inquiries WHERE id = ? AND atc_id = ?");
                $stmt->execute([$_POST['id'], $atcId]);
                echo json_encode(['success' => true, 'message' => 'Telephonic inquiry deleted successfully']);
                exit;
                
            case 'get':
                $stmt = $pdo->prepare("SELECT * FROM telephonic_inquiries WHERE id = ? AND atc_id = ?");
                $stmt->execute([$_POST['id'], $atcId]);
                $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $inquiry]);
                exit;

            case 'convert_to_admission':
                $stmt = $pdo->prepare("SELECT * FROM telephonic_inquiries WHERE id = ? AND atc_id = ?");
                $stmt->execute([$_POST['id'], $atcId]);
                $inq = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$inq) {
                    echo json_encode(['success' => false, 'message' => 'Inquiry not found']);
                    exit;
                }

                // Generate identifiers same as main admission flow
                $atcStmt = $pdo->prepare("SELECT center_type FROM atc_centers WHERE id = ?");
                $atcStmt->execute([$atcId]);
                $centerType = $atcStmt->fetchColumn() ?: 'Other';

                $rollNo = generateNextRollNoSimple($pdo, (int)$atcId);
                $registrationId = generateRegistrationId($pdo, (string)$centerType);

                $courseFees  = floatval($_POST['course_fees']    ?? 0);
                $discount    = floatval($_POST['discount_amount'] ?? 0);
                $netPayable  = $courseFees - $discount;
                $feesPaid    = floatval($_POST['fees_paid']       ?? 0);
                $feesPending = $netPayable - $feesPaid;

                $matTypeTel = $_POST['material_type'] ?? 'Without Material';
                $hoShareSnapshotTel  = getHoShareForCourse($pdo, $inq['interested_course'], $matTypeTel);
                $dlcShareSnapshotTel = getDlcShareForCourse($pdo, $inq['interested_course'], $matTypeTel);

                $stmt = $pdo->prepare("
                    INSERT INTO admissions (
                            atc_id, roll_no, registration_id, first_name, middle_name, last_name,
                        mobile, course, admission_date, status,
                            uniform_size, referenced_by,
                        course_fees, discount_amount, net_payable,
                        fees_total, fees_paid, fees_pending, material_type,
                        ho_share_snapshot, dlc_share_snapshot
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), 'Active', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $atcId,
                    $rollNo,
                    $registrationId,
                    $inq['first_name'],
                    $inq['middle_name'],
                    $inq['last_name'],
                    $inq['mobile'],
                    $inq['interested_course'],
                    !empty($_POST['uniform_size']) ? trim($_POST['uniform_size']) : null,
                        'Telephonic Inquiry',
                    $courseFees,
                    $discount,
                    $netPayable,
                    $netPayable,
                    $feesPaid,
                    $feesPending,
                    $matTypeTel,
                    $hoShareSnapshotTel,
                    $dlcShareSnapshotTel,
                ]);

                // Mark telephonic inquiry as Converted
                $pdo->prepare("UPDATE telephonic_inquiries SET status = 'Converted' WHERE id = ?")
                    ->execute([$_POST['id']]);

                // Update student count
                $pdo->prepare("UPDATE atc_centers SET student_count = student_count + 1 WHERE id = ?")
                    ->execute([$atcId]);

                echo json_encode([
                    'success'  => true,
                    'message'  => 'Converted to admission successfully! Roll No: ' . $rollNo . '. Please update full details in the Students section.',
                    'roll_no'  => $rollNo
                ]);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// Fetch telephonic inquiries for this ATC (paginated; exclude converted by default)
$statusFilter = $_GET['status'] ?? 'all';
$pagerParams  = paginationParams(25);

$telWhere = [];
$telParams = [];
if ($atcId) {
    $telWhere[] = 'atc_id = ?';
    $telParams[] = $atcId;
}
if ($statusFilter !== 'all') {
    $telWhere[] = 'status = ?';
    $telParams[] = $statusFilter;
} else {
    $telWhere[] = "status != 'Converted'";
}
$telWhereSql = $telWhere ? (' WHERE ' . implode(' AND ', $telWhere)) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM telephonic_inquiries' . $telWhereSql);
$countStmt->execute($telParams);
$pager = paginationMeta((int)$countStmt->fetchColumn(), $pagerParams);

$stmt = $pdo->prepare('SELECT * FROM telephonic_inquiries' . $telWhereSql . ' ORDER BY created_at DESC'
    . " LIMIT {$pager['per_page']} OFFSET {$pager['offset']}");
$stmt->execute($telParams);
$inquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch active master courses with this ATC's fee (either material fee > 0)
$courseStmt = $pdo->prepare("
    SELECT c.course_name, acf.final_fee AS fees,
           acf.fee_with_material, acf.fee_without_material
    FROM   courses c
    INNER JOIN atc_course_fees acf ON acf.course_id = c.id AND acf.atc_id = ?
    WHERE  c.status = 'Active'
      AND  (COALESCE(acf.fee_with_material, 0) > 0
         OR COALESCE(acf.fee_without_material, 0) > 0
         OR COALESCE(acf.final_fee, 0) > 0)
    ORDER BY c.course_name ASC
");
$courseStmt->execute([$atcId]);
$activeCourses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);


// Get status counts (exclude converted from 'all' count)
$statusCounts = [
    'all' => 0,
    'New' => 0,
    'Contacted' => 0,
    'Converted' => 0,
    'Closed' => 0
];

if ($atcId) {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM telephonic_inquiries WHERE atc_id = ? GROUP BY status");
    $stmt->execute([$atcId]);
} else {
    $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM telephonic_inquiries GROUP BY status");
}

$counts = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($counts as $count) {
    $statusCounts[$count['status']] = $count['count'];
    // Only add non-converted inquiries to 'all' count
    if ($count['status'] !== 'Converted') {
        $statusCounts['all'] += $count['count'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Telephonic Inquiries — ATC Center | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📚</text></svg>">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <style>
    /* ── Export bar ── */
    .export-btn-group { display:flex; gap:.35rem; flex-wrap:wrap; align-items:center; }
    .exp-btn {
        display:inline-flex; align-items:center; gap:.28rem;
        padding:.38rem .75rem; border-radius:8px; border:1.5px solid;
        font-size:.7rem; font-weight:700; cursor:pointer;
        white-space:nowrap; transition:all .18s; font-family:inherit;
    }
    .exp-copy  { background:#f1f5f9; border-color:#cbd5e1; color:#475569; }
    .exp-csv   { background:#ecfdf5; border-color:#6ee7b7; color:#065f46; }
    .exp-excel { background:#eff6ff; border-color:#93c5fd; color:#1d4ed8; }
    .exp-pdf   { background:#fef2f2; border-color:#fca5a5; color:#b91c1c; }
    .exp-print { background:#f5f3ff; border-color:#c4b5fd; color:#6d28d9; }
    .exp-btn:hover { transform:translateY(-1px); box-shadow:0 2px 8px rgba(0,0,0,.1); }

    /* ── Stat strip ── */
    .tel-stat-strip {
        display:grid; grid-template-columns:repeat(4,1fr); gap:1rem;
        margin-bottom:1.5rem;
    }
    @media(max-width:768px){ .tel-stat-strip{ grid-template-columns:repeat(2,1fr); } }
    .tel-stat {
        background:#fff; border:1.5px solid #e6eaf3; border-radius:16px;
        padding:1rem 1.25rem; display:flex; align-items:center; gap:.9rem;
        box-shadow:0 1px 4px rgba(0,0,0,.05); transition:transform .2s,box-shadow .2s;
    }
    .tel-stat:hover { transform:translateY(-2px); box-shadow:0 6px 18px rgba(0,0,0,.08); }
    .tel-stat-icon {
        width:42px; height:42px; border-radius:12px; flex-shrink:0;
        display:flex; align-items:center; justify-content:center;
    }
    .tel-stat-icon svg { width:20px; height:20px; }
    .tel-stat-label { font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#6b7280; margin-bottom:.2rem; }
    .tel-stat-value { font-size:1.5rem; font-weight:800; color:#111827; line-height:1; }

    /* ── Status tabs ── */
    .status-tabs {
        display:flex; gap:.5rem; margin-bottom:1.25rem;
        overflow-x:auto; padding-bottom:.25rem;
    }
    .status-tab {
        display:flex; align-items:center; gap:.5rem;
        padding:.6rem 1.1rem; border-radius:10px;
        background:#fff; border:1.5px solid #e6eaf3;
        text-decoration:none; color:#374151;
        font-weight:600; font-size:.82rem;
        transition:all .2s; white-space:nowrap;
    }
    .status-tab:hover { border-color:#a5b4fc; background:#eef2ff; }
    .status-tab.active {
        background:linear-gradient(135deg,#4361ee,#6366f1);
        border-color:#4361ee; color:#fff;
        box-shadow:0 4px 12px rgba(67,97,238,.25);
    }
    .tab-count {
        padding:.15rem .55rem; border-radius:999px;
        font-size:.72rem; font-weight:800;
        background:rgba(0,0,0,.07); color:inherit;
    }
    .status-tab.active .tab-count { background:rgba(255,255,255,.25); }

    /* ── Table polish ── */
    .tel-table-wrap { background:#fff; border:1.5px solid #e6eaf3; border-radius:18px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.06); }
    .tel-table { width:100%; border-collapse:collapse; font-size:.85rem; }
    .tel-table thead th {
        background:#f8faff; padding:.85rem 1rem;
        font-size:.68rem; font-weight:800; text-transform:uppercase;
        letter-spacing:.07em; color:#6b7280;
        border-bottom:2px solid #e6eaf3; text-align:left;
    }
    .tel-table tbody td { padding:.85rem 1rem; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
    .tel-table tbody tr:last-child td { border-bottom:none; }
    .tel-table tbody tr:hover td { background:#f8faff; }

    /* Student cell */
    .tel-student { display:flex; align-items:center; gap:.75rem; }
    .tel-avatar {
        width:36px; height:36px; border-radius:10px; flex-shrink:0;
        background:linear-gradient(135deg,#4361ee,#8b5cf6);
        color:#fff; display:flex; align-items:center; justify-content:center;
        font-weight:800; font-size:.82rem;
    }
    .tel-name { font-weight:700; color:#111827; font-size:.88rem; }
    .tel-date { font-size:.72rem; color:#9ca3af; margin-top:.15rem; }
    .tel-mobile { font-weight:600; color:#374151; font-family:'Courier New',monospace; font-size:.84rem; letter-spacing:.5px; }
    .tel-course { font-weight:600; color:#374151; }
    .tel-followup-date { font-weight:700; color:#111827; font-size:.84rem; }
    .tel-followup-time { font-size:.72rem; color:#6b7280; margin-top:.1rem; }

    /* Status badges */
    .tel-badge {
        display:inline-flex; align-items:center; gap:.3rem;
        padding:.25rem .7rem; border-radius:999px;
        font-size:.72rem; font-weight:800;
    }
    .tel-badge-new      { background:#eff6ff; color:#1d4ed8; }
    .tel-badge-contacted { background:#fef3c7; color:#92400e; }
    .tel-badge-converted { background:#d1fae5; color:#065f46; }
    .tel-badge-closed   { background:#f3f4f6; color:#374151; }

    /* Action buttons */
    .tel-actions { display:flex; align-items:center; gap:.35rem; }
    .tel-btn {
        width:32px; height:32px; border-radius:8px; border:1.5px solid;
        display:inline-flex; align-items:center; justify-content:center;
        cursor:pointer; transition:all .18s; background:#fff;
    }
    .tel-btn svg { width:15px; height:15px; }
    .tel-btn-view  { border-color:#bfdbfe; color:#2563eb; }
    .tel-btn-view:hover  { background:#eff6ff; border-color:#93c5fd; transform:translateY(-1px); }
    .tel-btn-edit  { border-color:#d1d5db; color:#374151; }
    .tel-btn-edit:hover  { background:#f9fafb; border-color:#9ca3af; transform:translateY(-1px); }
    .tel-btn-del   { border-color:#fecaca; color:#dc2626; }
    .tel-btn-del:hover   { background:#fef2f2; border-color:#f87171; transform:translateY(-1px); }

    /* Empty state */
    .tel-empty { text-align:center; padding:4rem 1rem; color:#9ca3af; }
    .tel-empty svg { width:52px; height:52px; margin-bottom:1rem; opacity:.4; }
    .tel-empty p { font-size:.9rem; font-weight:600; }

    /* Toolbar */
    .tel-toolbar {
        display:flex; align-items:center; justify-content:space-between;
        gap:1rem; margin-bottom:1rem; flex-wrap:wrap;
    }
    .tel-toolbar-title { font-size:1rem; font-weight:800; color:#111827; display:flex; align-items:center; gap:.5rem; }
    .tel-toolbar-badge { font-size:.72rem; font-weight:800; background:#eef2ff; color:#4361ee; padding:.15rem .55rem; border-radius:999px; }
    .tel-search {
        display:flex; align-items:center; gap:.5rem;
        background:#fff; border:1.5px solid #e6eaf3; border-radius:10px;
        padding:.45rem .85rem;
    }
    .tel-search svg { width:16px; height:16px; color:#9ca3af; flex-shrink:0; }
    .tel-search input { border:none; outline:none; font-size:.84rem; color:#374151; background:transparent; width:200px; }
    .tel-search input::placeholder { color:#9ca3af; }

    /* Topbar for add mode */
    .tel-add-topbar {
        display:flex; align-items:center; justify-content:space-between; gap:1rem;
        background:#fff; border:1.5px solid #e6eaf3; border-radius:16px;
        padding:1rem 1.25rem; margin-bottom:1rem;
        box-shadow:0 1px 4px rgba(0,0,0,.05);
    }
    .tel-add-title { font-size:1.05rem; font-weight:800; color:#111827; }
    .tel-add-subtitle { font-size:.8rem; color:#6b7280; margin-top:.2rem; }

    /* Form card */
    .tel-form-card {
        background:#fff; border:1.5px solid #e6eaf3; border-radius:18px;
        box-shadow:0 2px 12px rgba(0,0,0,.06); margin-bottom:1rem;
        overflow:hidden;
    }
    .tel-form-card .profile-form { padding:1.5rem 1.75rem; }
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
                    <h2>Telephonic Inquiries</h2>
                    <p>Manage phone-based inquiries and follow-ups</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">
            
            <?php if ($pageMode !== 'add'): ?>
            <!-- Stat Strip -->
            <div class="tel-stat-strip">
                <div class="tel-stat">
                    <div class="tel-stat-icon" style="background:#eef2ff">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#4361ee" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.19 11.9 19.79 19.79 0 0 1 1.12 3.2 2 2 0 0 1 3.11 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    </div>
                    <div>
                        <div class="tel-stat-label">Total</div>
                        <div class="tel-stat-value"><?= $statusCounts['all'] ?></div>
                    </div>
                </div>
                <div class="tel-stat">
                    <div class="tel-stat-icon" style="background:#eff6ff">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <div>
                        <div class="tel-stat-label">New</div>
                        <div class="tel-stat-value" style="color:#1d4ed8"><?= $statusCounts['New'] ?></div>
                    </div>
                </div>
                <div class="tel-stat">
                    <div class="tel-stat-icon" style="background:#fef3c7">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#d97706" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div>
                        <div class="tel-stat-label">Contacted</div>
                        <div class="tel-stat-value" style="color:#92400e"><?= $statusCounts['Contacted'] ?></div>
                    </div>
                </div>
                <div class="tel-stat">
                    <div class="tel-stat-icon" style="background:#d1fae5">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <div class="tel-stat-label">Converted</div>
                        <div class="tel-stat-value" style="color:#065f46"><?= $statusCounts['Converted'] ?></div>
                    </div>
                </div>
            </div>

            <!-- Status Tabs -->
            <div class="status-tabs">
                <a href="?status=all" class="status-tab <?= $statusFilter === 'all' ? 'active' : '' ?>">
                    All <span class="tab-count"><?= $statusCounts['all'] ?></span>
                </a>
                <a href="?status=New" class="status-tab <?= $statusFilter === 'New' ? 'active' : '' ?>">
                    New <span class="tab-count"><?= $statusCounts['New'] ?></span>
                </a>
                <a href="?status=Contacted" class="status-tab <?= $statusFilter === 'Contacted' ? 'active' : '' ?>">
                    Contacted <span class="tab-count"><?= $statusCounts['Contacted'] ?></span>
                </a>
                <a href="?status=Converted" class="status-tab <?= $statusFilter === 'Converted' ? 'active' : '' ?>">
                    Converted <span class="tab-count"><?= $statusCounts['Converted'] ?></span>
                </a>
                <a href="?status=Closed" class="status-tab <?= $statusFilter === 'Closed' ? 'active' : '' ?>">
                    Closed <span class="tab-count"><?= $statusCounts['Closed'] ?></span>
                </a>
            </div>

            <!-- Export + Toolbar -->
            <div class="tel-toolbar">
                <div class="tel-toolbar-title">
                    <?= $statusFilter === 'all' ? 'All' : ucfirst($statusFilter) ?> Inquiries
                    <span class="tel-toolbar-badge"><?= count($inquiries) ?></span>
                </div>
                <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
                    <div class="export-btn-group">
                        <button class="exp-btn exp-copy"  onclick="exportCopy()">📋 Copy</button>
                        <button class="exp-btn exp-csv"   onclick="exportCSV()">📄 CSV</button>
                        <button class="exp-btn exp-excel" onclick="exportExcel()">📊 Excel</button>
                        <button class="exp-btn exp-pdf"   onclick="exportPDF()">📑 PDF</button>
                        <button class="exp-btn exp-print" onclick="printInquiries()">🖨️ Print</button>
                    </div>
                    <div class="tel-search">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" id="searchInput" placeholder="Search inquiries...">
                    </div>
                    <a class="btn-add" href="?mode=add" style="text-decoration:none;display:inline-flex;align-items:center;gap:.5rem;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Add Inquiry
                    </a>
                </div>
            </div>
            <?php else: ?>
            <div class="tel-add-topbar">
                <div>
                    <div class="tel-add-title">New Telephonic Inquiry</div>
                    <div class="tel-add-subtitle">Fill in the inquiry details below and save</div>
                </div>
                <a class="btn-secondary" href="telephonic_inquiry.php" style="text-decoration:none;display:inline-flex;align-items:center;gap:.45rem;">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:15px;height:15px"><polyline points="15 18 9 12 15 6"/></svg>
                    Back to List
                </a>
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
            <div style="margin-bottom:1rem;padding:.9rem 1rem;border-radius:10px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-weight:600;">
                Telephonic inquiry added successfully.
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['error']) && $_GET['error'] === 'name'): ?>
            <div style="margin-bottom:1rem;padding:.9rem 1rem;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-weight:600;">
                First Name and Last Name are required.
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['error']) && $_GET['error'] === 'mobile'): ?>
            <div style="margin-bottom:1rem;padding:.9rem 1rem;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-weight:600;">
                Valid 10-digit mobile number is required.
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['error']) && $_GET['error'] === 'followup'): ?>
            <div style="margin-bottom:1rem;padding:.9rem 1rem;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-weight:600;">
                Follow-up date and time are required.
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['error']) && $_GET['error'] === 'save'): ?>
            <div style="margin-bottom:1rem;padding:.9rem 1rem;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-weight:600;">
                Unable to save inquiry. Please try again.
            </div>
            <?php endif; ?>

            <?php if ($pageMode === 'add'): ?>
            <div class="tel-form-card">
                <form method="POST" class="profile-form" id="telephonicPageForm" style="padding:1.5rem 1.75rem 1.75rem;">
                    <input type="hidden" name="action" value="add_page">
                    <div class="form-section">
                        <div class="form-section-title">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            Student Information
                        </div>
                        <div class="profile-form-grid">
                            <div class="form-field"><label>First Name <span class="required">*</span></label><input type="text" name="first_name" required maxlength="60"></div>
                            <div class="form-field"><label>Middle Name</label><input type="text" name="middle_name" maxlength="60"></div>
                            <div class="form-field"><label>Last Name <span class="required">*</span></label><input type="text" name="last_name" required maxlength="60"></div>
                            <div class="form-field"><label>Mobile Number <span class="required">*</span></label><input type="tel" name="mobile" required pattern="[0-9]{10}" maxlength="10"></div>
                            <div class="form-field full-width"><label>Interested Course <span class="required">*</span></label>
                                <select id="page_interested_course" name="interested_course" required onchange="togglePageCustomCourse()">
                                    <option value="">-- Select Course --</option>
                                    <?php foreach ($activeCourses as $ac): ?>
                                        <option value="<?= htmlspecialchars($ac['course_name']) ?>"><?= htmlspecialchars($ac['course_name']) ?></option>
                                    <?php endforeach; ?>
                                    <option value="Other">Other / Custom</option>
                                </select>
                                <input type="text" id="page_custom_course_tel" maxlength="100" placeholder="Type course name" style="display:none;margin-top:.4rem;padding:.7rem .9rem;border:1.5px solid var(--border-color);border-radius:var(--radius-md);font-size:.88rem;width:100%;" autocomplete="off">
                            </div>
                        </div>
                    </div>
                    <div class="form-section">
                        <div class="form-section-title">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/></svg>
                            Follow-up Details
                        </div>
                        <div class="profile-form-grid">
                            <div class="form-field"><label>Next Follow-up Date <span class="required">*</span></label><input type="date" name="next_inform_date" required value="<?= date('Y-m-d', strtotime('+1 day')) ?>"></div>
                            <div class="form-field"><label>Follow-up Time <span class="required">*</span></label><input type="time" name="next_inform_time" required value="10:00"></div>
                            <div class="form-field full-width"><label>Comments <span class="required">*</span></label><textarea name="comment" required rows="3" placeholder="Notes from the phone conversation"></textarea></div>
                            <div class="form-field"><label>Status</label><select name="status"><option value="New" selected>New</option><option value="Contacted">Contacted</option><option value="Converted">Converted</option><option value="Closed">Closed</option></select></div>
                        </div>
                    </div>
                    <div class="form-actions" style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:.75rem;">
                        <a class="btn-secondary" href="telephonic_inquiry.php" style="text-decoration:none;display:inline-flex;align-items:center;">Cancel</a>
                        <button type="submit" class="btn-primary" style="display:inline-flex;align-items:center;gap:.45rem;">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px;"><polyline points="20 6 9 17 4 12"/></svg>
                            Save Inquiry
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <?php if ($pageMode !== 'add'): ?>
            <div class="tel-table-wrap">
                <table class="tel-table" id="inquiryTable">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Mobile</th>
                            <th>Course Interest</th>
                            <th>Next Follow-up</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($inquiries)): ?>
                            <tr>
                                <td colspan="6">
                                    <div class="tel-empty">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.19 11.9 19.79 19.79 0 0 1 1.12 3.2 2 2 0 0 1 3.11 1h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                        <p>No telephonic inquiries found.<br>Add your first one to get started.</p>
                                    </div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($inquiries as $inquiry):
                                $initials = strtoupper(substr($inquiry['first_name'], 0, 1) . substr($inquiry['last_name'], 0, 1));
                                $fullName = trim($inquiry['first_name'] . ' ' . ($inquiry['middle_name'] ? $inquiry['middle_name'] . ' ' : '') . $inquiry['last_name']);
                                $statusClass = 'tel-badge-' . strtolower($inquiry['status']);
                            ?>
                                <tr>
                                    <td>
                                        <div class="tel-student">
                                            <div class="tel-avatar"><?= $initials ?></div>
                                            <div>
                                                <div class="tel-name"><?= htmlspecialchars($fullName) ?></div>
                                                <div class="tel-date">Added <?= date('d M Y', strtotime($inquiry['created_at'])) ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="tel-mobile"><?= htmlspecialchars($inquiry['mobile']) ?></span></td>
                                    <td><span class="tel-course"><?= htmlspecialchars($inquiry['interested_course']) ?></span></td>
                                    <td>
                                        <div class="tel-followup-date"><?= date('d M Y', strtotime($inquiry['next_inform_date'])) ?></div>
                                        <div class="tel-followup-time"><?= date('h:i A', strtotime($inquiry['next_inform_time'])) ?></div>
                                    </td>
                                    <td>
                                        <span class="tel-badge <?= $statusClass ?>">
                                            <?= $inquiry['status'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="tel-actions">
                                            <button class="tel-btn tel-btn-view" onclick="viewInquiry(<?= $inquiry['id'] ?>)" title="View Details">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                            </button>
                                            <button class="tel-btn tel-btn-edit" onclick="editInquiry(<?= $inquiry['id'] ?>)" title="Edit">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                            </button>
                                            <button class="tel-btn tel-btn-del" onclick="deleteInquiry(<?= $inquiry['id'] ?>, '<?= htmlspecialchars($fullName, ENT_QUOTES) ?>')" title="Delete">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= renderPagination($pager, 'inquiries') ?>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Add/Edit Inquiry Modal -->
<div class="modal-overlay" id="inquiryModal">
    <div class="modal-card" style="max-width: 700px;">
        <div class="modal-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                <span id="modalTitle">Add Telephonic Inquiry</span>
            </h3>
            <button type="button" class="modal-close" onclick="closeModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        
        <form id="inquiryForm" novalidate>
            <input type="hidden" id="inquiryId" name="id">
            <input type="hidden" id="formAction" name="action" value="add">
            
            <div class="modal-body">
                
                <!-- Basic Information Section -->
                <div class="form-section">
                    <div class="form-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Student Information
                    </div>
                    
                    <div class="profile-form-grid">
                        <div class="form-field">
                            <label for="first_name">First Name <span class="required">*</span></label>
                            <input type="text" id="first_name" name="first_name" required maxlength="60" placeholder="First name" autocomplete="off">
                        </div>
                        
                        <div class="form-field">
                            <label for="middle_name">Middle Name</label>
                            <input type="text" id="middle_name" name="middle_name" maxlength="60" placeholder="Optional" autocomplete="off">
                        </div>
                        
                        <div class="form-field">
                            <label for="last_name">Last Name <span class="required">*</span></label>
                            <input type="text" id="last_name" name="last_name" required maxlength="60" placeholder="Last name" autocomplete="off">
                        </div>
                        
                        <div class="form-field">
                            <label for="mobile">Mobile Number <span class="required">*</span></label>
                            <input type="tel" id="mobile" name="mobile" required pattern="[0-9]{10}" maxlength="10" placeholder="9876543210" autocomplete="off">
                            <small class="field-hint">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                                10 digits without country code
                            </small>
                        </div>
                    </div>
                </div>
                
                <!-- Course & Follow-up Section -->
                <div class="form-section">
                    <div class="form-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                        Course Interest & Follow-up
                    </div>
                    
                    <div class="profile-form-grid">
                        <div class="form-field full-width">
                            <label for="interested_course">Interested Course <span class="required">*</span></label>
                            <select id="interested_course" name="interested_course" required>
                                <option value="">-- Select Course --</option>
                                <?php foreach ($activeCourses as $ac): ?>
                                    <option value="<?= htmlspecialchars($ac['course_name']) ?>"><?= htmlspecialchars($ac['course_name']) ?></option>
                                <?php endforeach; ?>
                                <option value="Other">Other / Custom</option>
                            </select>
                            <input type="text" id="custom_course_tel" name="custom_course_tel" maxlength="100"
                                   placeholder="Type course name" style="display:none;margin-top:.4rem;padding:.7rem .9rem;border:1.5px solid var(--border-color);border-radius:var(--radius-md);font-size:.88rem;width:100%;" autocomplete="off">
                        </div>
                        
                        <div class="form-field">
                            <label for="next_inform_date">Next Follow-up Date <span class="required">*</span></label>
                            <input type="date" id="next_inform_date" name="next_inform_date" required>
                        </div>
                        
                        <div class="form-field">
                            <label for="next_inform_time">Follow-up Time <span class="required">*</span></label>
                            <input type="time" id="next_inform_time" name="next_inform_time" required>
                        </div>
                        
                        <div class="form-field full-width">
                            <label for="comment">Comments <span class="required">*</span></label>
                            <textarea id="comment" name="comment" required rows="3" placeholder="Notes from the phone conversation"></textarea>
                        </div>
                        
                        <div class="form-field">
                            <label for="status">Status</label>
                            <select id="status" name="status">
                                <option value="New">New</option>
                                <option value="Contacted">Contacted</option>
                                <option value="Converted">Converted</option>
                                <option value="Closed">Closed</option>
                            </select>
                        </div>
                    </div>
                </div>
                
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    Cancel
                </button>
                <button type="submit" class="btn-primary" id="submitBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px;">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    <span id="submitBtnText">Add Inquiry</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Inquiry Modal -->
<div class="modal-overlay" id="viewModal">
    <div class="modal-card" style="max-width: 600px;">
        <div class="modal-header">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                Inquiry Details
            </h3>
            <button type="button" class="modal-close" onclick="closeViewModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        
        <div class="modal-body" id="viewContent">
            <!-- Content will be populated by JavaScript -->
        </div>
        
        <div class="modal-footer">
            <button type="button" class="btn-secondary" onclick="closeViewModal()">
                Close
            </button>
        </div>
    </div>
</div>

<!-- Convert to Admission Modal -->
<div class="modal-overlay" id="convertModal">
    <div class="modal-card" style="max-width: 520px;">
        <div class="modal-header" style="background: linear-gradient(135deg, #f0fdf4, #dcfce7);">
            <h3 style="color: #15803d;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:22px;height:22px;color:#16a34a"><polyline points="20 6 9 17 4 12"/></svg>
                <span>Convert to Admission</span>
            </h3>
            <button type="button" class="modal-close" onclick="closeConvertModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <p style="font-size:.9rem;color:var(--text-secondary);margin-bottom:1.25rem;">
                Creating admission for: <strong id="convertStudentName" style="color:var(--text-primary)"></strong>
            </p>
            <input type="hidden" id="convertInquiryId">
            <div class="profile-form-grid" style="grid-template-columns: repeat(2, 1fr);">
                <div class="form-field full-width">
                    <label for="conv_course_select">Course <span style="color:#ef4444;">*</span></label>
                    <select id="conv_course_select" onchange="toggleConvertUniformSizeModal()">
                        <option value="">-- Select Course --</option>
                        <?php foreach ($activeCourses as $ac): ?>
                            <option value="<?= htmlspecialchars($ac['course_name']) ?>"><?= htmlspecialchars($ac['course_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field" id="convertUniformSizeField" style="display:none;">
                    <label for="conv_uniform_size">T-Shirt Size</label>
                    <select id="conv_uniform_size">
                        <option value="">-- Select T-Shirt Size --</option>
                        <option value="36">36</option>
                        <option value="38">38</option>
                        <option value="40">40</option>
                        <option value="42">42</option>
                        <option value="44">44</option>
                        <option value="46">46</option>
                    </select>
                </div>
                <div class="form-field">
                    <label for="conv_course_fees">Course Fees (₹)</label>
                    <input type="number" id="conv_course_fees" min="0" step="0.01" placeholder="0.00" oninput="updateConvPending()">
                </div>
                <div class="form-field">
                    <label for="conv_discount">Discount (₹)</label>
                    <input type="number" id="conv_discount" min="0" step="0.01" placeholder="0.00" oninput="updateConvPending()">
                </div>
                <div class="form-field">
                    <label>Fees Pending (₹)</label>
                    <input type="text" id="conv_pending" placeholder="0.00" readonly style="background:var(--gray-100);font-weight:700;color:var(--danger-600);">
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-secondary" onclick="closeConvertModal()">Cancel</button>
            <button type="button" class="btn-primary" id="convertSubmitBtn" onclick="submitConversion()" style="background:linear-gradient(135deg,#16a34a,#15803d);box-shadow:0 4px 12px rgba(22,163,74,.3);">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:16px;height:16px"><polyline points="20 6 9 17 4 12"/></svg>
                Confirm & Convert
            </button>
        </div>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<style>
/* Legacy overrides — kept minimal, new styles are in <head> */
.status-tabs {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1.5rem;
    overflow-x: auto;
    padding-bottom: 0.5rem;
}

.status-tab {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.25rem;
    border-radius: var(--radius-lg);
    background: var(--bg-surface);
    border: 1.5px solid var(--border-color);
    text-decoration: none;
    color: var(--text-primary);
    font-weight: 600;
    font-size: 0.85rem;
    transition: all 0.2s ease;
    white-space: nowrap;
}

.status-tab:hover {
    border-color: var(--primary-300);
    background: var(--primary-50);
}

.status-tab.active {
    background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
    border-color: var(--primary-600);
    color: #fff;
}

.tab-count {
    padding: 0.2rem 0.6rem;
    border-radius: var(--radius-full);
    font-size: 0.75rem;
    font-weight: 700;
    background: var(--gray-200);
    color: var(--text-primary);
}

.status-tab.active .tab-count {
    background: rgba(255, 255, 255, 0.25);
    color: #fff;
}

.badge-new {
    background: var(--blue-100);
    color: var(--blue-700);
}

.badge-contacted {
    background: var(--amber-100);
    color: var(--amber-700);
}

.badge-converted {
    background: var(--success-100);
    color: var(--success-700);
}

.badge-closed {
    background: var(--gray-200);
    color: var(--gray-700);
}

/* Status badges in table */
.cell-badge.status-new {
    background: var(--blue-100);
    color: var(--blue-700);
}

.cell-badge.status-contacted {
    background: var(--amber-100);
    color: var(--amber-700);
}

.cell-badge.status-converted {
    background: var(--success-100);
    color: var(--success-700);
}

.cell-badge.status-closed {
    background: var(--gray-200);
    color: var(--gray-700);
}

/* Modal styles */
.modal-overlay {
    animation: fadeIn 0.2s ease;
}

@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}

.modal-card {
    animation: slideUp 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes slideUp {
    from {
        opacity: 0;
        transform: translateY(30px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.modal-header {
    background: linear-gradient(135deg, var(--primary-50), var(--blue-50));
    padding: 1.5rem 1.75rem;
}

.modal-header h3 {
    font-size: 1.15rem;
    color: var(--primary-700);
}

.modal-header h3 svg {
    width: 22px;
    height: 22px;
    color: var(--primary-600);
}

.modal-close {
    width: 36px;
    height: 36px;
    border-radius: var(--radius-md);
    border: none;
    background: var(--gray-100);
    color: var(--text-muted);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.modal-close:hover {
    background: var(--gray-200);
    color: var(--text-primary);
}

.modal-body {
    padding: 2rem 1.75rem;
    max-height: calc(90vh - 180px);
    overflow-y: auto;
}

.form-section {
    margin-bottom: 1.75rem;
}

.form-section-title {
    font-size: 0.8rem;
    font-weight: 800;
    color: var(--text-primary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 2px solid var(--gray-200);
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.form-section-title svg {
    width: 16px;
    height: 16px;
    color: var(--primary-500);
}

.form-field {
    margin-bottom: 1.25rem;
}

.form-field label {
    display: block;
    font-size: 0.82rem;
    font-weight: 700;
    color: var(--text-primary);
    margin-bottom: 0.5rem;
}

.form-field label .required {
    color: var(--danger-500);
    font-weight: 800;
    margin-left: 2px;
}

.form-field input,
.form-field select,
.form-field textarea {
    width: 100%;
    padding: 0.7rem 0.9rem;
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-md);
    font-size: 0.88rem;
    font-family: inherit;
    color: var(--text-primary);
    background: var(--bg-surface);
    transition: all 0.2s ease;
}

.form-field input:focus,
.form-field select:focus,
.form-field textarea:focus {
    outline: none;
    border-color: var(--primary-500);
    box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
    background: #fff;
}

.form-field textarea {
    resize: vertical;
    min-height: 60px;
}

.field-hint {
    display: block;
    font-size: 0.72rem;
    color: var(--text-muted);
    margin-top: 0.35rem;
    display: flex;
    align-items: center;
    gap: 0.3rem;
}

.field-hint svg {
    width: 12px;
    height: 12px;
    flex-shrink: 0;
}

input[type="tel"] {
    font-family: 'Courier New', monospace;
    letter-spacing: 0.5px;
    font-weight: 600;
}

.modal-footer {
    padding: 1.25rem 1.75rem;
    background: var(--gray-50);
    border-top: 1.5px solid var(--border-color);
}

.btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.5rem;
    border: none;
    border-radius: var(--radius-md);
    background: linear-gradient(135deg, var(--primary-500), var(--primary-700));
    color: #fff;
    font-size: 0.88rem;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    transition: all 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
    box-shadow: 0 4px 12px rgba(67, 97, 238, 0.25);
}

.btn-primary:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(67, 97, 238, 0.35);
}

.btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.btn-secondary {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem 1.5rem;
    border: 1.5px solid var(--border-color);
    border-radius: var(--radius-md);
    background: var(--bg-surface);
    color: var(--text-primary);
    font-size: 0.88rem;
    font-weight: 700;
    font-family: inherit;
    cursor: pointer;
    transition: all 0.2s ease;
}

.btn-secondary:hover {
    background: var(--gray-100);
    border-color: var(--gray-300);
    transform: translateY(-1px);
}

/* View Modal Content */
.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1.5rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
}

.detail-label {
    font-size: 0.75rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.detail-value {
    font-size: 0.95rem;
    color: var(--text-primary);
    font-weight: 600;
}

.detail-item.full-width {
    grid-column: 1 / -1;
}

.table-card .profile-form .profile-form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.85rem 1rem;
}

.table-card .profile-form .profile-form-grid .form-field.full-width {
    grid-column: 1 / -1;
}

@media (max-width: 640px) {
    .status-tabs {
        flex-wrap: nowrap;
    }
    
    .detail-grid {
        grid-template-columns: 1fr;
    }
    
    .profile-form-grid {
        grid-template-columns: 1fr !important;
    }
}
.btn-icon.success {
    background: var(--success-50, #f0fdf4);
    color: var(--success-700, #15803d);
    border-color: var(--success-200, #bbf7d0);
}
.btn-icon.success:hover {
    background: var(--success-100, #dcfce7);
    border-color: var(--success-400, #4ade80);
    color: var(--success-800, #166534);
    transform: translateY(-1px);
}

.tel-add-topbar {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:1rem;
    background:var(--bg-surface);
    border:1px solid var(--border-color);
    border-radius:var(--radius-xl);
    padding:1rem 1.25rem;
    margin-bottom:1rem;
}

.tel-add-title { font-size:1.05rem; font-weight:800; color:var(--text-primary); }
.tel-add-subtitle { font-size:.8rem; color:var(--text-muted); margin-top:.2rem; }
</style>

<script>
// Search functionality
const searchInput = document.getElementById('searchInput');
if (searchInput) {
    searchInput.addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        const rows = document.querySelectorAll('#inquiryTable tbody tr');
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchTerm) ? '' : 'none';
        });
    });
}

// Open add modal
function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Add Telephonic Inquiry';
    document.getElementById('submitBtnText').textContent = 'Add Inquiry';
    document.getElementById('formAction').value = 'add';
    document.getElementById('inquiryForm').reset();
    document.getElementById('inquiryId').value = '';
    document.getElementById('status').value = 'New';
    
    // Set default next inform date to tomorrow
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    document.getElementById('next_inform_date').value = tomorrow.toISOString().split('T')[0];
    
    // Set default time to 10:00 AM
    document.getElementById('next_inform_time').value = '10:00';
    
    document.getElementById('inquiryModal').classList.add('active');
    
    setTimeout(() => {
        document.getElementById('first_name').focus();
    }, 100);
}

// When editing, handle the course dropdown (try match, fallback to Other)
function setCourseDropdown(val) {
    const sel = document.getElementById('interested_course');
    const customInput = document.getElementById('custom_course_tel');
    let matched = false;
    for (let i = 0; i < sel.options.length; i++) {
        if (sel.options[i].value === val && val !== 'Other') { sel.value = val; matched = true; break; }
    }
    if (!matched && val) {
        sel.value = 'Other';
        customInput.style.display = 'block';
        customInput.value = val;
        customInput.name = 'interested_course';
        sel.name = 'ignore_course_tel';
    } else {
        customInput.style.display = 'none';
        customInput.value = '';
        customInput.name = 'custom_course_tel';
        sel.name = 'interested_course';
    }
}

// Handle course dropdown change
const interestedCourse = document.getElementById('interested_course');
if (interestedCourse) {
    interestedCourse.addEventListener('change', function() {
        const customInput = document.getElementById('custom_course_tel');
        if (this.value === 'Other') {
            customInput.style.display = 'block';
            customInput.required = true;
            customInput.name = 'interested_course';
            this.name = 'ignore_course_tel';
            setTimeout(() => customInput.focus(), 50);
        } else {
            customInput.style.display = 'none';
            customInput.required = false;
            customInput.name = 'custom_course_tel';
            this.name = 'interested_course';
        }
    });
}

function togglePageCustomCourse() {
    const selectEl = document.getElementById('page_interested_course');
    const customEl = document.getElementById('page_custom_course_tel');
    if (!selectEl || !customEl) return;

    if (selectEl.value === 'Other') {
        customEl.style.display = 'block';
        customEl.required = true;
        customEl.name = 'interested_course';
        selectEl.name = 'ignore_course_tel_page';
        setTimeout(() => customEl.focus(), 50);
    } else {
        customEl.style.display = 'none';
        customEl.required = false;
        customEl.name = 'page_custom_course_tel';
        selectEl.name = 'interested_course';
    }
}

// Edit inquiry
async function editInquiry(id) {
    try {
        const formData = new FormData();
        formData.append('action', 'get');
        formData.append('id', id);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            const inquiry = result.data;
            document.getElementById('modalTitle').textContent = 'Edit Telephonic Inquiry';
            document.getElementById('submitBtnText').textContent = 'Update Inquiry';
            document.getElementById('formAction').value = 'edit';
            document.getElementById('inquiryId').value = inquiry.id;
            document.getElementById('first_name').value = inquiry.first_name;
            document.getElementById('middle_name').value = inquiry.middle_name || '';
            document.getElementById('last_name').value = inquiry.last_name;
            setCourseDropdown(inquiry.interested_course);
            document.getElementById('mobile').value = inquiry.mobile;
            document.getElementById('next_inform_date').value = inquiry.next_inform_date;
            document.getElementById('next_inform_time').value = inquiry.next_inform_time;
            document.getElementById('comment').value = inquiry.comment;
            document.getElementById('status').value = inquiry.status;
            document.getElementById('inquiryModal').classList.add('active');
            
            setTimeout(() => {
                document.getElementById('first_name').focus();
            }, 100);
        }
    } catch (error) {
        alert('Error loading inquiry data. Please try again.');
    }
}

// View inquiry details
async function viewInquiry(id) {
    try {
        const formData = new FormData();
        formData.append('action', 'get');
        formData.append('id', id);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            const inquiry = result.data;
            const fullName = inquiry.first_name + (inquiry.middle_name ? ' ' + inquiry.middle_name : '') + ' ' + inquiry.last_name;
            
            const content = `
                <div class="detail-grid">
                    <div class="detail-item full-width">
                        <div class="detail-label">Student Name</div>
                        <div class="detail-value">${fullName}</div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Mobile Number</div>
                        <div class="detail-value">${inquiry.mobile}</div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Interested Course</div>
                        <div class="detail-value">${inquiry.interested_course}</div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Next Follow-up Date</div>
                        <div class="detail-value">${new Date(inquiry.next_inform_date).toLocaleDateString('en-IN', {day: '2-digit', month: 'short', year: 'numeric'})}</div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Follow-up Time</div>
                        <div class="detail-value">${new Date('2000-01-01 ' + inquiry.next_inform_time).toLocaleTimeString('en-IN', {hour: '2-digit', minute: '2-digit'})}</div>
                    </div>
                    
                    <div class="detail-item full-width">
                        <div class="detail-label">Comments</div>
                        <div class="detail-value">${inquiry.comment}</div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Status</div>
                        <div class="detail-value">
                            <span class="cell-badge status-${inquiry.status.toLowerCase()}">${inquiry.status}</span>
                        </div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-label">Created On</div>
                        <div class="detail-value">${new Date(inquiry.created_at).toLocaleDateString('en-IN', {day: '2-digit', month: 'short', year: 'numeric'})}</div>
                    </div>
                </div>
            `;
            
            document.getElementById('viewContent').innerHTML = content;
            document.getElementById('viewModal').classList.add('active');
        }
    } catch (error) {
        alert('Error loading inquiry details. Please try again.');
    }
}

// Convert to Admission — open modal
function convertToAdmission(id, name, course) {
    document.getElementById('convertInquiryId').value = id;
    document.getElementById('convertStudentName').textContent = name;
    document.getElementById('conv_course_select').value = course || '';
    document.getElementById('conv_uniform_size').value = '';
    document.getElementById('conv_course_fees').value = '';
    document.getElementById('conv_discount').value = '';
    document.getElementById('conv_pending').value = '';
    document.getElementById('convertModal').classList.add('active');
    // Trigger toggle immediately to show/hide t-shirt field based on selected course
    setTimeout(() => toggleConvertUniformSizeModal(), 100);
}

function updateConvPending() {
    const fees    = parseFloat(document.getElementById('conv_course_fees').value) || 0;
    const disc    = parseFloat(document.getElementById('conv_discount').value) || 0;
    const pending = Math.max(0, fees - disc);
    document.getElementById('conv_pending').value = pending.toFixed(2);
}

// Toggle T-Shirt size field based on course selection in conversion modal
function toggleConvertUniformSizeModal() {
    const courseSelect = document.getElementById('conv_course_select');
    const uniformSizeField = document.getElementById('convertUniformSizeField');
    
    if (courseSelect && uniformSizeField) {
        const courseValue = courseSelect.value.toLowerCase().trim();
        if (courseValue.includes('abacus')) {
            uniformSizeField.style.display = 'block';
        } else {
            uniformSizeField.style.display = 'none';
            document.getElementById('conv_uniform_size').value = '';
        }
    }
}

function closeConvertModal() {
    document.getElementById('convertModal').classList.remove('active');
}

async function submitConversion() {
    const id  = document.getElementById('convertInquiryId').value;
    if (!id) return;
    const btn = document.getElementById('convertSubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Converting…';
    
    try {
        const courseVal = document.getElementById('conv_course_select').value;
        if (!courseVal) {
            alert('Please select a course');
            btn.disabled = false;
            btn.textContent = 'Confirm & Convert';
            return;
        }
        
        const formData = new FormData();
        formData.append('action',          'convert_telephonic_to_admission');
        formData.append('inquiry_id',      id);
        formData.append('course',          courseVal);
        formData.append('uniform_size',    document.getElementById('conv_uniform_size').value || '');
        formData.append('course_fees',     document.getElementById('conv_course_fees').value || 0);
        formData.append('discount_amount', document.getElementById('conv_discount').value   || 0);
        const response = await fetch('', { method: 'POST', body: formData });
        const result   = await response.json();
        closeConvertModal();
        if (result.success) {
            showNotification(result.message, 'success');
            setTimeout(() => location.reload(), 1800);
        } else {
            showNotification('Error: ' + (result.message || 'Conversion failed'), 'error');
            btn.disabled = false;
            btn.textContent = 'Confirm & Convert';
        }
    } catch (e) {
        showNotification('Unexpected error during conversion', 'error');
        btn.disabled = false;
        btn.textContent = 'Confirm & Convert';
    }
}

// Delete inquiry
async function deleteInquiry(id, name) {
    const confirmed = confirm(
        `⚠️ Delete Telephonic Inquiry\n\n` +
        `Are you sure you want to delete the inquiry for "${name}"?\n\n` +
        `This action cannot be undone.`
    );
    
    if (!confirmed) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete');
        formData.append('id', id);
        
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            showNotification('Telephonic inquiry deleted successfully', 'success');
            setTimeout(() => {
                location.reload();
            }, 800);
        } else {
            alert(result.message || 'Error deleting inquiry');
        }
    } catch (error) {
        alert('Error deleting inquiry. Please try again.');
    }
}

// Close modals
function closeModal() {
    document.getElementById('inquiryModal').classList.remove('active');
}

function closeViewModal() {
    document.getElementById('viewModal').classList.remove('active');
}

// Form submission
const inquiryFormEl = document.getElementById('inquiryForm');
if (inquiryFormEl) inquiryFormEl.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    // Validate mobile
    const mobile = document.getElementById('mobile').value;
    if (!/^[0-9]{10}$/.test(mobile)) {
        alert('Please enter a valid 10-digit mobile number');
        document.getElementById('mobile').focus();
        return;
    }
    
    const submitBtn = document.getElementById('submitBtn');
    const submitBtnText = document.getElementById('submitBtnText');
    const originalText = submitBtnText.textContent;
    
    submitBtn.disabled = true;
    submitBtnText.textContent = 'Saving...';
    
    const formData = new FormData(this);
    
    try {
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            const action = formData.get('action');
            const message = action === 'add' ? 'Telephonic inquiry added successfully!' : 'Telephonic inquiry updated successfully!';
            
            showNotification(message, 'success');
            
            setTimeout(() => {
                location.reload();
            }, 800);
        } else {
            alert(result.message || 'Error saving inquiry');
            submitBtn.disabled = false;
            submitBtnText.textContent = originalText;
        }
    } catch (error) {
        alert('Error saving inquiry. Please try again.');
        submitBtn.disabled = false;
        submitBtnText.textContent = originalText;
    }
});

// Close modals on overlay click
const inquiryModalEl = document.getElementById('inquiryModal');
if (inquiryModalEl) inquiryModalEl.addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});

const viewModalEl = document.getElementById('viewModal');
if (viewModalEl) viewModalEl.addEventListener('click', function(e) {
    if (e.target === this) {
        closeViewModal();
    }
});

// Show notification helper
function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        background: ${type === 'success' ? '#10b981' : '#ef4444'};
        color: white;
        padding: 1rem 1.5rem;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 10000;
        font-weight: 600;
        animation: slideIn 0.3s ease;
    `;
    notification.textContent = message;
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 2500);
}

// Add CSS animations
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(400px);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(400px);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

// ── Export helpers (Telephonic Inquiries) ─────────────────────────────────
function getTelTableData() {
    const headers = ['Student Name','Mobile','Course Interest','Follow-up Date','Follow-up Time','Status'];
    const rows = [];
    document.querySelectorAll('#inquiryTable tbody tr').forEach(tr => {
        if (tr.style.display === 'none') return;
        const tds = tr.querySelectorAll('td');
        if (!tds.length || tds[0].getAttribute('colspan')) return;
        rows.push([
            tds[0]?.querySelector('.cell-name')?.textContent?.trim() || '',
            tds[1]?.querySelector('.cell-name')?.textContent?.trim() || '',
            tds[2]?.textContent?.trim() || '',
            tds[3]?.querySelector('div')?.textContent?.trim() || '',
            tds[3]?.querySelector('.cell-sub')?.textContent?.trim() || '',
            tds[4]?.querySelector('.cell-badge')?.textContent?.trim() || '',
        ]);
    });
    return { headers, rows };
}
function exportCopy() {
    const { headers, rows } = getTelTableData();
    if (!rows.length) { alert('No data to copy.'); return; }
    navigator.clipboard.writeText([headers,...rows].map(r=>r.join('\t')).join('\n'))
        .then(()=>alert('✅ Copied '+rows.length+' inquiries to clipboard!'));
}
function exportCSV() {
    const { headers, rows } = getTelTableData();
    if (!rows.length) { alert('No data to export.'); return; }
    const csv = [headers,...rows].map(r=>r.map(c=>'"'+String(c).replace(/"/g,'""')+'"').join(',')).join('\n');
    Object.assign(document.createElement('a'),{href:'data:text/csv;charset=utf-8,'+encodeURIComponent(csv),download:'tel_inquiries_'+Date.now()+'.csv'}).click();
}
function exportExcel() {
    const { headers, rows } = getTelTableData();
    if (!rows.length) { alert('No data to export.'); return; }
    const ws=XLSX.utils.aoa_to_sheet([headers,...rows]);
    const wb=XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb,ws,'Telephonic');
    XLSX.writeFile(wb,'tel_inquiries_'+Date.now()+'.xlsx');
}
function exportPDF() {
    const { headers, rows } = getTelTableData();
    if (!rows.length) { alert('No data to export.'); return; }
    const {jsPDF}=window.jspdf;
    const doc=new jsPDF({orientation:'landscape'});
    doc.setFontSize(13);doc.text('Telephonic Inquiries — Gyanam India',14,14);
    doc.setFontSize(9);doc.text('Generated: '+new Date().toLocaleString('en-IN'),14,21);
    doc.autoTable({head:[headers],body:rows,startY:26,styles:{fontSize:8},headStyles:{fillColor:[67,97,238]}});
    doc.save('tel_inquiries_'+Date.now()+'.pdf');
}
function printInquiries() {
    const { headers, rows } = getTelTableData();
    if (!rows.length) { alert('No data to print.'); return; }
    const now=new Date().toLocaleString('en-IN',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
    const thHtml=headers.map(h=>`<th>${h}</th>`).join('');
    const rowsHtml=rows.map(r=>'<tr>'+r.map(c=>`<td>${c}</td>`).join('')+'</tr>').join('');
    const html=`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Telephonic Inquiries</title>
    <style>body{font-family:Arial,sans-serif;margin:1cm;font-size:11px}h2{margin:0;font-size:15px}p{margin:0 0 8px}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th{background:#4361ee;color:#fff;padding:6px 8px;text-align:left;font-size:9.5px;text-transform:uppercase}
    td{padding:5px 8px;border-bottom:1px solid #e5e7eb}tr:nth-child(even) td{background:#f8fafc}
    .footer{margin-top:12px;font-size:10px;color:#94a3b8;text-align:right}
    @media print{@page{margin:1cm;size:landscape}}</style></head><body>
    <h2>Telephonic Inquiries — Gyanam India</h2>
    <p style="font-size:11px;color:#64748b">Generated: ${now} &bull; ${rows.length} record(s)</p>
    <table><thead><tr>${thHtml}</tr></thead><tbody>${rowsHtml}</tbody></table>
    <div class="footer">Gyanam India — Confidential</div></body></html>`;
    const w=window.open('','_blank','width=1100,height=700');
    w.document.write(html);w.document.close();w.focus();
    setTimeout(()=>{w.print();w.close();},400);
}
</script>
</body>
</html>
