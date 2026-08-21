<?php
/**
 * Gyanam Portal — ATC: Inquiries Management
 * Matches actual DB table: inquiries
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo    = getDBConnection();
$userName = sanitize(getUserName());
$atcId  = $_SESSION['atc_id'] ?? null;
$userId = $_SESSION['user_id'] ?? null;
$pageMode = $_GET['mode'] ?? '';
ensureDualMaterialCourseSchema($pdo);

/* ── Full-page Add Inquiry submit ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'add_page')) {
    try {
        if (empty(trim($_POST['pin_code'] ?? ''))) {
            header('Location: inquiries.php?mode=add&error=pin');
            exit;
        }
        if (empty(trim($_POST['dob'] ?? ''))) {
            header('Location: inquiries.php?mode=add&error=dob');
            exit;
        }

        $stmt = $pdo->prepare("\n            INSERT INTO inquiries (
                atc_id, inquiry_type, first_name, middle_name, last_name,
                gender, dob, qualification, interested_course, inquiry_date,
                course_fees, quoted_fees, address, pin_code, city,
                mobile, phone, email, next_inform_date, next_inform_time,
                referenced_by, comment, status
            ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $atcId,
            $_POST['inquiry_type']     ?? 'Walk-in',
            $_POST['first_name'],
            $_POST['middle_name']      ?? '',
            $_POST['last_name'],
            $_POST['gender']           ?? 'Male',
            $_POST['dob']              ?? null,
            $_POST['qualification']    ?? '',
            $_POST['interested_course'],
            $_POST['inquiry_date']     ?? date('Y-m-d'),
            $_POST['course_fees']      ?? 0,
            $_POST['quoted_fees']      ?? 0,
            $_POST['address']          ?? '',
            trim($_POST['pin_code']),
            $_POST['city']             ?? '',
            $_POST['mobile'],
            $_POST['phone']            ?? '',
            $_POST['email']            ?? '',
            $_POST['next_inform_date'] ?? date('Y-m-d'),
            $_POST['next_inform_time'] ?? '10:00',
            $_POST['referenced_by']    ?? '',
            $_POST['comment']          ?? '',
            $_POST['status']           ?? 'New',
        ]);
        header('Location: inquiries.php?saved=1');
        exit;
    } catch (Exception $e) {
        header('Location: inquiries.php?mode=add&error=save');
        exit;
    }
}

/* ── AJAX ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        switch ($_POST['action']) {

            case 'add':
                // Server-side validation
                if (empty(trim($_POST['pin_code'] ?? ''))) {
                    echo json_encode(['success' => false, 'message' => 'PIN Code is required.']);
                    exit;
                }
                if (empty(trim($_POST['dob'] ?? ''))) {
                    echo json_encode(['success' => false, 'message' => 'Date of Birth is required for birthday reminders.']);
                    exit;
                }
                $stmt = $pdo->prepare("
                    INSERT INTO inquiries (
                        atc_id, inquiry_type, first_name, middle_name, last_name,
                        gender, dob, qualification, interested_course, inquiry_date,
                        course_fees, quoted_fees, address, pin_code, city,
                        mobile, phone, email, next_inform_date, next_inform_time,
                        referenced_by, comment, status
                    ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                ");
                $stmt->execute([
                    $atcId,
                    $_POST['inquiry_type']     ?? 'Walk-in',
                    $_POST['first_name'],
                    $_POST['middle_name']      ?? '',
                    $_POST['last_name'],
                    $_POST['gender']           ?? 'Male',
                    $_POST['dob']              ?? null,
                    $_POST['qualification']    ?? '',
                    $_POST['interested_course'],
                    $_POST['inquiry_date']     ?? date('Y-m-d'),
                    $_POST['course_fees']      ?? 0,
                    $_POST['quoted_fees']      ?? 0,
                    $_POST['address']          ?? '',
                    trim($_POST['pin_code']),
                    $_POST['city']             ?? '',
                    $_POST['mobile'],
                    $_POST['phone']            ?? '',
                    $_POST['email']            ?? '',
                    $_POST['next_inform_date'] ?? date('Y-m-d'),
                    $_POST['next_inform_time'] ?? '10:00',
                    $_POST['referenced_by']    ?? '',
                    $_POST['comment']          ?? '',
                    $_POST['status']           ?? 'New',
                ]);
                echo json_encode(['success' => true, 'message' => 'Inquiry added successfully']);
                exit;

            case 'edit':
                // Server-side validation
                if (empty(trim($_POST['pin_code'] ?? ''))) {
                    echo json_encode(['success' => false, 'message' => 'PIN Code is required.']);
                    exit;
                }
                if (empty(trim($_POST['dob'] ?? ''))) {
                    echo json_encode(['success' => false, 'message' => 'Date of Birth is required for birthday reminders.']);
                    exit;
                }
                $stmt = $pdo->prepare("
                    UPDATE inquiries SET
                        inquiry_type=?, first_name=?, middle_name=?, last_name=?,
                        gender=?, dob=?, qualification=?, interested_course=?,
                        inquiry_date=?, course_fees=?, quoted_fees=?,
                        address=?, pin_code=?, city=?, mobile=?, phone=?, email=?,
                        next_inform_date=?, next_inform_time=?,
                        referenced_by=?, comment=?, status=?
                    WHERE id=? AND atc_id=?
                ");
                $stmt->execute([
                    $_POST['inquiry_type']     ?? 'Walk-in',
                    $_POST['first_name'],
                    $_POST['middle_name']      ?? '',
                    $_POST['last_name'],
                    $_POST['gender']           ?? 'Male',
                    $_POST['dob']              ?? null,
                    $_POST['qualification']    ?? '',
                    $_POST['interested_course'],
                    $_POST['inquiry_date']     ?? date('Y-m-d'),
                    $_POST['course_fees']      ?? 0,
                    $_POST['quoted_fees']      ?? 0,
                    $_POST['address']          ?? '',
                    trim($_POST['pin_code']),
                    $_POST['city']             ?? '',
                    $_POST['mobile'],
                    $_POST['phone']            ?? '',
                    $_POST['email']            ?? '',
                    $_POST['next_inform_date'] ?? date('Y-m-d'),
                    $_POST['next_inform_time'] ?? '10:00',
                    $_POST['referenced_by']    ?? '',
                    $_POST['comment']          ?? '',
                    $_POST['status'],
                    $_POST['id'],
                    $atcId,
                ]);
                echo json_encode(['success' => true, 'message' => 'Inquiry updated successfully']);
                exit;

            case 'delete':
                $stmt = $pdo->prepare("DELETE FROM inquiries WHERE id=? AND atc_id=?");
                $stmt->execute([$_POST['id'], $atcId]);
                echo json_encode(['success' => true, 'message' => 'Inquiry deleted successfully']);
                exit;

            case 'get':
                $stmt = $pdo->prepare("SELECT * FROM inquiries WHERE id=? AND atc_id=?");
                $stmt->execute([$_POST['id'], $atcId]);
                echo json_encode(['success' => true, 'data' => $stmt->fetch(PDO::FETCH_ASSOC)]);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

/* ── Fetch inquiries (paginated) ── */
$statusFilter = $_GET['status'] ?? 'all';
$validStatuses = ['New','Contacted','Converted','Closed'];
$pagerParams = paginationParams(25);

