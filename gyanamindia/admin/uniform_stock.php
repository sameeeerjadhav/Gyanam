<?php
/**
 * Gyanam Portal — Admin: Sock & Stationery Stock Management
 * View which ATC needs how many t-shirts with student names and sizes
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());

// Fetch sock requirements by ATC
$atcFilter = $_GET['atc'] ?? 'all';
$sizeFilter = $_GET['size'] ?? 'all';

$sql = "SELECT 
    a.id as admission_id,
    a.roll_no,
    a.first_name,
    a.middle_name,
    a.last_name,
    a.course,
    a.uniform_size,
    a.mobile,
    a.admission_date,
    a.status,
    atc.id as atc_id,
    atc.name as atc_name,
    atc.district as atc_district,
    CASE WHEN mds.admission_id IS NOT NULL THEN 'Dispatched' ELSE 'Pending' END AS dispatch_status,
    md.dispatch_id, md.dispatch_date, md.postal_service
FROM admissions a
INNER JOIN atc_centers atc ON a.atc_id = atc.id
LEFT JOIN material_dispatch_students mds ON mds.admission_id = a.id
LEFT JOIN material_dispatches md ON md.id = mds.dispatch_id
WHERE a.course LIKE '%Abacus%' 
AND a.status = 'Active'
AND a.uniform_size IS NOT NULL
AND a.uniform_size != ''";

$params = [];

if ($atcFilter !== 'all') {
    $sql .= " AND a.atc_id = ?";
    $params[] = $atcFilter;
}

if ($sizeFilter !== 'all') {
    $sql .= " AND a.uniform_size = ?";
    $params[] = $sizeFilter;
}

$sql .= " ORDER BY atc.name ASC, a.uniform_size ASC, a.first_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get ATC centers for filter
$stmt = $pdo->query("SELECT id, name FROM atc_centers WHERE status = 'Active' ORDER BY name");
$atcCenters = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$totalStudents = count($students);
$sizeBreakdown = [];
$atcBreakdown = [];

foreach ($students as $student) {
    $size = $student['uniform_size'];
    $atcId = $student['atc_id'];
    $atcName = $student['atc_name'];
    
    // Size breakdown
    if (!isset($sizeBreakdown[$size])) {
        $sizeBreakdown[$size] = 0;
    }
    $sizeBreakdown[$size]++;
    
    // ATC breakdown
    if (!isset($atcBreakdown[$atcId])) {
        $atcBreakdown[$atcId] = [
            'name' => $atcName,
            'district' => $student['atc_district'],
            'total' => 0,
            'dispatched' => 0,
            'sizes' => []
        ];
    }
    $atcBreakdown[$atcId]['total']++;
    if ($student['dispatch_status'] === 'Dispatched') $atcBreakdown[$atcId]['dispatched']++;
    
    if (!isset($atcBreakdown[$atcId]['sizes'][$size])) {
        $atcBreakdown[$atcId]['sizes'][$size] = 0;
    }
    $atcBreakdown[$atcId]['sizes'][$size]++;
}

$dispatchedCount = count(array_filter($students, fn($s) => $s['dispatch_status'] === 'Dispatched'));
$pendingDispatch  = $totalStudents - $dispatchedCount;

// Stationery stock data
$stationerySql = "SELECT 
    a.id as admission_id,
    a.roll_no,
    a.first_name,
    a.middle_name,
    a.last_name,
    a.course,
    a.material_type,
    a.material_language,
    a.mobile,
    a.admission_date,
    a.status,
    atc.id as atc_id,
    atc.name as atc_name,
    atc.district as atc_district,
    CASE WHEN mds.admission_id IS NOT NULL THEN 'Dispatched' ELSE 'Pending' END AS dispatch_status,
    md.dispatch_id, md.dispatch_date
FROM admissions a
INNER JOIN atc_centers atc ON a.atc_id = atc.id
LEFT JOIN material_dispatch_students mds ON mds.admission_id = a.id
LEFT JOIN material_dispatches md ON md.id = mds.dispatch_id
WHERE a.status = 'Active'
AND a.material_type = 'With Material'";

$stationeryParams = [];
if ($atcFilter !== 'all') {
    $stationerySql .= " AND a.atc_id = ?";
    $stationeryParams[] = $atcFilter;
}

$stationerySql .= " ORDER BY atc.name ASC, a.first_name ASC";

$stationeryStmt = $pdo->prepare($stationerySql);
$stationeryStmt->execute($stationeryParams);
$stationeryStudents = $stationeryStmt->fetchAll(PDO::FETCH_ASSOC);

$stationeryTotal = count($stationeryStudents);
$stationeryDispatched = count(array_filter($stationeryStudents, fn($s) => $s['dispatch_status'] === 'Dispatched'));
$stationeryPending = $stationeryTotal - $stationeryDispatched;

// Unified student stock list (single-page view)
$studentStockList = [];
foreach ($students as $student) {
    $studentStockList[] = [
        'stock_type' => 'sock',
        'roll_no' => $student['roll_no'],
        'first_name' => $student['first_name'],
        'middle_name' => $student['middle_name'] ?? '',
        'last_name' => $student['last_name'],
        'course' => $student['course'],
        'atc_name' => $student['atc_name'],
        'atc_district' => $student['atc_district'],
        'dispatch_status' => $student['dispatch_status'],
        'mobile' => $student['mobile'],
        'admission_date' => $student['admission_date'],
        'stock_detail' => 'Size ' . ($student['uniform_size'] ?: '—')
    ];
}

foreach ($stationeryStudents as $student) {
    $studentStockList[] = [
        'stock_type' => 'stationery',
        'roll_no' => $student['roll_no'],
        'first_name' => $student['first_name'],
        'middle_name' => $student['middle_name'] ?? '',
        'last_name' => $student['last_name'],
        'course' => $student['course'],
        'atc_name' => $student['atc_name'],
        'atc_district' => $student['atc_district'],
        'dispatch_status' => $student['dispatch_status'],
        'mobile' => $student['mobile'],
        'admission_date' => $student['admission_date'],
        'stock_detail' => ($student['material_type'] ?: 'With Material') . ' • ' . ($student['material_language'] ?: 'N/A')
    ];
}


// Sort sizes in proper order
$sizeOrder = ['36', '38', '40', '42', '44', '46'];
uksort($sizeBreakdown, function($a, $b) use ($sizeOrder) {
    $posA = array_search($a, $sizeOrder);
    $posB = array_search($b, $sizeOrder);
    return $posA - $posB;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sock & Stationery Stock — Admin | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>👕</text></svg>">
    
    <style>
        /* ===== CSS VARIABLES ===== */
        :root {
            --font-primary: 'Sora', sans-serif;
            --font-mono: 'JetBrains Mono', monospace;

            --color-bg: #f0f2f7;
            --color-surface: #ffffff;
            --color-surface-raised: #f8f9fc;
            --color-border: #e4e8f0;
            --color-border-strong: #cdd3e0;

            --color-text-primary: #0f1523;
            --color-text-secondary: #4a5568;
            --color-text-muted: #8896a5;

            --color-emerald: #00c48c;
            --color-emerald-dark: #00a376;
            --color-emerald-soft: #e6faf4;

            --color-indigo: #4f6ef7;
            --color-indigo-dark: #3a57e8;
            --color-indigo-soft: #eef1fe;

            --color-amber: #f59e0b;
            --color-amber-soft: #fffbeb;

            --color-rose: #f43f5e;
            --color-rose-soft: #fff1f3;

            --color-slate: #334155;
            --color-slate-soft: #f1f5f9;

            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.07), 0 2px 6px rgba(0,0,0,0.04);
            --shadow-lg: 0 12px 40px rgba(0,0,0,0.10), 0 4px 12px rgba(0,0,0,0.06);
            --shadow-glow-emerald: 0 8px 24px rgba(0,196,140,0.25);
            --shadow-glow-indigo: 0 8px 24px rgba(79,110,247,0.25);

            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 14px;
            --radius-xl: 20px;
            --radius-full: 9999px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: var(--font-primary);
            background: var(--color-bg);
            color: var(--color-text-primary);
        }

        /* ===== PAGE HEADER ===== */
        .page-header-block {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2rem;
            padding: 1.75rem 2rem;
            background: var(--color-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--color-border);
            box-shadow: var(--shadow-sm);
        }

        .page-header-left {
            display: flex;
            align-items: center;
            gap: 1.25rem;
        }

        .page-header-icon {
            width: 52px;
            height: 52px;
            background: linear-gradient(135deg, var(--color-indigo), var(--color-indigo-dark));
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-glow-indigo);
        }

        .page-header-icon svg {
            width: 26px;
            height: 26px;
            stroke: white;
        }

        .page-header-title {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--color-text-primary);
            letter-spacing: -0.03em;
            line-height: 1.2;
        }

        .page-header-subtitle {
            font-size: 0.875rem;
            color: var(--color-text-muted);
            margin-top: 0.2rem;
            font-weight: 400;
        }

        /* ===== STATS GRID ===== */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1.25rem;
            margin-bottom: 2rem;
        }

        .kpi-card {
            background: var(--color-surface);
            border-radius: var(--radius-xl);
            padding: 1.5rem 1.75rem;
            border: 1px solid var(--color-border);
            box-shadow: var(--shadow-sm);
            position: relative;
            overflow: hidden;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .kpi-card:hover {
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }

        .kpi-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            border-radius: var(--radius-xl) var(--radius-xl) 0 0;
        }

        .kpi-card.indigo::before { background: linear-gradient(90deg, var(--color-indigo), #818cf8); }
        .kpi-card.emerald::before { background: linear-gradient(90deg, var(--color-emerald), #34d399); }
        .kpi-card.amber::before { background: linear-gradient(90deg, var(--color-amber), #fbbf24); }

        .kpi-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 1.25rem;
        }

        .kpi-value {
            font-size: 2rem;
            font-weight: 800;
            color: var(--color-text-primary);
            letter-spacing: -0.04em;
            line-height: 1;
            margin-bottom: 0.4rem;
        }

        .kpi-label {
            font-size: 0.8rem;
            color: var(--color-text-muted);
            font-weight: 500;
            letter-spacing: 0.02em;
            text-transform: uppercase;
        }

        /* ===== FILTER PANEL ===== */
        .filter-panel {
            background: var(--color-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--color-border);
            box-shadow: var(--shadow-sm);
            margin-bottom: 2rem;
            overflow: hidden;
        }

        .filter-panel-body {
            padding: 1.5rem 1.75rem;
            display: grid;
            grid-template-columns: 2fr 1fr auto;
            gap: 1.25rem;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--color-text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .form-control {
            padding: 0.75rem 1rem;
            border: 1.5px solid var(--color-border);
            border-radius: var(--radius-md);
            font-size: 0.9rem;
            background: var(--color-surface-raised);
            color: var(--color-text-primary);
            font-weight: 500;
            font-family: var(--font-primary);
            transition: all 0.2s ease;
            outline: none;
            appearance: none;
            -webkit-appearance: none;
            width: 100%;
        }

        select.form-control {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238896a5' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.75rem center;
            background-size: 16px;
            padding-right: 2.5rem;
        }

        .form-control:focus {
            border-color: var(--color-indigo);
            background: var(--color-surface);
            box-shadow: 0 0 0 3px rgba(79,110,247,0.1);
        }

        .btn-primary {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, var(--color-indigo), var(--color-indigo-dark));
            border: none;
            border-radius: var(--radius-md);
            color: white;
            font-size: 0.9rem;
            font-weight: 700;
            cursor: pointer;
            font-family: var(--font-primary);
            transition: all 0.2s ease;
            box-shadow: var(--shadow-glow-indigo);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 32px rgba(79,110,247,0.3);
        }

        .btn-primary svg { width: 16px; height: 16px; }

        /* ===== SECTION HEADERS ===== */
        .section-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 2.5rem 0 1rem;
        }

        .section-head-left {
            display: flex;
            align-items: center;
            gap: 0.875rem;
        }

        .section-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }

        .section-dot.indigo { background: var(--color-indigo); }

        .section-title {
            font-size: 1.125rem;
            font-weight: 800;
            color: var(--color-text-primary);
            letter-spacing: -0.02em;
        }

        .section-count {
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0.25rem 0.625rem;
            background: var(--color-slate-soft);
            border-radius: var(--radius-full);
            color: var(--color-text-muted);
        }

        .stock-slider {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-full);
            padding: 0.35rem;
            box-shadow: var(--shadow-sm);
            margin-bottom: 1rem;
        }

        .stock-slider-btn {
            border: none;
            background: transparent;
            color: var(--color-text-secondary);
            font-size: 0.8rem;
            font-weight: 700;
            padding: 0.5rem 0.9rem;
            border-radius: var(--radius-full);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .stock-slider-btn.active {
            background: linear-gradient(135deg, var(--color-indigo), var(--color-indigo-dark));
            color: #fff;
            box-shadow: var(--shadow-glow-indigo);
        }
        
        /* ===== TABLE ===== */
        .table-wrap {
            background: var(--color-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--color-border);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .data-table thead {
            background: var(--color-surface-raised);
        }

        .data-table thead th {
            padding: 1rem 1.25rem;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--color-text-muted);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            border-bottom: 1px solid var(--color-border);
            white-space: nowrap;
        }

        .data-table tbody tr {
            border-bottom: 1px solid #f3f5f9;
            transition: background 0.15s ease;
        }

        .data-table tbody tr:hover { background: #fafbff; }
        .data-table tbody tr:last-child { border-bottom: none; }

        .data-table tbody td {
            padding: 1.1rem 1.25rem;
            color: var(--color-text-primary);
            font-size: 0.9rem;
        }

        /* ===== CELL HELPERS ===== */
        .cell-primary { font-weight: 600; color: var(--color-text-primary); }
        .cell-secondary { font-size: 0.8rem; color: var(--color-text-muted); margin-top: 0.15rem; }
        
        .student-avatar {
            width:38px; height:38px; border-radius:var(--radius-md); flex-shrink:0;
            display:inline-flex; align-items:center; justify-content:center;
            font-size:.875rem; font-weight:800; color:white;
            background:linear-gradient(135deg,var(--color-indigo),var(--color-indigo-dark));
            margin-right: 0.75rem; vertical-align: middle;
        }
        .student-cell-content { display: inline-block; vertical-align: middle; }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.3rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.78rem;
            font-weight: 700;
            background: var(--color-indigo-soft);
            color: var(--color-indigo-dark);
            border: 1px solid #c7d2fe;
            letter-spacing: 0.01em;
        }

        /* Empty state */
        .table-empty { text-align: center; padding: 3.5rem 2rem !important; }

        /* ATC Summary specific */
        .atc-summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2.5rem;
        }

        .atc-summary-card {
            background: var(--color-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--color-border);
            padding: 1.5rem;
            box-shadow: var(--shadow-sm);
        }

        .atc-summary-header {
            margin-bottom: 1.25rem;
        }
        
        .atc-summary-title {
            font-size: 1.1rem;
            font-weight: 800;
            color: var(--color-text-primary);
        }

        .atc-summary-location {
            font-size: 0.8rem;
            color: var(--color-text-muted);
            margin-top: 0.25rem;
        }

        .atc-summary-total {
            font-size: 1.75rem;
            font-weight: 800;
            color: var(--color-indigo);
            margin-bottom: 1rem;
        }

        .atc-summary-sizes {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .atc-summary-size {
            padding: 0.25rem 0.6rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 700;
            background: var(--color-slate-soft);
            color: var(--color-text-secondary);
            border: 1px solid var(--color-border);
        }

        @media print {
            .page-header-block, .filter-panel, .sidebar, .top-header { display:none !important; }
            .content-wrapper { padding: 0 !important; }
            body { background: white !important; }
            .table-wrap { border: none !important; box-shadow: none !important; }
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
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
                <div class="header-greeting">
                    <h2>Sock & Stationery Stock</h2>
                    <p>Track student requirements</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="page-content">

            <!-- PAGE HEADER -->
            <div class="page-header-block">
                <div class="page-header-left">
                    <div class="page-header-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.38 3.46L16 2a8 8 0 01-8 0L3.62 3.46a2 2 0 00-1.34 2.23l.58 3.47a1 1 0 00.99.84H6v10c0 1.1.9 2 2 2h8a2 2 0 002-2V10h2.15a1 1 0 00.99-.84l.58-3.47a2 2 0 00-1.34-2.23z"/></svg>
                    </div>
                    <div>
                        <h1 class="page-header-title">Sock Stock Management</h1>
                        <p class="page-header-subtitle">Track sock and stationery requirements for students across ATC centers</p>
                    </div>
                </div>
                <div class="page-header-right">
                    <button class="btn-primary" onclick="window.print()">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                        Print Report
                    </button>
                </div>
            </div>

            <!-- KPI GRID -->
            <div class="kpi-grid">
                <div class="kpi-card indigo">
                    <div class="kpi-top">
                        <div>
                            <div class="kpi-value"><?= number_format($totalStudents) ?></div>
                            <div class="kpi-label">Total Socks</div>
                        </div>
                    </div>
                </div>
                <!-- N: Dispatch KPIs -->
                <div class="kpi-card emerald">
                    <div class="kpi-top">
                        <div>
                            <div class="kpi-value"><?= number_format($dispatchedCount) ?></div>
                            <div class="kpi-label">Socks Dispatched</div>
                        </div>
                    </div>
                </div>
                <div class="kpi-card amber">
                    <div class="kpi-top">
                        <div>
                            <div class="kpi-value"><?= number_format($pendingDispatch) ?></div>
                            <div class="kpi-label">Pending Dispatch</div>
                        </div>
                    </div>
                </div>
                <?php 
                $kpiColors = ['emerald', 'amber', 'rose', 'indigo', 'emerald', 'amber', 'rose'];
                $colorIdx = 0;
                foreach ($sizeBreakdown as $size => $count): 
                ?>
                <div class="kpi-card <?= $kpiColors[$colorIdx % count($kpiColors)] ?>">
                    <div class="kpi-top">
                        <div>
                            <div class="kpi-value"><?= number_format($count) ?></div>
                            <div class="kpi-label">Size <?= htmlspecialchars($size) ?></div>
                        </div>
                    </div>
                </div>
                <?php $colorIdx++; endforeach; ?>
            </div>


            <!-- FILTERS -->
            <div class="filter-panel">
                <form method="GET" class="filter-panel-body">
                    <div class="filter-group">
                        <label class="filter-label">Filter by ATC Center</label>
                        <select name="atc" class="form-control" onchange="this.form.submit()">
                            <option value="all">All ATC Centers</option>
                            <?php foreach ($atcCenters as $atc): ?>
                                <option value="<?= htmlspecialchars($atc['id']) ?>" <?= $atcFilter == $atc['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($atc['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label class="filter-label">Sock Size</label>
                        <select name="size" class="form-control" onchange="this.form.submit()">
                            <option value="all">All Sizes</option>
                            <?php foreach ($sizeOrder as $size): ?>
                                <option value="<?= htmlspecialchars($size) ?>" <?= $sizeFilter == $size ? 'selected' : '' ?>>
                                    Size <?= htmlspecialchars($size) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="filter-group" style="padding-bottom: 2px;">
                        <button type="submit" class="btn-primary" style="height: 44px; display: inline-flex; justify-content: center;">
                            Apply Filters
                        </button>
                    </div>
                </form>
            </div>

            <!-- ATC-WISE SUMMARY CARDS -->
            <?php if (!empty($atcBreakdown)): ?>
            <div class="section-head">
                <div class="section-head-left">
                    <div class="section-dot indigo"></div>
                    <h3 class="section-title">ATC-wise Requirements</h3>
                    <span class="section-count"><?= count($atcBreakdown) ?> Centers</span>
                </div>
            </div>
            
            <div class="atc-summary-grid">
                <?php foreach ($atcBreakdown as $atcId => $atcData): ?>
                <div class="atc-summary-card">
                    <div class="atc-summary-header">
                        <div class="atc-summary-title"><?= htmlspecialchars($atcData['name']) ?></div>
                        <div class="atc-summary-location">📍 <?= htmlspecialchars($atcData['district']) ?></div>
                    </div>
                    <div class="atc-summary-total"><?= $atcData['total'] ?> <span style="font-size: 0.9rem; font-weight: 500; color: var(--color-text-muted);">T-Shirts</span></div>
                    <div style="font-size:.78rem;margin-bottom:.75rem">
                        <span style="color:#065f46;font-weight:700">✓ <?= $atcData['dispatched'] ?> dispatched</span>
                        <span style="color:#9ca3af;margin-left:.5rem">/ <?= $atcData['total'] - $atcData['dispatched'] ?> pending</span>
                    </div>
                    <div class="atc-summary-sizes">
                        <?php foreach ($atcData['sizes'] as $size => $count): ?>
                        <span class="atc-summary-size"><?= htmlspecialchars($size) ?>: <strong><?= $count ?></strong></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- SINGLE STUDENT LIST WITH SLIDE BAR -->
            <div class="section-head">
                <div class="section-head-left">
                    <div class="section-dot emerald"></div>
                    <h3 class="section-title">Student Stock List</h3>
                    <span class="section-count"><?= count($studentStockList) ?> Records</span>
                </div>
            </div>

            <div class="stock-slider" role="tablist" aria-label="Student stock filters">
                <button type="button" class="stock-slider-btn active" data-filter="all">All (<?= count($studentStockList) ?>)</button>
                <button type="button" class="stock-slider-btn" data-filter="sock">Sock (<?= count($students) ?>)</button>
                <button type="button" class="stock-slider-btn" data-filter="stationery">Stationery (<?= $stationeryTotal ?>)</button>
            </div>

            <div class="table-wrap" style="overflow-x:auto;">
                <table class="data-table" id="studentStockTable" style="min-width: 980px;">
                    <thead>
                        <tr>
                            <th>Student Details</th>
                            <th>ATC Center</th>
                            <th>Course</th>
                            <th>Stock Type</th>
                            <th>Details</th>
                            <th>Dispatch Status</th>
                            <th>Mobile</th>
                            <th>Admission Date</th>
                        </tr>
                    </thead>
                    <tbody id="studentStockBody">
                        <?php if (empty($studentStockList)): ?>
                            <tr>
                                <td colspan="8" class="table-empty">
                                    <div class="empty-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                    </div>
                                    <div class="empty-title">No student stock records found</div>
                                    <div class="empty-sub">Try adjusting your filters.</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($studentStockList as $row): 
                                $fullName = trim($row['first_name'] . ' ' . ($row['middle_name'] ?? '') . ' ' . $row['last_name']);
                                $initial = strtoupper(substr($row['first_name'], 0, 1));
                            ?>
                                <tr data-stock-type="<?= htmlspecialchars($row['stock_type']) ?>">
                                    <td>
                                        <div class="student-avatar"><?= $initial ?></div>
                                        <div class="student-cell-content">
                                            <div class="cell-primary"><?= htmlspecialchars($fullName) ?></div>
                                            <div class="cell-secondary"><?= htmlspecialchars($row['roll_no']) ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="cell-primary"><?= htmlspecialchars($row['atc_name']) ?></div>
                                        <div class="cell-secondary"><?= htmlspecialchars($row['atc_district']) ?></div>
                                    </td>
                                    <td>
                                        <div class="cell-primary"><?= htmlspecialchars($row['course']) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($row['stock_type'] === 'sock'): ?>
                                            <span class="badge">Sock</span>
                                        <?php else: ?>
                                            <span class="badge" style="background:#fff1f3;color:#be123c;">Stationery</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="cell-primary"><?= htmlspecialchars($row['stock_detail']) ?></div>
                                    </td>
                                    <td>
                                        <?php if ($row['dispatch_status'] === 'Dispatched'): ?>
                                            <span style="display:inline-flex;align-items:center;gap:.35rem;background:#ecfdf5;color:#065f46;padding:.25rem .7rem;border-radius:999px;font-size:.75rem;font-weight:700">✓ Dispatched</span>
                                        <?php else: ?>
                                            <span style="display:inline-flex;align-items:center;gap:.35rem;background:#fff7ed;color:#c2410c;padding:.25rem .7rem;border-radius:999px;font-size:.75rem;font-weight:700">⏳ Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="cell-mono"><?= htmlspecialchars($row['mobile']) ?></div>
                                    </td>
                                    <td>
                                        <div class="cell-primary"><?= !empty($row['admission_date']) ? date('d M Y', strtotime($row['admission_date'])) : '—' ?></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            </div> <!-- End page-content -->
        </div> <!-- End padding wrapper -->
    </main>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const sliderButtons = document.querySelectorAll('.stock-slider-btn');
    const tableRows = document.querySelectorAll('#studentStockBody tr[data-stock-type]');

    sliderButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const filter = button.getAttribute('data-filter');

            sliderButtons.forEach(function (btn) {
                btn.classList.remove('active');
            });
            button.classList.add('active');

            tableRows.forEach(function (row) {
                const rowType = row.getAttribute('data-stock-type');
                row.style.display = (filter === 'all' || rowType === filter) ? '' : 'none';
            });
        });
    });
});
</script>
</body>
</html>
