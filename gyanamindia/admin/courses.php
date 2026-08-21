<?php
/**
 * Gyanam Portal — Admin: Master Courses Management (Head Office)
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';
if (file_exists(__DIR__ . '/../includes/exam_integration.php')) {
    require_once __DIR__ . '/../includes/exam_integration.php';
}

requireLogin(['Admin']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
ensureDualMaterialCourseSchema($pdo);

// ── AJAX handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        switch ($_POST['action']) {

            case 'add':
                $shareWith    = floatval($_POST['ho_share_with_material'] ?? 0);
                $shareWithout = floatval($_POST['ho_share_without_material'] ?? 0);
                $dlcWith      = floatval($_POST['dlc_share_with_material'] ?? 0);
                $dlcWithout   = floatval($_POST['dlc_share_without_material'] ?? 0);
                // Keep legacy ho_share filled (without preferred) for older share-payment fallbacks
                $legacyShare  = $shareWithout > 0 ? $shareWithout : $shareWith;
                $stmt = $pdo->prepare("
                    INSERT INTO courses
                        (course_name, course_type, duration, course_content,
                         ho_share, ho_share_with_material, ho_share_without_material,
                         dlc_share_with_material, dlc_share_without_material,
                         material_type, material_language, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Without Material', ?, ?)
                ");
                $stmt->execute([
                    trim($_POST['course_name']),
                    trim($_POST['course_type'] ?? ''),
                    $_POST['duration'] ?? null,
                    trim($_POST['course_content'] ?? ''),
                    $legacyShare,
                    $shareWith,
                    $shareWithout,
                    $dlcWith,
                    $dlcWithout,
                    $_POST['material_language'] ?? 'English',
                    $_POST['status'] ?? 'Active',
                ]);
                echo json_encode(['success' => true, 'message' => 'Course added successfully']);
                // 🔄 Sync courses to Exam Portal
                if (function_exists('syncCoursesToExamPortal')) { syncCoursesToExamPortal($pdo); }
                exit;

            case 'edit':
                $shareWith    = floatval($_POST['ho_share_with_material'] ?? 0);
                $shareWithout = floatval($_POST['ho_share_without_material'] ?? 0);
                $dlcWith      = floatval($_POST['dlc_share_with_material'] ?? 0);
                $dlcWithout   = floatval($_POST['dlc_share_without_material'] ?? 0);
                $legacyShare  = $shareWithout > 0 ? $shareWithout : $shareWith;
                $stmt = $pdo->prepare("
                    UPDATE courses
                    SET course_name                = ?,
                        course_type                = ?,
                        duration                   = ?,
                        course_content             = ?,
                        ho_share                   = ?,
                        ho_share_with_material     = ?,
                        ho_share_without_material  = ?,
                        dlc_share_with_material    = ?,
                        dlc_share_without_material = ?,
                        material_language          = ?,
                        status                     = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    trim($_POST['course_name']),
                    trim($_POST['course_type'] ?? ''),
                    $_POST['duration'] ?? null,
                    trim($_POST['course_content'] ?? ''),
                    $legacyShare,
                    $shareWith,
                    $shareWithout,
                    $dlcWith,
                    $dlcWithout,
                    $_POST['material_language'] ?? 'English',
                    $_POST['status'] ?? 'Active',
                    intval($_POST['id']),
                ]);
                echo json_encode(['success' => true, 'message' => 'Course updated successfully']);
                // 🔄 Sync courses to Exam Portal
                if (function_exists('syncCoursesToExamPortal')) { syncCoursesToExamPortal($pdo); }
                exit;

            case 'delete':
                // Check if any ATC has set fees for this course
                $chk = $pdo->prepare("SELECT COUNT(*) FROM atc_course_fees WHERE course_id = ?");
                $chk->execute([intval($_POST['id'])]);
                if ($chk->fetchColumn() > 0) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete: ATCs have set fees for this course. Deactivate it instead.']);
                    exit;
                }
                $stmt = $pdo->prepare("DELETE FROM courses WHERE id = ?");
                $stmt->execute([intval($_POST['id'])]);
                echo json_encode(['success' => true, 'message' => 'Course deleted successfully']);
                // 🔄 Sync courses to Exam Portal
                if (function_exists('syncCoursesToExamPortal')) { syncCoursesToExamPortal($pdo); }
                exit;

            case 'get':
                $stmt = $pdo->prepare("SELECT * FROM courses WHERE id = ?");
                $stmt->execute([intval($_POST['id'])]);
                $course = $stmt->fetch(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'data' => $course]);
                exit;

            case 'toggle_status':
                $stmt = $pdo->prepare("UPDATE courses SET status = IF(status='Active','Inactive','Active') WHERE id = ?");
                $stmt->execute([intval($_POST['id'])]);
                echo json_encode(['success' => true, 'message' => 'Status toggled']);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ── Fetch courses ─────────────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'all';
$searchTerm   = trim($_GET['search'] ?? '');
$pagerParams  = paginationParams(25);

$where  = [];
$params = [];

if ($searchTerm) {
    $where[]  = "(c.course_name LIKE ? OR c.course_type LIKE ?)";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
}
if ($statusFilter !== 'all') {
    $where[]  = "c.status = ?";
    $params[] = $statusFilter;
}

$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM courses c" . $whereSql);
$countStmt->execute($params);
$totalCourses = (int)$countStmt->fetchColumn();
$pager = paginationMeta($totalCourses, $pagerParams);

$sql = "SELECT c.*,
               COUNT(acf.id) AS atc_count
        FROM courses c
        LEFT JOIN atc_course_fees acf ON acf.course_id = c.id"
        . $whereSql
        . " GROUP BY c.id ORDER BY c.course_name ASC
           LIMIT {$pager['per_page']} OFFSET {$pager['offset']}";

$stmt    = $pdo->prepare($sql);
$stmt->execute($params);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Counts
$counts = $pdo->query("SELECT status, COUNT(*) cnt FROM courses GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalCount    = array_sum($counts);
$activeCount   = $counts['Active']   ?? 0;
$inactiveCount = $counts['Inactive'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Master Courses — Head Office | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📚</text></svg>">
    <style>
        :root { --font: 'Sora', sans-serif; }

        .page-content { padding: 1.75rem 2rem; width: 100%; box-sizing: border-box; }

        /* Stats row */
        .stat-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: #fff; border: 1.5px solid #e5e7eb; border-radius: 16px; padding: 1.25rem 1.5rem; display: flex; flex-direction: column; gap: .35rem; box-shadow: 0 2px 8px rgba(0,0,0,.05); }
        .stat-label { font-size: .75rem; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .05em; }
        .stat-value { font-size: 2rem; font-weight: 800; color: #1f2937; line-height: 1; }
        .stat-value.green { color: #059669; }
        .stat-value.amber { color: #d97706; }

        /* Table fee columns */
        .fee-pill {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            padding: .28rem .7rem;
            border-radius: 8px;
            font-size: .76rem;
            font-weight: 800;
            letter-spacing: .01em;
            line-height: 1.2;
            white-space: nowrap;
        }
        .fee-base { background: #dbeafe; color: #1d4ed8; }
        .fee-share { background: #d1fae5; color: #065f46; }

        /* Stacked W / WO share amounts */
        .share-stack {
            display: flex;
            flex-direction: column;
            gap: .35rem;
            align-items: flex-start;
            min-width: 6.5rem;
        }
        .share-chip {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
            min-width: 5.75rem;
            padding: .32rem .65rem;
            border-radius: 8px;
            font-size: .74rem;
            font-weight: 800;
            line-height: 1.15;
            border: 1px solid transparent;
        }
        .share-chip .tag {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.55rem;
            height: 1.15rem;
            padding: 0 .28rem;
            border-radius: 5px;
            font-size: .62rem;
            font-weight: 800;
            letter-spacing: .04em;
            background: rgba(255,255,255,.55);
        }
        .share-chip.ho-w  { background: #ede9fe; color: #5b21b6; border-color: #ddd6fe; }
        .share-chip.ho-wo { background: #d1fae5; color: #065f46; border-color: #a7f3d0; }
        .share-chip.dlc-w  { background: #ffedd5; color: #9a3412; border-color: #fed7aa; }
        .share-chip.dlc-wo { background: #fef3c7; color: #92400e; border-color: #fde68a; }
        .share-chip.is-zero {
            background: #f8fafc;
            color: #64748b;
            border-color: #e2e8f0;
            font-weight: 700;
        }
        .share-chip.is-zero .tag { background: #e2e8f0; color: #475569; }

        #coursesTable th {
            white-space: nowrap;
            vertical-align: middle;
        }
        #coursesTable td {
            vertical-align: middle;
            padding-top: .9rem;
            padding-bottom: .9rem;
        }
        #coursesTable .cell-name {
            font-size: .9rem;
            font-weight: 800;
            color: #111827;
            line-height: 1.35;
        }
        #coursesTable .cell-sub {
            font-size: .75rem;
            color: #6b7280;
            margin-top: .2rem;
            line-height: 1.4;
            max-width: 280px;
        }

        /* HO Share type badge */
        .share-type { font-size: .7rem; font-weight: 700; padding: .15rem .5rem; border-radius: 6px; background: #f3f4f6; color: #374151; text-transform: uppercase; letter-spacing: .04em; }

        /* ATC count badge */
        .atc-ct { display: inline-flex; align-items: center; gap: .35rem; font-size: .78rem; font-weight: 700; color: #6b7280; }

        /* Toolbar */
        .page-toolbar { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem; gap: 1rem; flex-wrap: wrap; }
        .status-tabs { display: flex; gap: .5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .status-tab { display: flex; align-items: center; gap: .5rem; padding: .65rem 1.1rem; border-radius: 12px; border: 1.5px solid #e5e7eb; text-decoration: none; color: #374151; font-size: .85rem; font-weight: 700; transition: all .2s; white-space: nowrap; background: #fff; }
        .status-tab:hover { border-color: #a5b4fc; background: #eef2ff; }
        .status-tab.active { background: linear-gradient(135deg,#4361ee,#3730a3); border-color: #3730a3; color: #fff; box-shadow: 0 4px 12px rgba(67,97,238,.25); }
        .tab-count { padding: .2rem .55rem; border-radius: 999px; font-size: .72rem; font-weight: 800; background: rgba(255,255,255,.2); }
        .status-tab:not(.active) .tab-count { background: #e5e7eb; color: #6b7280; }

        /* Modal field grid */
        .field-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .875rem; }
        .field-grid .full { grid-column: 1 / -1; }
        .field-label { font-size: .8rem; font-weight: 700; color: #374151; margin-bottom: .3rem; display: block; }
        .field-req { color: #ef4444; }
        .field-input, .field-select { width: 100%; height: 40px; padding: 0 .875rem; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: .875rem; font-family: inherit; color: #1f2937; background: #fff; transition: border .2s, box-shadow .2s; }
        .field-input:focus, .field-select:focus { outline: none; border-color: #4361ee; box-shadow: 0 0 0 3px rgba(67,97,238,.12); }
        textarea.field-input { height: auto; padding: .75rem .875rem; resize: vertical; }
        .field-hint { font-size: .72rem; color: #9ca3af; margin-top: .25rem; }

        /* Fee section highlight */
        .fee-section { background: linear-gradient(135deg,#eef2ff,#f5f3ff); border: 1.5px solid #c7d2fe; border-radius: 14px; padding: 1rem 1.25rem; }
        .fee-section-title { font-size: .72rem; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: #4361ee; margin-bottom: .875rem; }

        /* Toggle status button */
        .btn-toggle { padding: .35rem .85rem; border-radius: 8px; font-size: .75rem; font-weight: 700; border: 1.5px solid; cursor: pointer; transition: all .2s; }
        .btn-toggle.active { border-color: #d1fae5; background: #d1fae5; color: #065f46; }
        .btn-toggle.active:hover { background: #a7f3d0; }
        .btn-toggle.inactive { border-color: #fee2e2; background: #fee2e2; color: #991b1b; }
        .btn-toggle.inactive:hover { background: #fca5a5; }

        /* Course modal polish */
        #courseModal .modal-card {
            border: 1.5px solid #e5e7eb;
            border-radius: 18px;
            overflow: hidden;
            max-height: 92vh;
            display: flex;
            flex-direction: column;
        }
        #courseModal .modal-header {
            background: linear-gradient(135deg, #4361ee, #3730a3);
            border-bottom: 0;
            padding: 1rem 1.25rem;
        }
        #courseModal .modal-header h3 {
            color: #fff;
            font-size: .98rem;
            display: flex;
            align-items: center;
            gap: .55rem;
        }
        #courseModal .modal-header .modal-header-icon {
            color: rgba(255,255,255,.9);
            width: 18px;
            height: 18px;
        }
        #courseModal .modal-close {
            background: rgba(255,255,255,.18);
            color: #fff;
            border-radius: 8px;
        }
        #courseModal .modal-close:hover {
            background: rgba(255,255,255,.28);
            color: #fff;
        }
        #courseModal .modal-body {
            background: #f8fafc;
            padding: 1rem 1.25rem;
            display: flex;
            flex-direction: column;
            gap: .9rem;
            overflow-y: auto;
            max-height: calc(92vh - 138px);
            overscroll-behavior: contain;
        }
        #courseModal .modal-body::-webkit-scrollbar {
            width: 8px;
        }
        #courseModal .modal-body::-webkit-scrollbar-track {
            background: #eef2f7;
            border-radius: 999px;
        }
        #courseModal .modal-body::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 999px;
        }
        #courseModal .modal-body::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }
        .form-section {
            background: #fff;
            border: 1.5px solid #e5e7eb;
            border-radius: 14px;
            padding: 1rem;
        }
        .form-section-title {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            font-size: .75rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: #4b5563;
            margin-bottom: .55rem;
        }
        .section-icon {
            width: 15px;
            height: 15px;
            color: #4361ee;
        }

        /* Share amounts — partitioned by material option */
        .share-flow {
            display: flex;
            align-items: center;
            gap: .45rem;
            flex-wrap: wrap;
            margin-bottom: .9rem;
            padding: .55rem .7rem;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            font-size: .72rem;
            font-weight: 700;
            color: #64748b;
        }
        .share-flow .flow-step {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            padding: .2rem .5rem;
            border-radius: 6px;
            background: #fff;
            border: 1px solid #e2e8f0;
            color: #334155;
        }
        .share-flow .flow-arrow { color: #94a3b8; font-weight: 800; }
        .share-flow .flow-note { margin-left: auto; font-weight: 600; color: #94a3b8; }

        .share-split {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: .75rem;
            margin-bottom: .75rem;
        }
        @media (max-width: 640px) {
            .share-split { grid-template-columns: 1fr; }
            .share-flow .flow-note { margin-left: 0; width: 100%; }
        }
        .share-pane {
            border: 1.5px solid #e5e7eb;
            border-radius: 12px;
            padding: .85rem;
            background: #fafbfc;
        }
        .share-pane.pane-with {
            border-color: #ddd6fe;
            background: linear-gradient(180deg, #f5f3ff 0%, #fafbfc 40%);
        }
        .share-pane.pane-without {
            border-color: #a7f3d0;
            background: linear-gradient(180deg, #ecfdf5 0%, #fafbfc 40%);
        }
        .share-pane-head {
            display: flex;
            align-items: center;
            gap: .5rem;
            margin-bottom: .75rem;
            padding-bottom: .55rem;
            border-bottom: 1px solid rgba(15, 23, 42, .08);
        }
        .share-pane-tag {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2rem;
            padding: .2rem .45rem;
            border-radius: 6px;
            font-size: .65rem;
            font-weight: 800;
            letter-spacing: .04em;
        }
        .pane-with .share-pane-tag { background: #ede9fe; color: #5b21b6; }
        .pane-without .share-pane-tag { background: #d1fae5; color: #065f46; }
        .share-pane-title {
            font-size: .82rem;
            font-weight: 800;
            color: #1f2937;
            line-height: 1.2;
        }
        .share-pane-sub {
            font-size: .68rem;
            font-weight: 600;
            color: #94a3b8;
            margin-top: .1rem;
        }
        .share-pane .share-field { margin-bottom: .65rem; }
        .share-pane .share-field:last-child { margin-bottom: 0; }
        .share-pane .field-label {
            font-size: .72rem;
            margin-bottom: .25rem;
        }
        .share-pane .field-hint {
            font-size: .65rem;
            margin-top: .25rem;
            color: #94a3b8;
        }
        #courseModal .field-input,
        #courseModal .field-select {
            height: 42px;
            border-color: #dbe1ea;
        }
        #courseModal .field-input::placeholder { color: #9ca3af; }
        #courseModal .modal-footer {
            position: sticky;
            bottom: 0;
            background: #fff;
            border-top: 1px solid #e5e7eb;
            padding: .9rem 1.25rem;
            display: flex;
            justify-content: flex-end;
            gap: .65rem;
        }
        #courseModal .modal-footer .btn-secondary,
        #courseModal .modal-footer .btn-primary {
            min-width: 126px;
            height: 42px;
            border-radius: 10px;
            padding: 0 1.1rem;
            font-size: .84rem;
            font-weight: 700;
            letter-spacing: .01em;
            transition: all .2s ease;
        }
        #courseModal .modal-footer .btn-secondary {
            border: 1.5px solid #e5e7eb;
            background: #f8fafc;
            color: #374151;
        }
        #courseModal .modal-footer .btn-secondary:hover {
            background: #f1f5f9;
            border-color: #cbd5e1;
        }
        #courseModal .modal-footer .btn-primary {
            border: 1.5px solid #3730a3;
            background: linear-gradient(135deg, #4361ee, #3730a3);
            color: #fff;
            box-shadow: 0 4px 12px rgba(67, 97, 238, .25);
        }
        #courseModal .modal-footer .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(67, 97, 238, .32);
        }
        #courseModal .modal-footer .btn-primary:disabled {
            opacity: .75;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        @media (max-width: 600px) {
            .field-grid { grid-template-columns: 1fr; }
            .field-grid .full { grid-column: 1; }
            #courseModal .modal-card { max-height: 96vh; }
            #courseModal .modal-body { padding: .85rem 1rem; max-height: calc(96vh - 138px); }
            .form-section { padding: .85rem; }
            #courseModal .modal-footer {
                padding: .8rem 1rem;
                justify-content: stretch;
            }
            #courseModal .modal-footer .btn-secondary,
            #courseModal .modal-footer .btn-primary {
                min-width: 0;
                flex: 1;
            }
        }
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
                    <h2>Master Courses</h2>
                    <p>HO share (ATC → Admin) and DLC share (Admin → DLC) for With / Without Material</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">

            <!-- Stat Cards -->
            <div class="stat-cards">
                <div class="stat-card">
                    <div class="stat-label">Total Courses</div>
                    <div class="stat-value"><?= $totalCount ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Active</div>
                    <div class="stat-value green"><?= $activeCount ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Inactive</div>
                    <div class="stat-value amber"><?= $inactiveCount ?></div>
                </div>
            </div>

            <!-- Status Tabs -->
            <div class="status-tabs">
                <a href="?status=all<?= $searchTerm ? '&search='.urlencode($searchTerm) : '' ?>" class="status-tab <?= $statusFilter==='all'?'active':'' ?>">
                    All <span class="tab-count"><?= $totalCount ?></span>
                </a>
                <a href="?status=Active<?= $searchTerm ? '&search='.urlencode($searchTerm) : '' ?>" class="status-tab <?= $statusFilter==='Active'?'active':'' ?>">
                    Active <span class="tab-count"><?= $activeCount ?></span>
                </a>
                <a href="?status=Inactive<?= $searchTerm ? '&search='.urlencode($searchTerm) : '' ?>" class="status-tab <?= $statusFilter==='Inactive'?'active':'' ?>">
                    Inactive <span class="tab-count"><?= $inactiveCount ?></span>
                </a>
            </div>

            <!-- Toolbar -->
            <div class="page-toolbar">
                <h3>
                    Course List
                    <span class="badge-count"><?= $pager['total'] ?></span>
                </h3>
                <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
                    <form method="GET" style="display:flex;gap:.75rem">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                        <div class="search-bar">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                            <input type="text" name="search" placeholder="Search courses…" value="<?= htmlspecialchars($searchTerm) ?>">
                        </div>
                        <button type="submit" class="btn-primary" style="padding:0 1.25rem">Search</button>
                    </form>
                    <button class="btn-add" onclick="openAddModal()">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Add Course
                    </button>
                </div>
            </div>

            <!-- Table -->
            <div class="table-card">
                <table class="data-table" id="coursesTable">
                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Type</th>
                            <th>Duration</th>
                            <th>HO Share</th>
                            <th>DLC Share</th>
                            <th>ATCs using</th>
                            <th>Status</th>
                            <th style="text-align:center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($courses)): ?>
                        <tr><td colspan="8" class="table-empty">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                            <p>No courses found. Create your first master course!</p>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($courses as $c):
                            $shareWith    = floatval($c['ho_share_with_material'] ?? 0);
                            $shareWithout = floatval($c['ho_share_without_material'] ?? 0);
                            $dlcWith      = floatval($c['dlc_share_with_material'] ?? 0);
                            $dlcWithout   = floatval($c['dlc_share_without_material'] ?? 0);
                            // Legacy display fallback
                            if ($shareWith <= 0 && $shareWithout <= 0 && floatval($c['ho_share'] ?? 0) > 0) {
                                if (($c['material_type'] ?? '') === 'With Material') {
                                    $shareWith = floatval($c['ho_share']);
                                } else {
                                    $shareWithout = floatval($c['ho_share']);
                                }
                            }
                        ?>
                        <tr>
                            <td>
                                <div class="cell-name"><?= htmlspecialchars($c['course_name']) ?></div>
                                <?php if (!empty($c['course_content'])): ?>
                                    <div class="cell-sub"><?= htmlspecialchars(substr($c['course_content'],0,55)) ?><?= strlen($c['course_content'])>55?'…':'' ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($c['course_type'])): ?>
                                    <span style="display:inline-block;padding:.2rem .6rem;border-radius:6px;font-size:.75rem;font-weight:700;background:#eef2ff;color:#4361ee"><?= htmlspecialchars($c['course_type']) ?></span>
                                <?php else: ?>
                                    <span style="color:#9ca3af">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($c['duration'] ?: '—') ?></td>
                            <td>
                                <div class="share-stack">
                                    <span class="share-chip ho-w <?= $shareWith <= 0 ? 'is-zero' : '' ?>"><span class="tag">W</span>₹<?= number_format($shareWith, 0) ?></span>
                                    <span class="share-chip ho-wo <?= $shareWithout <= 0 ? 'is-zero' : '' ?>"><span class="tag">WO</span>₹<?= number_format($shareWithout, 0) ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="share-stack">
                                    <span class="share-chip dlc-w <?= $dlcWith <= 0 ? 'is-zero' : '' ?>"><span class="tag">W</span>₹<?= number_format($dlcWith, 0) ?></span>
                                    <span class="share-chip dlc-wo <?= $dlcWithout <= 0 ? 'is-zero' : '' ?>"><span class="tag">WO</span>₹<?= number_format($dlcWithout, 0) ?></span>
                                </div>
                            </td>
                            <td>
                                <span class="atc-ct">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                                    <?= $c['atc_count'] ?> ATC<?= $c['atc_count']!=1?'s':'' ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn-toggle <?= strtolower($c['status']) ?>" onclick="toggleStatus(<?= $c['id'] ?>, this)">
                                    <?= $c['status'] ?>
                                </button>
                            </td>
                            <td style="text-align:center">
                                <div class="cell-actions" style="justify-content:center">
                                    <button class="btn-icon" onclick="editCourse(<?= $c['id'] ?>)" title="Edit">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                    </button>
                                    <button class="btn-icon danger" onclick="deleteCourse(<?= $c['id'] ?>, '<?= htmlspecialchars($c['course_name'],ENT_QUOTES) ?>')" title="Delete">
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
            <?= renderPagination($pager, 'courses') ?>
        </div>
    </main>
</div>

<!-- ── Add / Edit Course Modal ────────────────────────────────────────────── -->
<div class="modal-overlay" id="courseModal">
    <div class="modal-card" style="max-width:760px">
        <div class="modal-header">
            <h3>
                <svg class="modal-header-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                <span id="modalTitle">Add Master Course</span>
            </h3>
            <button type="button" class="modal-close" onclick="closeModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>

        <form id="courseForm" novalidate>
            <input type="hidden" id="courseId" name="id">
            <input type="hidden" id="formAction" name="action" value="add">

            <div class="modal-body">

                <div class="form-section">
                    <div class="form-section-title">
                        <svg class="section-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                        Course Information
                    </div>
                    <div class="field-grid">
                        <div class="full">
                            <label class="field-label" for="course_name">Course Name <span class="field-req">*</span></label>
                            <input type="text" class="field-input" id="course_name" name="course_name" required maxlength="100" placeholder="e.g. Abacus Level 1, DCA, Vedic Maths">
                        </div>
                        <div>
                            <label class="field-label" for="course_type">Course Type <span class="field-req">*</span></label>
                            <select class="field-select" id="course_type" name="course_type" required>
                                <option value="">— Select Type —</option>
                                <option value="Abacus">Abacus</option>
                                <option value="Vedic Maths">Vedic Maths</option>
                                <option value="IT">IT</option>
                            </select>
                            <div class="field-hint">Determines which ATC centers can use this course</div>
                        </div>
                        <div>
                            <label class="field-label" for="duration">Duration</label>
                            <select class="field-select" id="duration" name="duration">
                                <option value="">— Select —</option>
                                <option value="1 Month">1 Month</option>
                                <option value="2 Months">2 Months</option>
                                <option value="3 Months">3 Months</option>
                                <option value="6 Months">6 Months</option>
                                <option value="1 Year">1 Year</option>
                                <option value="2 Years">2 Years</option>
                                <option value="custom">Custom…</option>
                            </select>
                        </div>
                        <div id="customDurWrap" style="display:none" class="full">
                            <label class="field-label" for="custom_dur">Custom Duration</label>
                            <input type="text" class="field-input" id="custom_dur" maxlength="50" placeholder="e.g. 45 Days, 18 Months">
                        </div>
                        <div class="full">
                            <label class="field-label" for="course_content">Course Content <span class="field-req">*</span></label>
                            <textarea class="field-input" id="course_content" name="course_content" rows="4" required placeholder="Topics covered, objectives, syllabus outline (shown on student certificate)..."></textarea>
                            <div class="field-hint">This content will appear on the student's completion certificate</div>
                        </div>
                    </div>
                </div>

                <!-- Materials & Share — partitioned by material option -->
                <div class="form-section">
                    <div class="form-section-title">
                        <svg class="section-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                        Share Amounts (per student)
                    </div>

                    <div class="share-flow">
                        <span class="flow-step">ATC pays HO</span>
                        <span class="flow-arrow">→</span>
                        <span class="flow-step">Admin</span>
                        <span class="flow-arrow">→</span>
                        <span class="flow-step">DLC gets DLC share</span>
                        <span class="flow-note">₹0 = hide that option from ATC</span>
                    </div>

                    <div class="share-split">
                        <!-- With Material -->
                        <div class="share-pane pane-with">
                            <div class="share-pane-head">
                                <span class="share-pane-tag">W</span>
                                <div>
                                    <div class="share-pane-title">With Material</div>
                                    <div class="share-pane-sub">Books / kit included</div>
                                </div>
                            </div>
                            <div class="share-field">
                                <label class="field-label" for="ho_share_with_material">HO Share (&#8377;)</label>
                                <input type="number" class="field-input" id="ho_share_with_material" name="ho_share_with_material" min="0" step="1" placeholder="e.g. 2400">
                                <div class="field-hint">ATC → Admin</div>
                            </div>
                            <div class="share-field">
                                <label class="field-label" for="dlc_share_with_material">DLC Share (&#8377;)</label>
                                <input type="number" class="field-input" id="dlc_share_with_material" name="dlc_share_with_material" min="0" step="1" placeholder="e.g. 200">
                                <div class="field-hint">Admin → DLC</div>
                            </div>
                            <div class="share-field">
                                <label class="field-label" for="material_language">Material Language</label>
                                <select class="field-select" id="material_language" name="material_language">
                                    <option value="English">English</option>
                                    <option value="Marathi">Marathi</option>
                                </select>
                            </div>
                        </div>

                        <!-- Without Material -->
                        <div class="share-pane pane-without">
                            <div class="share-pane-head">
                                <span class="share-pane-tag">WO</span>
                                <div>
                                    <div class="share-pane-title">Without Material</div>
                                    <div class="share-pane-sub">No books / kit</div>
                                </div>
                            </div>
                            <div class="share-field">
                                <label class="field-label" for="ho_share_without_material">HO Share (&#8377;)</label>
                                <input type="number" class="field-input" id="ho_share_without_material" name="ho_share_without_material" min="0" step="1" placeholder="e.g. 180">
                                <div class="field-hint">ATC → Admin</div>
                            </div>
                            <div class="share-field">
                                <label class="field-label" for="dlc_share_without_material">DLC Share (&#8377;)</label>
                                <input type="number" class="field-input" id="dlc_share_without_material" name="dlc_share_without_material" min="0" step="1" placeholder="e.g. 50">
                                <div class="field-hint">Admin → DLC</div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="field-label" for="status">Status <span class="field-req">*</span></label>
                        <select class="field-select" id="status" name="status" required style="max-width:220px">
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                </div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn-primary" id="submitBtn">
                    <span id="submitBtnText">Add Course</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
// ── Duration toggle ───────────────────────────────────────────────────────────
document.getElementById('duration').addEventListener('change', function() {
    const wrap = document.getElementById('customDurWrap');
    wrap.style.display = this.value === 'custom' ? 'block' : 'none';
    document.getElementById('custom_dur').required = (this.value === 'custom');
});

// ── Modal helpers ─────────────────────────────────────────────────────────────
function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Add Master Course';
    document.getElementById('submitBtnText').textContent = 'Add Course';
    document.getElementById('formAction').value = 'add';
    document.getElementById('courseForm').reset();
    document.getElementById('courseId').value = '';
    document.getElementById('customDurWrap').style.display = 'none';
    document.getElementById('custom_dur').required = false;
    document.getElementById('custom_dur').value = '';
    document.getElementById('ho_share_with_material').value = '';
    document.getElementById('ho_share_without_material').value = '';
    document.getElementById('dlc_share_with_material').value = '';
    document.getElementById('dlc_share_without_material').value = '';
    document.getElementById('courseModal').classList.add('active');
}
function closeModal() {
    document.getElementById('courseModal').classList.remove('active');
}

// ── Edit ─────────────────────────────────────────────────────────────────────
async function editCourse(id) {
    const fd = new FormData(); fd.append('action','get'); fd.append('id',id);
    const r  = await (await fetch('', { method:'POST', body:fd })).json();
    if (!r.success || !r.data) { alert('Error loading course'); return; }
    const c  = r.data;
    document.getElementById('modalTitle').textContent = 'Edit Course';
    document.getElementById('submitBtnText').textContent = 'Update Course';
    document.getElementById('formAction').value = 'edit';
    document.getElementById('courseId').value       = c.id;
    document.getElementById('course_name').value    = c.course_name;
    document.getElementById('course_type').value    = c.course_type || '';
    document.getElementById('course_content').value = c.course_content || '';
    document.getElementById('status').value         = c.status;
    document.getElementById('material_language').value = c.material_language || 'English';

    let shareWith    = parseFloat(c.ho_share_with_material) || 0;
    let shareWithout = parseFloat(c.ho_share_without_material) || 0;
    const legacyShare = parseFloat(c.ho_share) || 0;
    if (shareWith <= 0 && shareWithout <= 0 && legacyShare > 0) {
        if ((c.material_type || '') === 'With Material') shareWith = legacyShare;
        else shareWithout = legacyShare;
    }
    document.getElementById('ho_share_with_material').value = shareWith || '';
    document.getElementById('ho_share_without_material').value = shareWithout || '';
    document.getElementById('dlc_share_with_material').value = parseFloat(c.dlc_share_with_material) || '';
    document.getElementById('dlc_share_without_material').value = parseFloat(c.dlc_share_without_material) || '';

    // Duration
    const predefined = ['1 Month','2 Months','3 Months','6 Months','1 Year','2 Years'];
    if (predefined.includes(c.duration)) {
        document.getElementById('duration').value = c.duration;
        document.getElementById('customDurWrap').style.display = 'none';
        document.getElementById('custom_dur').required = false;
        document.getElementById('custom_dur').value = '';
    } else if (c.duration) {
        document.getElementById('duration').value = 'custom';
        document.getElementById('customDurWrap').style.display = 'block';
        document.getElementById('custom_dur').value = c.duration;
        document.getElementById('custom_dur').required = true;
    } else {
        document.getElementById('duration').value = '';
        document.getElementById('customDurWrap').style.display = 'none';
        document.getElementById('custom_dur').required = false;
        document.getElementById('custom_dur').value = '';
    }

    document.getElementById('courseModal').classList.add('active');
}

// ── Delete ────────────────────────────────────────────────────────────────────
async function deleteCourse(id, name) {
    if (!confirm(`Delete "${name}"?\n\nNote: If any ATC has set fees for this course, deletion will be blocked. Deactivate instead.`)) return;
    const fd = new FormData(); fd.append('action','delete'); fd.append('id',id);
    const r  = await (await fetch('', { method:'POST', body:fd })).json();
    r.success ? location.reload() : alert(r.message || 'Error deleting course');
}

// ── Toggle status ─────────────────────────────────────────────────────────────
async function toggleStatus(id, btn) {
    const fd = new FormData(); fd.append('action','toggle_status'); fd.append('id',id);
    const r  = await (await fetch('', { method:'POST', body:fd })).json();
    if (r.success) location.reload();
    else alert(r.message || 'Error toggling status');
}

// ── Form submit ───────────────────────────────────────────────────────────────
document.getElementById('courseForm').addEventListener('submit', async function(e) {
    e.preventDefault();

    if (!document.getElementById('course_name').value.trim()) { alert('Course Name is required'); return; }
    if (!document.getElementById('course_type').value) { alert('Course Type is required'); return; }
    if (!document.getElementById('course_content').value.trim()) { alert('Course Content is required'); return; }

    // Resolve duration
    let finalDur = document.getElementById('duration').value;
    if (finalDur === 'custom') {
        finalDur = document.getElementById('custom_dur').value.trim();
        if (!finalDur) { alert('Please enter a custom duration'); return; }
    }
    // Inject final duration as hidden
    const hdur = document.createElement('input');
    hdur.type = 'hidden'; hdur.name = 'duration'; hdur.value = finalDur;
    this.appendChild(hdur);

    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    document.getElementById('submitBtnText').textContent = 'Saving…';

    try {
        const fd = new FormData(this);
        const r  = await (await fetch('', { method:'POST', body:fd })).json();
        if (r.success) { location.reload(); }
        else {
            alert(r.message || 'Error saving course');
            submitBtn.disabled = false;
            document.getElementById('submitBtnText').textContent = document.getElementById('formAction').value === 'add' ? 'Add Course' : 'Update Course';
        }
    } catch(err) {
        alert('Network error: ' + err.message);
        submitBtn.disabled = false;
    } finally {
        if (hdur.parentNode) hdur.parentNode.removeChild(hdur);
    }
});

// Close modal on overlay click / Escape
document.getElementById('courseModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('courseModal').classList.contains('active')) {
        closeModal();
    }
});
</script>
</body>
</html>