$inqWhere = [];
$inqParams = [];
if ($atcId) {
    $inqWhere[] = 'atc_id = ?';
    $inqParams[] = $atcId;
}
if ($statusFilter !== 'all' && in_array($statusFilter, $validStatuses)) {
    $inqWhere[] = 'status = ?';
    $inqParams[] = $statusFilter;
} else {
    $inqWhere[] = "status != 'Converted'";
}
$inqWhereSql = $inqWhere ? (' WHERE ' . implode(' AND ', $inqWhere)) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM inquiries' . $inqWhereSql);
$countStmt->execute($inqParams);
$pager = paginationMeta((int)$countStmt->fetchColumn(), $pagerParams);

$stmt = $pdo->prepare('SELECT * FROM inquiries' . $inqWhereSql . ' ORDER BY created_at DESC'
    . " LIMIT {$pager['per_page']} OFFSET {$pager['offset']}");
$stmt->execute($inqParams);
$inquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ── Fetch active courses for Dropdown (either material fee > 0) ── */
$courseStmt = $pdo->prepare("
    SELECT c.course_name, acf.final_fee AS fees,
           acf.fee_with_material, acf.fee_without_material
    FROM courses c
    INNER JOIN atc_course_fees acf ON acf.course_id = c.id AND acf.atc_id = ?
    WHERE c.status = 'Active'
      AND (COALESCE(acf.fee_with_material, 0) > 0
        OR COALESCE(acf.fee_without_material, 0) > 0
        OR COALESCE(acf.final_fee, 0) > 0)
    ORDER BY c.course_name ASC
");
$courseStmt->execute([$atcId]);
$activeCourses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

// Build JS map for course fees (prefer without-material; else with; else legacy)
$courseFeesJS = [];
foreach ($activeCourses as $c) {
    $feeW  = floatval($c['fee_without_material'] ?? 0);
    $feeM  = floatval($c['fee_with_material'] ?? 0);
    $legacy = floatval($c['fees'] ?? 0);
    $courseFeesJS[$c['course_name']] = $feeW > 0 ? $feeW : ($feeM > 0 ? $feeM : $legacy);
}
$courseFeesJSON = json_encode($courseFeesJS);

/* ── Status counts ── */
$statusCounts = ['all'=>0,'New'=>0,'Contacted'=>0,'Converted'=>0,'Closed'=>0];
if ($atcId) {
    $cStmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM inquiries WHERE atc_id=? GROUP BY status");
    $cStmt->execute([$atcId]);
} else {
    $cStmt = $pdo->query("SELECT status, COUNT(*) as cnt FROM inquiries GROUP BY status");
}
foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
    if (isset($statusCounts[$c['status']])) $statusCounts[$c['status']] = $c['cnt'];
    if ($c['status'] !== 'Converted') $statusCounts['all'] += $c['cnt'];
}

