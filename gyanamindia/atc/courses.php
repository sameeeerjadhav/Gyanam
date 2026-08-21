<?php
/**
 * Gyanam Portal — ATC: Fees Structure (Course Fee Table)
 * M — ATC Fees Structure Table
 * 
 * - Shows only courses matching this ATC's center type
 * - Fee > 0  → course becomes Active (visible in admission/inquiry dropdowns)
 * - Fee = 0  → course becomes Inactive (hidden from dropdowns)
 * - ATC can inline-edit fees in a clear table view
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo   = getDBConnection();
$atcId = $_SESSION['atc_id'] ?? null;
ensureDualMaterialCourseSchema($pdo);

// Fetch ATC center details (for center_type)
$atcStmt = $pdo->prepare("SELECT * FROM atc_centers WHERE id = ?");
$atcStmt->execute([$atcId]);
$atcCenter = $atcStmt->fetch(PDO::FETCH_ASSOC);
$centerType = $atcCenter['center_type'] ?? '';

// Build center-type → course_type matching logic
// center_type can be: "Abacus", "Vedic Maths", "IT", "Abacus + IT", etc.
function getCourseTypesForCenter(string $centerType): array {
    $map = [
        'Abacus'                   => ['Abacus'],
        'Vedic Maths'              => ['Vedic Maths'],
        'IT'                       => ['IT'],
        'Abacus + IT'              => ['Abacus', 'IT'],
        'IT + Abacus'              => ['Abacus', 'IT'],
        'Abacus + Vedic Maths'     => ['Abacus', 'Vedic Maths'],
        'Vedic Maths + Abacus'     => ['Abacus', 'Vedic Maths'],
        'Vedic Maths + IT'         => ['Vedic Maths', 'IT'],
        'IT + Vedic Maths'         => ['Vedic Maths', 'IT'],
        'Abacus + Vedic Maths + IT'     => ['Abacus', 'Vedic Maths', 'IT'],
        'All Three (IT + Abacus + Vedic Maths)' => ['Abacus', 'Vedic Maths', 'IT'],
    ];
    return $map[$centerType] ?? [];
}
$allowedTypes = getCourseTypesForCenter($centerType);

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    try {
        switch ($_POST['action']) {

            case 'set_fee':
                $courseId   = intval($_POST['course_id']);
                $feeWith    = floatval($_POST['fee_with_material'] ?? 0);
                $feeWithout = floatval($_POST['fee_without_material'] ?? 0);
                if ($feeWith < 0) $feeWith = 0;
                if ($feeWithout < 0) $feeWithout = 0;
                $finalFee = max($feeWith, $feeWithout);

                // Verify course exists and belongs to an allowed type
                $chk = $pdo->prepare("SELECT id, course_name FROM courses WHERE id = ? AND status = 'Active'");
                $chk->execute([$courseId]);
                $row = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$row) {
                    echo json_encode(['success' => false, 'message' => 'Course not found or inactive']);
                    exit;
                }

                // Upsert both material fees; final_fee kept for backward-compat active checks
                $stmt = $pdo->prepare("
                    INSERT INTO atc_course_fees (atc_id, course_id, final_fee, fee_with_material, fee_without_material)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        final_fee = VALUES(final_fee),
                        fee_with_material = VALUES(fee_with_material),
                        fee_without_material = VALUES(fee_without_material)
                ");
                $stmt->execute([$atcId, $courseId, $finalFee, $feeWith, $feeWithout]);

                $status = $finalFee > 0 ? 'Active' : 'Inactive';
                echo json_encode([
                    'success'              => true,
                    'message'              => 'Fees updated. Course is now ' . $status . ' for your centre.',
                    'fee_with_material'    => $feeWith,
                    'fee_without_material' => $feeWithout,
                    'final_fee'            => $finalFee,
                    'status'               => $status,
                ]);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ── Fetch courses for this ATC's center type ──────────────────────────────────
$searchTerm  = trim($_GET['search'] ?? '');
$typeFilter  = $_GET['type'] ?? 'all';   // 'all' | 'active' | 'inactive'

if (empty($allowedTypes)) {
    // No matching center type — show all master courses
    $allowedTypes = ['Abacus', 'Vedic Maths', 'IT'];
}

// Build placeholders
$phList  = implode(',', array_fill(0, count($allowedTypes), '?'));

$sql = "
    SELECT c.id, c.course_name, c.course_type, c.duration,
           c.material_type, c.material_language,
           c.ho_share_with_material, c.ho_share_without_material, c.ho_share,
           c.dlc_share_with_material, c.dlc_share_without_material,
           c.status AS master_status,
           acf.final_fee AS my_fee,
           acf.fee_with_material,
           acf.fee_without_material
    FROM courses c
    LEFT JOIN atc_course_fees acf ON acf.course_id = c.id AND acf.atc_id = ?
    WHERE c.status = 'Active'
      AND c.course_type IN ($phList)
";
$params = [$atcId, ...$allowedTypes];

if ($searchTerm) {
    $sql     .= " AND c.course_name LIKE ?";
    $params[] = "%$searchTerm%";
}

$sql .= " ORDER BY c.course_type ASC, c.course_name ASC";

$stmt    = $pdo->prepare($sql);
$stmt->execute($params);
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Tag each course with active status for this ATC
foreach ($courses as &$c) {
    $feeWith    = floatval($c['fee_with_material'] ?? 0);
    $feeWithout = floatval($c['fee_without_material'] ?? 0);
    $legacyFee  = floatval($c['my_fee'] ?? 0);
    $hoWith     = floatval($c['ho_share_with_material'] ?? 0);
    $hoWithout  = floatval($c['ho_share_without_material'] ?? 0);
    $legacyHo   = floatval($c['ho_share'] ?? 0);
    if ($feeWith <= 0 && $feeWithout <= 0 && $legacyFee > 0) {
        if (($c['material_type'] ?? '') === 'With Material') {
            $feeWith = $legacyFee;
        } else {
            $feeWithout = $legacyFee;
        }
    }
    if ($hoWith <= 0 && $hoWithout <= 0 && $legacyHo > 0) {
        if (($c['material_type'] ?? '') === 'With Material') {
            $hoWith = $legacyHo;
        } else {
            $hoWithout = $legacyHo;
        }
    }
    $c['fee_with_material']    = $feeWith;
    $c['fee_without_material'] = $feeWithout;
    // Active only if at least one material option has both HO share + ATC fee
    $c['atc_active'] = ($feeWith > 0 && $hoWith > 0) || ($feeWithout > 0 && $hoWithout > 0);
}
unset($c);

// Filter by type if requested
$filteredCourses = $courses;
if ($typeFilter === 'active') {
    $filteredCourses = array_values(array_filter($courses, fn($c) => $c['atc_active']));
} elseif ($typeFilter === 'inactive') {
    $filteredCourses = array_values(array_filter($courses, fn($c) => !$c['atc_active']));
} else {
    $filteredCourses = array_values($courses);
}

$totalCount    = count($courses);
$activeCount   = count(array_filter($courses, fn($c) => $c['atc_active']));
$inactiveCount = $totalCount - $activeCount;

// Paginate filtered list
$pagerParams = paginationParams(25);
$pager = paginationMeta(count($filteredCourses), $pagerParams);
$filteredCourses = array_slice($filteredCourses, $pager['offset'], $pager['per_page']);

$typeColors = [
    'Abacus'      => ['bg' => '#eff6ff', 'text' => '#2563eb', 'border' => '#bfdbfe'],
    'Vedic Maths' => ['bg' => '#fdf4ff', 'text' => '#9333ea', 'border' => '#e9d5ff'],
    'IT'          => ['bg' => '#f0fdf4', 'text' => '#16a34a', 'border' => '#bbf7d0'],
];
$qsSearch = $searchTerm !== '' ? '&search=' . urlencode($searchTerm) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fees Structure — ATC | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💰</text></svg>">
    <style>
        /* ── Info Banner ── */
        .info-banner {
            background: linear-gradient(135deg, #eef2ff, #f5f3ff);
            border: 1.5px solid #c7d2fe;
            border-radius: 14px;
            padding: 1rem 1.25rem;
            display: flex; gap: .875rem; align-items: flex-start;
            margin-bottom: 1.5rem;
        }
        .info-banner svg { flex-shrink:0; color:#4361ee; width:20px; height:20px; margin-top:2px; }
        .info-banner-text { font-size:.875rem; color:#3730a3; line-height:1.6; }
        .info-banner-text strong { font-weight:800; }

        /* ── Stats summary ── */
        .fee-stats {
            display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap;
        }
        .fee-stat {
            background: var(--bg-surface);
            border: 1.5px solid var(--border-color);
            border-radius: 12px;
            padding: .75rem 1.25rem;
            display: flex; align-items: center; gap: .75rem;
            text-decoration: none;
            transition: all .2s;
            cursor: pointer;
        }
        .fee-stat:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
        .fee-stat.active-tab { border-color: #4361ee; background: #eef2ff; }
        .fee-stat-icon {
            width: 36px; height: 36px;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
        }
        .fee-stat-icon svg { width: 18px; height: 18px; }
        .fee-stat-num  { font-size: 1.4rem; font-weight: 800; letter-spacing: -.02em; line-height:1; }
        .fee-stat-lbl  { font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: var(--text-muted); margin-top:.15rem; }

        .fee-stat.s-all    .fee-stat-icon { background: #eef2ff; color: #4361ee; }
        .fee-stat.s-active .fee-stat-icon { background: #d1fae5; color: #059669; }
        .fee-stat.s-inactive .fee-stat-icon { background: #f3f4f6; color: #6b7280; }

        /* ── Type badge ── */
        .type-badge {
            display: inline-flex; align-items: center;
            padding: .2rem .6rem; border-radius: 999px;
            font-size: .7rem; font-weight: 700; border: 1px solid;
        }

        .share-stack { display:flex; flex-direction:column; gap:.3rem; align-items:flex-start; }
        .share-chip {
            display:inline-flex; align-items:center; gap:.3rem;
            min-width:5.5rem; padding:.28rem .55rem; border-radius:8px;
            font-size:.72rem; font-weight:800; border:1px solid transparent;
        }
        .share-chip .tag {
            min-width:1.45rem; text-align:center; font-size:.6rem; font-weight:800;
            padding:.05rem .25rem; border-radius:4px; background:rgba(255,255,255,.55);
        }
        .share-chip.ho-w  { background:#ede9fe; color:#5b21b6; border-color:#ddd6fe; }
        .share-chip.ho-wo { background:#d1fae5; color:#065f46; border-color:#a7f3d0; }
        .share-chip.dlc-w  { background:#ffedd5; color:#9a3412; border-color:#fed7aa; }
        .share-chip.dlc-wo { background:#fef3c7; color:#92400e; border-color:#fde68a; }
        .share-chip.is-zero { background:#f8fafc; color:#64748b; border-color:#e2e8f0; font-weight:700; }
        .share-chip.is-zero .tag { background:#e2e8f0; color:#475569; }
        #feesTable td { vertical-align: middle; }

        /* ── Inline fee input ── */
        .fee-row { display: flex; align-items: center; gap: .5rem; }
        .fee-input {
            width: 110px; height: 36px;
            padding: 0 .65rem;
            border: 1.5px solid #d1d5db;
            border-radius: 9px;
            font-size: .875rem; font-weight: 700;
            font-family: inherit; color: #1f2937;
            transition: border .2s, box-shadow .2s;
            text-align: right;
        }
        .fee-input:focus { outline: none; border-color: #4361ee; box-shadow: 0 0 0 3px rgba(67,97,238,.1); }
        .fee-input.changed { border-color: #f59e0b; background: #fffbeb; }

        .btn-save-fee {
            display: inline-flex; align-items: center; gap: .3rem;
            padding: .45rem .85rem;
            border: none; border-radius: 8px;
            background: #4361ee; color: #fff;
            font-size: .75rem; font-weight: 800;
            cursor: pointer; white-space: nowrap;
            transition: all .2s; font-family: inherit;
        }
        .btn-save-fee:hover { background: #3050dd; transform: translateY(-1px); }
        .btn-save-fee:disabled { background: #9ca3af; cursor: default; transform: none; }
        .btn-save-fee svg { width: 13px; height: 13px; }

        .fee-zero-hint { font-size: .68rem; color: #9ca3af; margin-top: .2rem; font-style: italic; }

        /* ── Status pill in table ── */
        .atc-status {
            display: inline-flex; align-items: center; gap: .3rem;
            padding: .25rem .7rem; border-radius: 999px;
            font-size: .72rem; font-weight: 700;
        }
        .atc-status::before { content:''; width:6px; height:6px; border-radius:50%; display:block; }
        .atc-status.active { background:#d1fae5; color:#065f46; }
        .atc-status.active::before { background:#059669; }
        .atc-status.inactive { background:#f3f4f6; color:#6b7280; }
        .atc-status.inactive::before { background:#9ca3af; }

        /* ── Toast ── */
        #feeToast {
            position: fixed; bottom: 1.5rem; right: 1.5rem;
            background: #10b981; color: #fff;
            padding: .8rem 1.4rem; border-radius: 12px;
            font-size: .875rem; font-weight: 700;
            box-shadow: 0 8px 24px rgba(0,0,0,.15);
            z-index: 9999;
            display: none;
            align-items: center; gap: .5rem;
        }
        #feeToast.error { background: #ef4444; }
        #feeToast.show { display: flex; animation: toastIn .3s ease; }

        @keyframes toastIn {
            from { opacity:0; transform:translateY(12px); }
            to   { opacity:1; transform:translateY(0); }
        }

        .center-type-header {
            font-size: .75rem; font-weight: 800;
            text-transform: uppercase; letter-spacing: .06em;
            color: var(--text-muted);
            padding: .5rem .75rem;
            background: var(--gray-50);
            border-bottom: 1.5px solid var(--border-color);
            grid-column: 1/-1;
        }

        .no-courses-tip {
            text-align: center; padding: 3rem 2rem;
        }
        .no-courses-tip svg { width: 48px; height: 48px; stroke: #c7d2fe; margin: 0 auto 1rem; display:block; }
        .no-courses-tip h4 { font-size: 1.05rem; font-weight: 700; color: var(--text-primary); margin-bottom:.4rem; }
        .no-courses-tip p  { font-size: .875rem; color: var(--text-muted); }
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
                    <h2>Fees Structure</h2>
                    <p>Set your centre's course fees — fee &gt; 0 enables the course</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">

            <!-- Info Banner -->
            <div class="info-banner">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                <div class="info-banner-text">
                    Showing courses for your centre type: <strong><?= htmlspecialchars($centerType ?: 'All') ?></strong>.
                    Set fees for <strong>With Material</strong> and/or <strong>Without Material</strong>.
                    If either fee is <strong>&gt; ₹0</strong>, the course becomes <strong>Active</strong> and appears in admissions with both options.
                    Set both to <strong>₹0</strong> to deactivate the course for your centre.
                </div>
            </div>

            <!-- Stats -->
            <div class="fee-stats">
                <a href="?type=all<?= $qsSearch ?>" class="fee-stat s-all <?= $typeFilter==='all'?'active-tab':'' ?>" style="cursor:pointer;text-decoration:none">
                    <div class="fee-stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                    </div>
                    <div>
                        <div class="fee-stat-num"><?= $totalCount ?></div>
                        <div class="fee-stat-lbl">Total Courses</div>
                    </div>
                </a>
                <a href="?type=active<?= $qsSearch ?>" class="fee-stat s-active <?= $typeFilter==='active'?'active-tab':'' ?>" style="cursor:pointer;text-decoration:none">
                    <div class="fee-stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <div class="fee-stat-num" style="color:#059669"><?= $activeCount ?></div>
                        <div class="fee-stat-lbl">Active (Fee Set)</div>
                    </div>
                </a>
                <a href="?type=inactive<?= $qsSearch ?>" class="fee-stat s-inactive <?= $typeFilter==='inactive'?'active-tab':'' ?>" style="cursor:pointer;text-decoration:none">
                    <div class="fee-stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    </div>
                    <div>
                        <div class="fee-stat-num" style="color:#6b7280"><?= $inactiveCount ?></div>
                        <div class="fee-stat-lbl">Not Active</div>
                    </div>
                </a>

                <!-- Search -->
                <div style="margin-left:auto; display:flex; align-items:center;">
                    <form method="GET" class="search-bar" style="display:flex;align-items:center">
                        <input type="hidden" name="type" value="<?= htmlspecialchars($typeFilter) ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        <input type="text" name="search" id="searchInput" placeholder="Search courses…" value="<?= htmlspecialchars($searchTerm) ?>">
                    </form>
                </div>
            </div>

            <!-- Table -->
            <div class="table-card">
                <table class="data-table" id="feesTable">
                    <thead>
                        <tr>
                            <th>Course Name</th>
                            <th>Type</th>
                            <th>Duration</th>
                            <th>HO Share</th>
                            <th>DLC Share</th>
                            <th>Your Fee — With Material (₹)</th>
                            <th>Your Fee — Without Material (₹)</th>
                            <th style="text-align:center">Status</th>
                            <th style="text-align:center">Save</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($filteredCourses)): ?>
                        <tr><td colspan="9">
                            <div class="no-courses-tip">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                                <h4>No courses found</h4>
                                <p>No courses match your centre type (<strong><?= htmlspecialchars($centerType ?: 'Not Set') ?></strong>). Contact Head Office to add matching courses.</p>
                            </div>
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($filteredCourses as $c):
                            $tc  = $typeColors[$c['course_type']] ?? ['bg'=>'#f3f4f6','text'=>'#6b7280','border'=>'#e5e7eb'];
                            $feeWith    = floatval($c['fee_with_material'] ?? 0);
                            $feeWithout = floatval($c['fee_without_material'] ?? 0);
                            $hoWith     = floatval($c['ho_share_with_material'] ?? 0);
                            $hoWithout  = floatval($c['ho_share_without_material'] ?? 0);
                            $dlcWith    = floatval($c['dlc_share_with_material'] ?? 0);
                            $dlcWithout = floatval($c['dlc_share_without_material'] ?? 0);
                            if ($hoWith <= 0 && $hoWithout <= 0 && floatval($c['ho_share'] ?? 0) > 0) {
                                if (($c['material_type'] ?? '') === 'With Material') $hoWith = floatval($c['ho_share']);
                                else $hoWithout = floatval($c['ho_share']);
                            }
                            $isActive = ($feeWith > 0 && $hoWith > 0) || ($feeWithout > 0 && $hoWithout > 0);
                        ?>
                        <tr data-course-id="<?= $c['id'] ?>" 
                            data-active="<?= $isActive ? '1' : '0' ?>"
                            data-type="<?= htmlspecialchars($c['course_type']) ?>">
                            <td>
                                <div class="cell-name"><?= htmlspecialchars($c['course_name']) ?></div>
                            </td>
                            <td>
                                <span class="type-badge" style="background:<?= $tc['bg'] ?>;color:<?= $tc['text'] ?>;border-color:<?= $tc['border'] ?>">
                                    <?= htmlspecialchars($c['course_type']) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($c['duration'] ?: '—') ?></td>
                            <td>
                                <div class="share-stack">
                                    <span class="share-chip ho-w <?= $hoWith <= 0 ? 'is-zero' : '' ?>"><span class="tag">W</span>₹<?= number_format($hoWith, 0) ?></span>
                                    <span class="share-chip ho-wo <?= $hoWithout <= 0 ? 'is-zero' : '' ?>"><span class="tag">WO</span>₹<?= number_format($hoWithout, 0) ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="share-stack">
                                    <span class="share-chip dlc-w <?= $dlcWith <= 0 ? 'is-zero' : '' ?>"><span class="tag">W</span>₹<?= number_format($dlcWith, 0) ?></span>
                                    <span class="share-chip dlc-wo <?= $dlcWithout <= 0 ? 'is-zero' : '' ?>"><span class="tag">WO</span>₹<?= number_format($dlcWithout, 0) ?></span>
                                </div>
                            </td>
                            <td>
                                <div class="fee-row">
                                    <input type="number" 
                                           class="fee-input" 
                                           id="fee_with_<?= $c['id'] ?>"
                                           value="<?= $feeWith ?>" 
                                           min="0" step="1"
                                           data-original="<?= $feeWith ?>"
                                           onchange="onFeeChange(<?= $c['id'] ?>)"
                                           oninput="onFeeChange(<?= $c['id'] ?>)"
                                           placeholder="0"
                                           <?= $hoWith <= 0 ? 'disabled title="HO share for With Material is ₹0 — not offered"' : '' ?>>
                                </div>
                                <div class="fee-zero-hint"><?= $hoWith <= 0 ? 'Not offered (HO W = ₹0)' : 'With books / kit' ?></div>
                            </td>
                            <td>
                                <div class="fee-row">
                                    <input type="number" 
                                           class="fee-input" 
                                           id="fee_without_<?= $c['id'] ?>"
                                           value="<?= $feeWithout ?>" 
                                           min="0" step="1"
                                           data-original="<?= $feeWithout ?>"
                                           onchange="onFeeChange(<?= $c['id'] ?>)"
                                           oninput="onFeeChange(<?= $c['id'] ?>)"
                                           placeholder="0"
                                           <?= $hoWithout <= 0 ? 'disabled title="HO share for Without Material is ₹0 — not offered"' : '' ?>>
                                </div>
                                <div class="fee-zero-hint"><?= $hoWithout <= 0 ? 'Not offered (HO WO = ₹0)' : 'No material' ?></div>
                            </td>
                            <td style="text-align:center">
                                <span class="atc-status <?= $isActive ? 'active' : 'inactive' ?>" id="status_<?= $c['id'] ?>">
                                    <?= $isActive ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td style="text-align:center">
                                <button class="btn-save-fee" 
                                        id="savebtn_<?= $c['id'] ?>"
                                        onclick="saveFee(<?= $c['id'] ?>)"
                                        disabled>
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                                    Save
                                </button>
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

<!-- Toast -->
<div id="feeToast">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><polyline points="20 6 9 17 4 12"/></svg>
    <span id="feeToastMsg">Saved!</span>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
// Search submits via Enter (form GET). Keep live filter within current page as bonus.
document.getElementById('searchInput')?.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') this.form?.submit();
});

// ── Fee change handler ────────────────────────────────────────────────────────
function onFeeChange(courseId) {
    const inputWith    = document.getElementById('fee_with_' + courseId);
    const inputWithout = document.getElementById('fee_without_' + courseId);
    const saveBtn      = document.getElementById('savebtn_' + courseId);

    const origWith    = parseFloat(inputWith.dataset.original) || 0;
    const origWithout = parseFloat(inputWithout.dataset.original) || 0;
    const curWith     = parseFloat(inputWith.value) || 0;
    const curWithout  = parseFloat(inputWithout.value) || 0;

    const changed = (curWith !== origWith) || (curWithout !== origWithout);
    inputWith.classList.toggle('changed', curWith !== origWith);
    inputWithout.classList.toggle('changed', curWithout !== origWithout);
    saveBtn.disabled = !changed;
}

// ── Save fee ─────────────────────────────────────────────────────────────────
async function saveFee(courseId) {
    const inputWith     = document.getElementById('fee_with_' + courseId);
    const inputWithout  = document.getElementById('fee_without_' + courseId);
    const saveBtn       = document.getElementById('savebtn_' + courseId);
    const statusBadge   = document.getElementById('status_' + courseId);
    const feeWith       = parseFloat(inputWith.value) || 0;
    const feeWithout    = parseFloat(inputWithout.value) || 0;

    saveBtn.disabled = true;
    saveBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px;animation:spin 1s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Saving…';
    saveBtn.style.background = '#6b7280';

    try {
        const fd = new FormData();
        fd.append('action', 'set_fee');
        fd.append('course_id', courseId);
        fd.append('fee_with_material', feeWith);
        fd.append('fee_without_material', feeWithout);

        const res  = await fetch('', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            inputWith.dataset.original = feeWith;
            inputWithout.dataset.original = feeWithout;
            inputWith.classList.remove('changed');
            inputWithout.classList.remove('changed');

            const isActive = data.status === 'Active';
            statusBadge.className = 'atc-status ' + (isActive ? 'active' : 'inactive');
            statusBadge.textContent = isActive ? 'Active' : 'Inactive';

            const row = inputWith.closest('tr');
            if (row) row.dataset.active = isActive ? '1' : '0';

            showToast(data.message, 'success');
        } else {
            showToast(data.message, 'error');
        }
    } catch (e) {
        showToast('Network error — please try again.', 'error');
    } finally {
        saveBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg> Save`;
        saveBtn.style.background = '';
        onFeeChange(courseId);
    }
}

// ── Toast ─────────────────────────────────────────────────────────────────────
let toastTimer;
function showToast(msg, type = 'success') {
    const el  = document.getElementById('feeToast');
    const msg_el = document.getElementById('feeToastMsg');
    msg_el.textContent = msg;
    el.className = 'show' + (type === 'error' ? ' error' : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.className = ''; }, 3500);
}

// Spinner animation
const style = document.createElement('style');
style.textContent = `@keyframes spin { to { transform: rotate(360deg); } }`;
document.head.appendChild(style);
</script>
</body>
</html>