/* ── Helper ── */
function fullName(array $r): string {
    return trim($r['first_name'].' '.($r['middle_name'] ? $r['middle_name'].' ' : '').$r['last_name']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inquiries — ATC Center | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/inquiries.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📚</text></svg>">
    <!-- Export libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>
    <style>
    .export-btn-group { display: flex; gap: .35rem; flex-wrap: wrap; align-items: center; }
    .exp-btn {
        display: inline-flex; align-items: center; gap: .28rem;
        padding: .38rem .7rem; border-radius: 8px; border: 1.5px solid;
        font-size: .7rem; font-weight: 700; cursor: pointer;
        white-space: nowrap; transition: all .18s; font-family: inherit;
    }
    .exp-copy  { background:#f1f5f9; border-color:#cbd5e1; color:#475569; }
    .exp-csv   { background:#ecfdf5; border-color:#6ee7b7; color:#065f46; }
    .exp-excel { background:#eff6ff; border-color:#93c5fd; color:#1d4ed8; }
    .exp-pdf   { background:#fef2f2; border-color:#fca5a5; color:#b91c1c; }
    .exp-print { background:#f5f3ff; border-color:#c4b5fd; color:#6d28d9; }
    .exp-btn:hover { transform: translateY(-1px); box-shadow: 0 2px 8px rgba(0,0,0,.1); }
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
                <h2>Inquiries</h2>
                <p>Manage walk-in, telephonic &amp; online inquiries</p>
            </div>
        </div>
        <div class="header-right">
            <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
            <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">

        <?php if ($pageMode !== 'add'): ?>
        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card c-brand">
                <div class="kpi-top"><span class="kpi-label">Total (Active)</span><span class="kpi-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg></span></div>
                <div class="kpi-value"><?= $statusCounts['all'] ?></div>
            </div>
            <div class="kpi-card c-sky">
                <div class="kpi-top"><span class="kpi-label">New</span><span class="kpi-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></span></div>
                <div class="kpi-value"><?= $statusCounts['New'] ?></div>
            </div>
            <div class="kpi-card c-amber">
                <div class="kpi-top"><span class="kpi-label">Contacted</span><span class="kpi-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span></div>
                <div class="kpi-value"><?= $statusCounts['Contacted'] ?></div>
            </div>
            <div class="kpi-card c-emerald">
                <div class="kpi-top"><span class="kpi-label">Converted</span><span class="kpi-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></span></div>
                <div class="kpi-value"><?= $statusCounts['Converted'] ?></div>
            </div>
            <div class="kpi-card c-violet">
                <div class="kpi-top"><span class="kpi-label">Closed</span><span class="kpi-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></span></div>
                <div class="kpi-value"><?= $statusCounts['Closed'] ?></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($pageMode !== 'add'): ?>
        <!-- Status Tabs -->
        <div class="inq-status-tabs">
            <a href="?status=all" class="inq-status-tab <?= $statusFilter==='all'?'active':'' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                All Inquiries <span class="tab-count"><?= $statusCounts['all'] ?></span>
            </a>
            <a href="?status=New" class="inq-status-tab <?= $statusFilter==='New'?'active':'' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                New <span class="tab-count cnt-new"><?= $statusCounts['New'] ?></span>
            </a>
            <a href="?status=Contacted" class="inq-status-tab <?= $statusFilter==='Contacted'?'active':'' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                Contacted <span class="tab-count cnt-contacted"><?= $statusCounts['Contacted'] ?></span>
            </a>
            <a href="?status=Converted" class="inq-status-tab <?= $statusFilter==='Converted'?'active':'' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                Converted <span class="tab-count cnt-converted"><?= $statusCounts['Converted'] ?></span>
            </a>
            <a href="?status=Closed" class="inq-status-tab <?= $statusFilter==='Closed'?'active':'' ?>">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                Closed <span class="tab-count cnt-not-interested"><?= $statusCounts['Closed'] ?></span>
            </a>
        </div>
        <?php endif; ?>

        <?php if ($pageMode !== 'add'): ?>
        <!-- Toolbar -->
        <div class="inq-toolbar">
            <div class="inq-search-box">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                <input type="text" id="searchInput" placeholder="Search by name, mobile, course…">
            </div>
            <select class="inq-filter-select" id="typeFilter">
                <option value="">All Types</option>
                <option value="Walk-in">Walk-in</option>
                <option value="Telephonic">Telephonic</option>
                <option value="Online">Online</option>
                <option value="Reference">Reference</option>
            </select>
            <!-- Export buttons -->
            <div class="export-btn-group">
                <button class="exp-btn exp-copy"  onclick="exportCopy()"  title="Copy">📋 Copy</button>
                <button class="exp-btn exp-csv"   onclick="exportCSV()"   title="CSV">📄 CSV</button>
                <button class="exp-btn exp-excel" onclick="exportExcel()" title="Excel">📊 Excel</button>
                <button class="exp-btn exp-pdf"   onclick="exportPDF()"   title="PDF">📑 PDF</button>
                <button class="exp-btn exp-print" onclick="printInquiries()" title="Print">🖨️ Print</button>
            </div>
            <a class="inq-btn inq-btn-primary" href="?mode=add">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Inquiry
            </a>
        </div>
        <?php else: ?>
        <div class="inq-add-topbar">
            <div>
                <div class="inq-add-title">New Inquiry Form</div>
                <div class="inq-add-subtitle">Fill the details below and submit</div>
            </div>
            <a class="inq-btn inq-btn-secondary" href="inquiries.php">
                Back to Inquiry List
            </a>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
        <div style="margin-bottom:1rem;padding:.9rem 1rem;border-radius:10px;background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;font-weight:600;">
            Inquiry added successfully.
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['error']) && $_GET['error'] === 'save'): ?>
        <div style="margin-bottom:1rem;padding:.9rem 1rem;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-weight:600;">
            Unable to save inquiry. Please try again.
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['error']) && $_GET['error'] === 'pin'): ?>
        <div style="margin-bottom:1rem;padding:.9rem 1rem;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-weight:600;">
            PIN Code is required.
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['error']) && $_GET['error'] === 'dob'): ?>
        <div style="margin-bottom:1rem;padding:.9rem 1rem;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-weight:600;">
            Date of Birth is required.
        </div>
        <?php endif; ?>

        <?php if ($pageMode === 'add'): ?>
        <div class="table-card" style="margin-bottom:1rem;">
            <form method="POST" class="profile-form" id="inquiryPageForm" style="padding:1rem 1.25rem 1.25rem;">
                <input type="hidden" name="action" value="add_page">

                <div class="form-section">
                    <div class="form-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Student Information
                    </div>
                    <div class="profile-form-grid">
                        <div class="form-field"><label>First Name <span class="required">*</span></label><input required type="text" name="first_name" maxlength="60" autocomplete="off"></div>
                        <div class="form-field"><label>Middle Name</label><input type="text" name="middle_name" maxlength="60" autocomplete="off"></div>
                        <div class="form-field"><label>Last Name <span class="required">*</span></label><input required type="text" name="last_name" maxlength="60" autocomplete="off"></div>
                        <div class="form-field"><label>Gender</label><select name="gender"><option>Male</option><option>Female</option><option>Other</option></select></div>
                        <div class="form-field"><label>Date of Birth <span class="required">*</span></label><input required type="date" name="dob"></div>
                        <div class="form-field"><label>Qualification</label><input type="text" name="qualification"></div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72"/></svg>
                        Contact & Course
                    </div>
                    <div class="profile-form-grid">
                        <div class="form-field"><label>Mobile <span class="required">*</span></label><input required type="tel" pattern="[0-9]{10}" maxlength="10" name="mobile"></div>
                        <div class="form-field"><label>Phone</label><input type="tel" name="phone"></div>
                        <div class="form-field"><label>Email</label><input type="email" name="email"></div>
                        <div class="form-field"><label>Inquiry Type</label><select name="inquiry_type"><option>Walk-in</option><option>Telephonic</option><option>Online</option><option>Reference</option></select></div>
                        <div class="form-field"><label>Interested Course <span class="required">*</span></label><select name="interested_course" id="page_interested_course" onchange="updatePageCourseFees()" required><option value="">Select Course</option><?php foreach ($activeCourses as $course): ?><option value="<?= sanitize($course['course_name']) ?>"><?= sanitize($course['course_name']) ?></option><?php endforeach; ?></select></div>
                        <div class="form-field"><label>Inquiry Date</label><input type="date" name="inquiry_date" value="<?= date('Y-m-d') ?>"></div>
                        <div class="form-field"><label>Course Fees (₹)</label><input type="number" min="0" step="0.01" id="page_course_fees" name="course_fees" value="0"></div>
                        <div class="form-field"><label>Quoted Fees (₹)</label><input type="number" min="0" step="0.01" id="page_quoted_fees" name="quoted_fees" value="0"></div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="form-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/></svg>
                        Address & Follow-up
                    </div>
                    <div class="profile-form-grid">
                        <div class="form-field full-width"><label>Address</label><input type="text" name="address"></div>
                        <div class="form-field"><label>City</label><input type="text" name="city"></div>
                        <div class="form-field"><label>PIN Code <span class="required">*</span></label><input required type="text" name="pin_code" maxlength="10"></div>
                        <div class="form-field"><label>Next Follow-up Date <span class="required">*</span></label><input required type="date" name="next_inform_date" value="<?= date('Y-m-d', strtotime('+1 day')) ?>"></div>
                        <div class="form-field"><label>Follow-up Time <span class="required">*</span></label><input required type="time" name="next_inform_time" value="10:00"></div>
                        <div class="form-field"><label>Referenced By</label><input type="text" name="referenced_by"></div>
                        <div class="form-field"><label>Status</label><select name="status"><option value="New" selected>New</option><option value="Contacted">Contacted</option><option value="Converted">Converted</option><option value="Closed">Closed</option></select></div>
                        <div class="form-field full-width"><label>Comment / Notes</label><textarea name="comment" rows="3"></textarea></div>
                    </div>
                </div>

                <div class="form-actions" style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:.75rem;">
                    <a href="inquiries.php" class="btn-secondary" style="text-decoration:none;display:inline-flex;align-items:center;">Cancel</a>
                    <button type="submit" class="btn-primary" style="display:inline-flex;align-items:center;gap:.45rem;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><polyline points="20 6 9 17 4 12"/></svg>
                        Save Inquiry
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($pageMode !== 'add'): ?>
        <!-- Table -->
        <div class="inq-table-wrap">
            <table class="data-table" id="inquiryTable">
                <thead>
                    <tr>
                        <th>Student</th>
                        <th>Contact</th>
                        <th>Course Interest</th>
                        <th>Type</th>
                        <th>Follow-up</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($inquiries)): ?>
                    <tr><td colspan="7">
                        <div class="inq-empty">
                            <div class="inq-empty-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg></div>
                            <div class="inq-empty-title">No inquiries found</div>
                            <div class="inq-empty-desc">Click "Add Inquiry" to register your first student inquiry.</div>
                        </div>
                    </td></tr>
                <?php else: foreach ($inquiries as $inq):
                    $name = fullName($inq);
                    $initial = strtoupper(mb_substr($inq['first_name'], 0, 1));
                    $typeClass = ['Walk-in'=>'s-new','Telephonic'=>'s-contacted','Online'=>'s-interested','Reference'=>'s-converted'];
                    $statusClass = ['New'=>'s-new','Contacted'=>'s-contacted','Converted'=>'s-converted','Closed'=>'s-not-interested'];
                    $tc = $typeClass[$inq['inquiry_type']] ?? 's-new';
                    $sc = $statusClass[$inq['status']] ?? 's-new';
                    $fup = $inq['next_inform_date'];
                    $fupTs = $fup ? strtotime($fup) : 0;
                    $todayTs = strtotime('today');
                ?>
                    <tr data-type="<?= sanitize($inq['inquiry_type']) ?>">
                        <td>
                            <div class="inquiry-cell">
                                <div class="inquiry-avatar"><?= $initial ?></div>
                                <div>
                                    <div class="inquiry-name"><?= sanitize($name) ?></div>
                                    <div class="inquiry-type"><?= sanitize($inq['inquiry_type']) ?> · <?= date('d M Y', strtotime($inq['created_at'])) ?></div>
                                </div>
                            </div>
                        </td>
                        <td>
                            <div class="cell-main"><?= sanitize($inq['mobile']) ?></div>
                            <?php if ($inq['email']): ?><div class="cell-sub"><?= sanitize($inq['email']) ?></div><?php endif; ?>
                        </td>
                        <td><div class="cell-main"><?= sanitize($inq['interested_course']) ?></div></td>
                        <td>
                            <span class="inq-badge <?= $tc ?>">
                                <span class="inq-badge-dot"></span><?= sanitize($inq['inquiry_type']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($fup): ?>
                                <div class="cell-main"><?= date('d M Y', $fupTs) ?></div>
                                <?php if ($fupTs < $todayTs): ?>
                                    <div class="cell-sub" style="color:var(--danger-500)">Overdue</div>
                                <?php elseif ($fupTs === $todayTs): ?>
                                    <div class="cell-sub" style="color:var(--warning-500)">Today</div>
                                <?php else: ?>
                                    <div class="cell-sub">Upcoming</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="cell-sub">Not set</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="inq-badge <?= $sc ?>">
                                <span class="inq-badge-dot"></span><?= sanitize($inq['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="inq-actions">
                                <button class="inq-action-btn edit" title="View" onclick="viewInquiry(<?= $inq['id'] ?>)">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>

                                <button class="inq-action-btn delete" title="Delete" onclick="confirmDelete(<?= $inq['id'] ?>, '<?= addslashes(sanitize($name)) ?>')">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <?= renderPagination($pager, 'inquiries') ?>
        <?php endif; ?>

    </div><!-- /.page-content -->
</main>
</div>

<!-- ADD / EDIT MODAL -->
<div class="modal-overlay inq-modal" id="inquiryModal">
    <div class="modal-card" style="max-width:780px">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="inq-modal-header-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                </div>
                <div>
                    <div class="inq-modal-title" id="modalTitle">Add Inquiry</div>
                    <div class="inq-modal-subtitle" id="modalSubtitle">Fill in the student inquiry details</div>
                </div>
            </div>
            <button type="button" class="modal-close" onclick="closeModal()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <form id="inquiryForm" novalidate>
            <input type="hidden" id="inquiryId" name="id">
            <input type="hidden" id="formAction" name="action" value="add">
            <div class="modal-body">

                <!-- ── Section 1: Personal Details ── -->
                <div class="inq-section">
                    <div class="inq-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        Personal Details
                    </div>
                    <div class="inq-form-grid">
                        <div class="inq-form-group">
                            <label class="inq-form-label">First Name <span class="req">*</span></label>
                            <input type="text" class="inq-form-input" id="first_name" name="first_name" required maxlength="60" placeholder="First name" autocomplete="off">
                        </div>
                        <div class="inq-form-group">
                            <label class="inq-form-label">Middle Name</label>
                            <input type="text" class="inq-form-input" id="middle_name" name="middle_name" maxlength="60" placeholder="Optional" autocomplete="off">
                        </div>
                        <div class="inq-form-group">
                            <label class="inq-form-label">Last Name <span class="req">*</span></label>
                            <input type="text" class="inq-form-input" id="last_name" name="last_name" required maxlength="60" placeholder="Last name" autocomplete="off">
                        </div>
                        <div class="inq-form-group">
                            <label class="inq-form-label">Date of Birth <span class="req">*</span></label>
                            <input type="date" class="inq-form-input" id="dob" name="dob" required>
                            <span class="inq-field-hint">Required for birthday reminders</span>
                        </div>
                        <div class="inq-form-group">
                            <label class="inq-form-label">Gender <span class="req">*</span></label>
                            <select class="inq-form-select" id="gender" name="gender">
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="inq-form-group">
                            <label class="inq-form-label">Mobile <span class="req">*</span></label>
                            <input type="tel" class="inq-form-input" id="mobile" name="mobile" required maxlength="15" placeholder="9876543210" autocomplete="off">
                        </div>
                        <div class="inq-form-group">
                            <label class="inq-form-label">Alt. Phone</label>
                            <input type="tel" class="inq-form-input" id="phone" name="phone" maxlength="15" placeholder="Optional" autocomplete="off">
                        </div>
                        <div class="inq-form-group">
                            <label class="inq-form-label">Email</label>
                            <input type="email" class="inq-form-input" id="email" name="email" maxlength="100" placeholder="student@example.com" autocomplete="off">
                        </div>
                        <div class="inq-form-group">
                            <label class="inq-form-label">Qualification</label>
                            <input type="text" class="inq-form-input" id="qualification" name="qualification" maxlength="80" placeholder="e.g., 12th, B.Sc." autocomplete="off">
                        </div>
                    </div>
                </div>

                <!-- ── Section 2: Address Details ── -->
                <div class="inq-section">
                    <div class="inq-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        Address Details
                    </div>
                    <div class="inq-form-grid">
                        <div class="inq-form-group full">
                            <label class="inq-form-label">Address</label>
                            <input type="text" class="inq-form-input" id="address" name="address" maxlength="255" placeholder="Street / Area" autocomplete="off">
                        </div>
                        <div class="inq-form-group">
                            <label class="inq-form-label">City</label>
                            <input type="text" class="inq-form-input" id="city" name="city" maxlength="80" placeholder="City" autocomplete="off">
                        </div>
                        <div class="inq-form-group">
                            <label class="inq-form-label">PIN Code <span class="req">*</span></label>
                            <input type="text" class="inq-form-input" id="pin_code" name="pin_code"
                                   maxlength="10" pattern="[0-9]{6}" placeholder="e.g. 411001"
                                   required autocomplete="off"
                                   title="Enter a valid 6-digit PIN code">
                        </div>
                    </div>
                </div>

                <!-- ── Section 3: Course Interest ── -->
                <div class="inq-section">
                    <div class="inq-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                        Course Interest
                    </div>
                    <div class="inq-form-grid">
                        <div class="inq-form-group">
                            <label class="inq-form-label">Interested Course <span class="req">*</span></label>
                            <select class="inq-form-select" id="interested_course" name="interested_course" required onchange="updateCourseFees()">
                                <option value="">-- Select Course --</option>
                                <?php foreach ($activeCourses as $c): ?>
                                    <option value="<?= htmlspecialchars($c['course_name']) ?>"><?= htmlspecialchars($c['course_name']) ?></option>
                                <?php endforeach; ?>
                                <option value="Custom">Other / Custom</option>
                            </select>
                            <input type="text" class="inq-form-input" id="custom_course" name="custom_course" maxlength="100" placeholder="Type custom course" style="display:none; margin-top:0.4rem;" autocomplete="off">
                        </div>
                        <div class="inq-form-group">
                            <label class="inq-form-label">Inquiry Type</label>
                            <select class="inq-form-select" id="inquiry_type" name="inquiry_type">
                                <option value="Walk-in">Walk-in</option>
                                <option value="Telephonic">Telephonic</option>
                                <option value="Online">Online</option>
                                <option value="Reference">Reference</option>
                            </select>
                        </div>
                        <div class="inq-form-group">
                            <label class="inq-form-label">Course Fees (₹)</label>
                            <input type="number" class="inq-form-input" id="course_fees" name="course_fees" min="0" step="0.01" placeholder="0.00">
                        </div>
                        <div class="inq-form-group">
                            <label class="inq-form-label">Quoted Fees (₹)</label>
                            <input type="number" class="inq-form-input" id="quoted_fees" name="quoted_fees" min="0" step="0.01" placeholder="0.00">
                        </div>
                        <div class="inq-form-group">
                            <label class="inq-form-label">Inquiry Date</label>
                            <input type="date" class="inq-form-input" id="inquiry_date" name="inquiry_date">
                        </div>
                    </div>
                </div>

                <!-- ── Section 4: Follow-up & Notes ── -->
                <div class="inq-section" style="margin-bottom:0">
                    <div class="inq-section-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Follow-up &amp; Notes
                    </div>
                    <div class="inq-form-grid">
                        <div class="inq-form-group">
                            <label class="inq-form-label">Next Follow-up Date <span class="req">*</span></label>
                            <input type="date" class="inq-form-input" id="next_inform_date" name="next_inform_date" required>
                        </div>
                        <div class="inq-form-group">
                            <label class="inq-form-label">Follow-up Time <span class="req">*</span></label>
                            <input type="time" class="inq-form-input" id="next_inform_time" name="next_inform_time" required>
                        </div>
                        <div class="inq-form-group">
                            <label class="inq-form-label">Referenced By</label>
                            <input type="text" class="inq-form-input" id="referenced_by" name="referenced_by" maxlength="100" placeholder="Name of referrer" autocomplete="off">
                        </div>
                        <div class="inq-form-group">
                            <label class="inq-form-label">Status</label>
                            <select class="inq-form-select" id="status" name="status">
                                <option value="New">New</option>
                                <option value="Contacted">Contacted</option>
                                <option value="Converted">Converted</option>
                                <option value="Closed">Closed</option>
                            </select>
                        </div>
                        <div class="inq-form-group full">
                            <label class="inq-form-label">Comment / Notes</label>
                            <textarea class="inq-form-textarea" id="comment" name="comment" rows="3" placeholder="Any notes about this inquiry…"></textarea>
                        </div>
                    </div>
                </div>

            </div>
            <div class="modal-footer">
                <button type="button" class="inq-btn inq-btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="inq-btn inq-btn-primary" id="submitBtn">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><polyline points="20 6 9 17 4 12"/></svg>
                    <span id="submitBtnText">Add Inquiry</span>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- VIEW MODAL -->
<div class="modal-overlay inq-modal" id="viewModal">
    <div class="modal-card" style="max-width:640px">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="inq-modal-header-icon" style="background:linear-gradient(135deg,#0ea5e9,#6366f1)">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </div>
                <div>
                    <div class="inq-modal-title">Inquiry Details</div>
                    <div class="inq-modal-subtitle">Complete information about this student</div>
                </div>
            </div>
            <button type="button" class="modal-close" onclick="closeViewModal()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body" id="viewContent"></div>
        <div class="modal-footer">
            <button type="button" class="inq-btn inq-btn-secondary" onclick="closeViewModal()">Close</button>
        </div>
    </div>
</div>

<!-- DELETE MODAL -->
<div class="modal-overlay inq-delete-modal" id="deleteModal">
    <div class="modal-card">
        <div class="modal-body" style="padding:2.5rem 2rem;text-align:center">
            <div class="inq-delete-icon">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
            </div>
            <div class="inq-delete-title">Delete Inquiry?</div>
            <div class="inq-delete-desc" id="deleteDesc">This action cannot be undone.</div>
            <div class="inq-delete-actions">
                <button class="inq-btn inq-btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                <button class="inq-btn inq-btn-danger" id="confirmDeleteBtn" onclick="executeDelete()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                    Delete
                </button>
            </div>
        </div>
    </div>
</div>

<!-- TOAST -->
<div class="inq-toast" id="toast">
    <div class="inq-toast-icon" id="toastIcon">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
    </div>
    <div class="inq-toast-msg" id="toastMsg"></div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
let deleteTargetId = null;

/* ── Search & Type Filter ── */
const searchInputEl = document.getElementById('searchInput');
const typeFilterEl = document.getElementById('typeFilter');
if (searchInputEl) searchInputEl.addEventListener('input', filterTable);
if (typeFilterEl) typeFilterEl.addEventListener('change', filterTable);

function filterTable() {
    const tableBodyRows = document.querySelectorAll('#inquiryTable tbody tr');
    if (!tableBodyRows.length || !searchInputEl || !typeFilterEl) return;

    const term = searchInputEl.value.toLowerCase();
    const type = typeFilterEl.value;
    tableBodyRows.forEach(row => {
        const textMatch = !term || row.textContent.toLowerCase().includes(term);
        const typeMatch = !type || (row.dataset.type || '') === type;
        row.style.display = (textMatch && typeMatch) ? '' : 'none';
    });
}

/* ── Open Add Modal ── */
function openAddModal() {
    document.getElementById('modalTitle').textContent    = 'Add Inquiry';
    document.getElementById('modalSubtitle').textContent = 'Fill in the student inquiry details';
    document.getElementById('submitBtnText').textContent = 'Add Inquiry';
    document.getElementById('formAction').value = 'add';
    document.getElementById('inquiryForm').reset();
    document.getElementById('inquiryId').value = '';
    document.getElementById('status').value       = 'New';
    document.getElementById('inquiry_type').value = 'Walk-in';
    document.getElementById('gender').value       = 'Male';
    document.getElementById('inquiry_date').value    = new Date().toISOString().split('T')[0];
    const tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate()+1);
    document.getElementById('next_inform_date').value = tomorrow.toISOString().split('T')[0];
    document.getElementById('next_inform_time').value = '10:00';
    
    // Reset custom course field
    document.getElementById('interested_course').value = '';
    document.getElementById('custom_course').style.display = 'none';
    document.getElementById('custom_course').required = false;
    document.getElementById('custom_course').name = 'custom_course'; 
    document.getElementById('interested_course').name = 'interested_course';
    
    document.getElementById('course_fees').value = '';
    document.getElementById('quoted_fees').value = '';
    
    document.getElementById('inquiryModal').classList.add('active');
    setTimeout(() => document.getElementById('first_name').focus(), 120);
}

/* ── Update Course Fees Automatically ── */
const courseFeesMap = <?= $courseFeesJSON ?>;

function updatePageCourseFees() {
    const selector = document.getElementById('page_interested_course');
    const feeInput = document.getElementById('page_course_fees');
    const quotedInput = document.getElementById('page_quoted_fees');
    if (!selector || !feeInput || !quotedInput) return;

    const course = selector.value;
    if (courseFeesMap[course] !== undefined) {
        feeInput.value = courseFeesMap[course];
        if (!quotedInput.value || quotedInput.value === '0' || quotedInput.value === '0.00') {
            quotedInput.value = courseFeesMap[course];
        }
    }
}

function updateCourseFees() {
    const selector = document.getElementById('interested_course');
    const customField = document.getElementById('custom_course');
    const course = selector.value;
    const feeInput = document.getElementById('course_fees');
    const quotedInput = document.getElementById('quoted_fees');

    if (course === "Custom") {
        customField.style.display = 'block';
        customField.required = true;
        customField.name = 'interested_course'; // Submit this input's value for the column
        selector.name = 'ignore_course'; // Ignore the selector value on submit
        feeInput.value = '';
        quotedInput.value = '';
        setTimeout(() => customField.focus(), 50);
    } else {
        customField.style.display = 'none';
        customField.required = false;
        customField.name = 'custom_course';
        selector.name = 'interested_course';

        if (courseFeesMap[course] !== undefined) {
            feeInput.value = courseFeesMap[course];
            if (!quotedInput.value) { // Auto-fill quoted fees only if it's empty
                quotedInput.value = courseFeesMap[course];
            }
        }
    }
}

/* ── Edit ── */
async function editInquiry(id) {
    try {
        const fd = new FormData(); fd.append('action','get'); fd.append('id',id);
        const res = await fetch('', {method:'POST', body:fd});
        const data = await res.json();
        if (data.success && data.data) {
            const q = data.data;
            document.getElementById('modalTitle').textContent    = 'Edit Inquiry';
            document.getElementById('modalSubtitle').textContent = 'Update the inquiry details';
            document.getElementById('submitBtnText').textContent = 'Update Inquiry';
            document.getElementById('formAction').value  = 'edit';
            document.getElementById('inquiryId').value   = q.id;
            document.getElementById('first_name').value  = q.first_name || '';
            document.getElementById('middle_name').value = q.middle_name || '';
            document.getElementById('last_name').value   = q.last_name || '';
            document.getElementById('mobile').value      = q.mobile || '';
            document.getElementById('phone').value       = q.phone || '';
            document.getElementById('email').value       = q.email || '';
            document.getElementById('gender').value      = q.gender || 'Male';
            document.getElementById('dob').value         = q.dob || '';
            document.getElementById('qualification').value = q.qualification || '';
            
            // Handle interested_course mapping
            const selector = document.getElementById('interested_course');
            const customField = document.getElementById('custom_course');
            let isStandardCourse = false;
            if (q.interested_course) {
                for(let i = 0; i < selector.options.length; i++) {
                    if(selector.options[i].value === q.interested_course && q.interested_course !== "Custom") {
                        isStandardCourse = true; break;
                    }
                }
            }
            
            if(isStandardCourse) {
                selector.value = q.interested_course;
                customField.style.display = 'none';
                customField.required = false;
                customField.name = 'custom_course';
                selector.name = 'interested_course';
            } else if (q.interested_course) {
                selector.value = "Custom";
                customField.style.display = 'block';
                customField.value = q.interested_course;
                customField.required = true;
                customField.name = 'interested_course';
                selector.name = 'ignore_course';
            } else {
                // If totally empty
                selector.value = "";
                customField.style.display = 'none';
                customField.required = false;
            }
            
            document.getElementById('inquiry_type').value = q.inquiry_type || 'Walk-in';
            document.getElementById('inquiry_date').value = q.inquiry_date || '';
            document.getElementById('course_fees').value = q.course_fees || '';
            document.getElementById('quoted_fees').value = q.quoted_fees || '';
            document.getElementById('address').value     = q.address || '';
            document.getElementById('pin_code').value    = q.pin_code || '';
            document.getElementById('city').value        = q.city || '';
            document.getElementById('next_inform_date').value = q.next_inform_date || '';
            document.getElementById('next_inform_time').value = q.next_inform_time ? q.next_inform_time.substring(0,5) : '';
            document.getElementById('referenced_by').value = q.referenced_by || '';
            document.getElementById('comment').value     = q.comment || '';
            document.getElementById('status').value      = q.status || 'New';
            document.getElementById('inquiryModal').classList.add('active');
            setTimeout(() => document.getElementById('first_name').focus(), 120);
        }
    } catch(e) { showToast('Error loading inquiry data','error'); }
}

/* ── View ── */
async function viewInquiry(id) {
    try {
        const fd = new FormData(); fd.append('action','get'); fd.append('id',id);
        const res = await fetch('', {method:'POST', body:fd});
        const data = await res.json();
        if (data.success && data.data) {
            const q = data.data;
            const bm = {New:'s-new',Contacted:'s-contacted',Converted:'s-converted',Closed:'s-not-interested'};
            const tm = {'Walk-in':'s-new',Telephonic:'s-contacted',Online:'s-interested',Reference:'s-converted'};
            const fmt = d => d ? new Date(d).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—';
            const fullName = [q.first_name, q.middle_name, q.last_name].filter(Boolean).join(' ');
            document.getElementById('viewContent').innerHTML = `
                <div class="inq-view-grid">
                    <div class="inq-view-row header-row">
                        <div class="inq-view-avatar">${q.first_name ? q.first_name[0].toUpperCase() : '?'}</div>
                        <div>
                            <div style="font-size:1.1rem;font-weight:800;color:var(--text-primary)">${fullName}</div>
                            <div style="font-size:.8rem;color:var(--text-muted);margin-top:.2rem">${q.qualification || ''} ${q.gender ? '· '+q.gender : ''}</div>
                        </div>
                        <span class="inq-badge ${bm[q.status]||'s-new'}" style="margin-left:auto"><span class="inq-badge-dot"></span>${q.status}</span>
                    </div>
                    <div class="inq-view-section">
                        <div class="inq-view-label">Contact</div>
                        <div class="inq-view-cols">
                            <div><div class="ivl">Mobile</div><div class="ivv">${q.mobile||'—'}</div></div>
                            <div><div class="ivl">Phone</div><div class="ivv">${q.phone||'—'}</div></div>
                            <div><div class="ivl">Email</div><div class="ivv">${q.email||'—'}</div></div>
                            <div><div class="ivl">Date of Birth</div><div class="ivv">${fmt(q.dob)}</div></div>
                        </div>
                    </div>
                    <div class="inq-view-section">
                        <div class="inq-view-label">Course</div>
                        <div class="inq-view-cols">
                            <div><div class="ivl">Course Interest</div><div class="ivv">${q.interested_course||'—'}</div></div>
                            <div><div class="ivl">Inquiry Type</div><div class="ivv"><span class="inq-badge ${tm[q.inquiry_type]||'s-new'}"><span class="inq-badge-dot"></span>${q.inquiry_type}</span></div></div>
                            <div><div class="ivl">Course Fees</div><div class="ivv">₹${parseFloat(q.course_fees||0).toLocaleString()}</div></div>
                            <div><div class="ivl">Quoted Fees</div><div class="ivv">₹${parseFloat(q.quoted_fees||0).toLocaleString()}</div></div>
                        </div>
                    </div>
                    <div class="inq-view-section">
                        <div class="inq-view-label">Follow-up</div>
                        <div class="inq-view-cols">
                            <div><div class="ivl">Date</div><div class="ivv">${fmt(q.next_inform_date)}</div></div>
                            <div><div class="ivl">Time</div><div class="ivv">${q.next_inform_time ? q.next_inform_time.substring(0,5) : '—'}</div></div>
                            <div><div class="ivl">Referenced By</div><div class="ivv">${q.referenced_by||'—'}</div></div>
                            <div><div class="ivl">Inquiry Date</div><div class="ivv">${fmt(q.inquiry_date)}</div></div>
                        </div>
                    </div>
                    <div class="inq-view-section">
                        <div class="inq-view-label">Address</div>
                        <div style="font-size:.875rem;color:var(--text-primary);font-weight:500;margin-top:.5rem">${[q.address,q.city,q.pin_code].filter(Boolean).join(', ')||'—'}</div>
                    </div>
                    <div class="inq-view-section">
                        <div class="inq-view-label">Comment</div>
                        <div style="font-size:.875rem;color:var(--text-secondary);line-height:1.6;margin-top:.5rem">${q.comment||'<span style="color:var(--text-muted)">No comment</span>'}</div>
                    </div>
                </div>`;
            document.getElementById('viewModal').classList.add('active');
        }
    } catch(e) { showToast('Error loading details','error'); }
}

/* ── Delete ── */
function confirmDelete(id, name) {
    deleteTargetId = id;
    document.getElementById('deleteDesc').textContent = `Delete the inquiry for "${name}"? This cannot be undone.`;
    document.getElementById('deleteModal').classList.add('active');
}
async function executeDelete() {
    if (!deleteTargetId) return;
    const btn = document.getElementById('confirmDeleteBtn');
    btn.disabled = true; btn.textContent = 'Deleting…';
    try {
        const fd = new FormData(); fd.append('action','delete'); fd.append('id',deleteTargetId);
        const res = await fetch('', {method:'POST', body:fd});
        const data = await res.json();
        if (data.success) { closeDeleteModal(); showToast('Inquiry deleted','success'); setTimeout(()=>location.reload(),800); }
        else { showToast(data.message||'Error','error'); btn.disabled=false; btn.textContent='Delete'; }
    } catch(e) { showToast('Error','error'); btn.disabled=false; btn.textContent='Delete'; }
}

/* ── Modal helpers ── */
function closeModal()       { document.getElementById('inquiryModal').classList.remove('active'); }
function closeViewModal()   { document.getElementById('viewModal').classList.remove('active'); }
function closeDeleteModal() {
    document.getElementById('deleteModal').classList.remove('active');
    deleteTargetId = null;
    const btn = document.getElementById('confirmDeleteBtn');
    btn.disabled = false; btn.textContent = 'Delete';
}
['inquiryModal','viewModal','deleteModal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) { if(id==='inquiryModal') closeModal(); else if(id==='viewModal') closeViewModal(); else closeDeleteModal(); }
    });
});
document.addEventListener('keydown', e => { if(e.key==='Escape'){closeModal();closeViewModal();closeDeleteModal();} });

/* ── Form submit ── */
document.getElementById('inquiryForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn  = document.getElementById('submitBtn');
    const text = document.getElementById('submitBtnText');
    const orig = text.textContent;
    btn.disabled = true; text.textContent = 'Saving…';
    try {
        const res  = await fetch('', {method:'POST', body:new FormData(this)});
        const data = await res.json();
        if (data.success) {
            closeModal();
            showToast(document.getElementById('formAction').value==='add' ? 'Inquiry added!' : 'Inquiry updated!', 'success');
            setTimeout(()=>location.reload(), 800);
        } else { showToast(data.message||'Error saving','error'); btn.disabled=false; text.textContent=orig; }
    } catch(e) { showToast('Error saving inquiry','error'); btn.disabled=false; text.textContent=orig; }
});

/* ── Toast ── */
function showToast(msg, type='success') {
    const toast = document.getElementById('toast');
    const icon  = document.getElementById('toastIcon');
    document.getElementById('toastMsg').textContent = msg;
    toast.className = 'inq-toast show ' + type;
    icon.innerHTML = type==='success'
        ? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>'
        : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
    clearTimeout(window._tt);
    window._tt = setTimeout(() => toast.classList.remove('show'), 3500);
}
</script>

<!-- View modal extra styles -->
<style>
.inq-view-grid { display:flex; flex-direction:column; gap:1.25rem; }
.inq-view-row.header-row { display:flex; align-items:center; gap:1rem; padding:.75rem 1rem; background:linear-gradient(135deg,var(--primary-50),#f5f3ff); border-radius:var(--radius-lg); }
.inq-view-avatar { width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,var(--primary-400),#8b5cf6);display:flex;align-items:center;justify-content:center;font-size:1.2rem;font-weight:800;color:#fff;flex-shrink:0; }
.inq-view-section { padding:.875rem 1rem; background:var(--gray-50); border-radius:var(--radius-lg); border:1px solid var(--border-color); }
.inq-view-label { font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);margin-bottom:.5rem; }
.inq-view-cols { display:grid;grid-template-columns:repeat(2,1fr);gap:.875rem; }
.ivl { font-size:.72rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em; }
.ivv { font-size:.9rem;font-weight:600;color:var(--text-primary);margin-top:.2rem; }
@media(max-width:520px){ .inq-view-cols{grid-template-columns:1fr;} }
</style>

<script>
// ── Export helpers (Inquiries) ────────────────────────────────────────────
function getInqTableData() {
    const headers = ['Name','Type','Mobile','Email','Course Interest','Follow-up Date','Status'];
    const rows = [];
    document.querySelectorAll('#inquiryTable tbody tr').forEach(tr => {
        if (tr.style.display === 'none') return; // respect client-side filter
        const tds = tr.querySelectorAll('td');
        if (!tds.length || tds[0].getAttribute('colspan')) return;
        rows.push([
            tds[0]?.querySelector('.inquiry-name')?.textContent?.trim() || '',
            tds[0]?.querySelector('.inquiry-type')?.textContent?.split('·')[0]?.trim() || '',
            tds[1]?.querySelector('.cell-main')?.textContent?.trim() || '',
            tds[1]?.querySelector('.cell-sub')?.textContent?.trim()  || '',
            tds[2]?.querySelector('.cell-main')?.textContent?.trim() || '',
            tds[4]?.querySelector('.cell-main')?.textContent?.trim() || '',
            tds[5]?.querySelector('.inq-badge')?.textContent?.trim() || '',
        ]);
    });
    return { headers, rows };
}
function exportCopy() {
    const { headers, rows } = getInqTableData();
    if (!rows.length) { alert('No data to copy.'); return; }
    navigator.clipboard.writeText([headers, ...rows].map(r => r.join('\t')).join('\n'))
        .then(() => alert('✅ Copied ' + rows.length + ' inquiries to clipboard!'));
}
function exportCSV() {
    const { headers, rows } = getInqTableData();
    if (!rows.length) { alert('No data to export.'); return; }
    const csv = [headers, ...rows].map(r => r.map(c => '"'+String(c).replace(/"/g,'""')+'"').join(',')).join('\n');
    Object.assign(document.createElement('a'), { href: 'data:text/csv;charset=utf-8,'+encodeURIComponent(csv), download: 'inquiries_'+Date.now()+'.csv' }).click();
}
function exportExcel() {
    const { headers, rows } = getInqTableData();
    if (!rows.length) { alert('No data to export.'); return; }
    const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Inquiries');
    XLSX.writeFile(wb, 'inquiries_'+Date.now()+'.xlsx');
}
function exportPDF() {
    const { headers, rows } = getInqTableData();
    if (!rows.length) { alert('No data to export.'); return; }
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape' });
    doc.setFontSize(13); doc.text('Inquiries — Gyanam India', 14, 14);
    doc.setFontSize(9);  doc.text('Generated: ' + new Date().toLocaleString('en-IN'), 14, 21);
    doc.autoTable({ head: [headers], body: rows, startY: 26, styles: { fontSize: 8 }, headStyles: { fillColor: [67,97,238] } });
    doc.save('inquiries_'+Date.now()+'.pdf');
}
function printInquiries() {
    const { headers, rows } = getInqTableData();
    if (!rows.length) { alert('No data to print.'); return; }
    const now = new Date().toLocaleString('en-IN', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
    const thHtml = headers.map(h=>`<th>${h}</th>`).join('');
    const rowsHtml = rows.map(r=>'<tr>'+r.map(c=>`<td>${c}</td>`).join('')+'</tr>').join('');
    const html = `<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Inquiries — Gyanam India</title>
    <style>body{font-family:Arial,sans-serif;margin:1cm;font-size:11px}h2{margin:0;font-size:15px}p{margin:0 0 8px}
    table{width:100%;border-collapse:collapse;margin-top:10px}
    th{background:#4361ee;color:#fff;padding:6px 8px;text-align:left;font-size:9.5px;text-transform:uppercase}
    td{padding:5px 8px;border-bottom:1px solid #e5e7eb}tr:nth-child(even) td{background:#f8fafc}
    .footer{margin-top:12px;font-size:10px;color:#94a3b8;text-align:right}
    @media print{@page{margin:1cm;size:landscape}}</style></head><body>
    <h2>Inquiries — Gyanam India</h2>
    <p style="font-size:11px;color:#64748b">Generated: ${now} &bull; ${rows.length} record(s)</p>
    <table><thead><tr>${thHtml}</tr></thead><tbody>${rowsHtml}</tbody></table>
    <div class="footer">Gyanam India — Confidential</div>
    </body></html>`;
    const w = window.open('', '_blank', 'width=1100,height=700');
    w.document.write(html); w.document.close(); w.focus();
    setTimeout(() => { w.print(); w.close(); }, 400);
}
</script>
</body>
</html>
