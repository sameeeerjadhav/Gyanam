<?php
/**
 * Gyanam Portal — Admin: Reports & Analytics
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());

// Date filters
$startDate = $_GET['start_date'] ?? date('Y-m-01'); // First day of current month
$endDate = $_GET['end_date'] ?? date('Y-m-d'); // Today
$dlcFilter = $_GET['dlc'] ?? 'all';

// Get overall statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(DISTINCT dlc.id) as total_dlc,
        COUNT(DISTINCT atc.id) as total_atc,
        COUNT(DISTINCT adm.id) as total_admissions,
        COUNT(DISTINCT inq.id) as total_inquiries
    FROM dlc_offices dlc
    LEFT JOIN atc_centers atc ON dlc.id = atc.dlc_id
    LEFT JOIN admissions adm ON atc.id = adm.atc_id
    LEFT JOIN inquiries inq ON atc.id = inq.atc_id
");
$overallStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get revenue statistics
$stmt = $pdo->query("
    SELECT 
        COALESCE(SUM(course_fees - discount_amount), 0) as total_revenue,
        COALESCE(SUM(fees_paid), 0) as total_collected,
        COALESCE(SUM(fees_pending), 0) as total_pending
    FROM admissions
    WHERE status = 'Active'
");
$revenueStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get DLC-wise revenue breakdown
$dlcRevenueQuery = "
    SELECT 
        dlc.id,
        dlc.name as dlc_name,
        dlc.district,
        COUNT(DISTINCT atc.id) as atc_count,
        COUNT(DISTINCT adm.id) as total_students,
        COALESCE(SUM(adm.course_fees - adm.discount_amount), 0) as total_revenue,
        COALESCE(SUM(adm.fees_paid), 0) as collected,
        COALESCE(SUM(adm.fees_pending), 0) as pending
    FROM dlc_offices dlc
    LEFT JOIN atc_centers atc ON dlc.id = atc.dlc_id AND atc.status = 'Active'
    LEFT JOIN admissions adm ON atc.id = adm.atc_id AND adm.status = 'Active'
";

if ($dlcFilter !== 'all') {
    $dlcRevenueQuery .= " WHERE dlc.id = " . intval($dlcFilter);
}

$dlcRevenueQuery .= " GROUP BY dlc.id ORDER BY collected DESC";

$stmt = $pdo->query($dlcRevenueQuery);
$dlcRevenue = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get dispatch statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(*) as total_dispatches,
        SUM(CASE WHEN status = 'Created' THEN 1 ELSE 0 END) as created,
        SUM(CASE WHEN status = 'Sent to DLC' THEN 1 ELSE 0 END) as sent_to_dlc,
        SUM(CASE WHEN status = 'Forwarded to ATC' THEN 1 ELSE 0 END) as forwarded_to_atc,
        SUM(CASE WHEN status = 'Delivered' THEN 1 ELSE 0 END) as delivered,
        SUM(quantity) as total_items
    FROM dispatches
");
$dispatchStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get material type breakdown
$stmt = $pdo->query("
    SELECT 
        material_type,
        COUNT(*) as dispatch_count,
        SUM(quantity) as total_quantity
    FROM dispatches
    GROUP BY material_type
    ORDER BY total_quantity DESC
");
$materialBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly revenue trend (last 6 months)
$stmt = $pdo->query("
    SELECT 
        DATE_FORMAT(admission_date, '%Y-%m') as month,
        COUNT(*) as admissions,
        SUM(course_fees - discount_amount) as revenue,
        SUM(fees_paid) as collected
    FROM admissions
    WHERE admission_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(admission_date, '%Y-%m')
    ORDER BY month ASC
");
$monthlyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get DLC offices for filter
$stmt = $pdo->query("SELECT id, name FROM dlc_offices WHERE status = 'Active' ORDER BY name");
$dlcOffices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get top performing ATCs
$stmt = $pdo->query("
    SELECT 
        atc.name as atc_name,
        dlc.name as dlc_name,
        COUNT(adm.id) as student_count,
        SUM(adm.fees_paid) as revenue_collected
    FROM atc_centers atc
    LEFT JOIN dlc_offices dlc ON atc.dlc_id = dlc.id
    LEFT JOIN admissions adm ON atc.id = adm.atc_id AND adm.status = 'Active'
    WHERE atc.status = 'Active'
    GROUP BY atc.id
    ORDER BY revenue_collected DESC
    LIMIT 10
");
$topATCs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics — Admin | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📊</text></svg>">
    <style>
    /* ═══════════════════════════════════════════
       DESIGN TOKENS
    ═══════════════════════════════════════════ */
    :root {
        --font:        'Sora', sans-serif;
        --mono:        'JetBrains Mono', monospace;
        --bg:          #f4f6fb;
        --surface:     #ffffff;
        --surface-2:   #f9fafc;
        --surface-3:   #f0f3f9;
        --border:      #e6eaf3;
        --border-2:    #d4dae8;
        --text:        #111827;
        --text-2:      #374151;
        --text-3:      #6b7280;
        --text-4:      #9ca3af;
        --brand:       #4361ee;
        --brand-dark:  #3451d1;
        --brand-light: #eef1fd;
        --brand-glow:  rgba(67,97,238,.18);
        --violet:      #7c3aed;
        --violet-soft: #f5f3ff;
        --sky:         #0ea5e9;
        --sky-soft:    #f0f9ff;
        --emerald:     #10b981;
        --emerald-dark:#059669;
        --emerald-soft:#ecfdf5;
        --amber:       #f59e0b;
        --amber-dark:  #d97706;
        --amber-soft:  #fffbeb;
        --rose:        #f43f5e;
        --rose-soft:   #fff1f3;
        --pink:        #ec4899;
        --pink-soft:   #fdf2f8;
        --shadow-xs:   0 1px 2px rgba(0,0,0,.05);
        --shadow-sm:   0 1px 4px rgba(0,0,0,.06), 0 2px 8px rgba(0,0,0,.04);
        --shadow-md:   0 4px 16px rgba(0,0,0,.08), 0 2px 6px rgba(0,0,0,.04);
        --shadow-lg:   0 20px 60px rgba(0,0,0,.12), 0 8px 20px rgba(0,0,0,.06);
        --shadow-brand:0 6px 20px var(--brand-glow);
        --r-xs:4px; --r-sm:6px; --r-md:10px;
        --r-lg:14px; --r-xl:18px; --r-2xl:24px; --r-full:9999px;
        --t:.18s ease;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); line-height: 1.5; -webkit-font-smoothing: antialiased; }
    .page-content { padding: 1.75rem 2rem; }

    /* ── Page header ── */
    .page-header-block { display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; margin-bottom:1.75rem; }
    .page-header-left  { display:flex; align-items:center; gap:1rem; }
    .page-header-icon  {
        width:50px; height:50px; border-radius:var(--r-lg);
        background:linear-gradient(135deg,var(--brand),var(--violet));
        display:flex; align-items:center; justify-content:center;
        box-shadow:var(--shadow-brand); flex-shrink:0;
    }
    .page-header-icon svg    { width:24px; height:24px; stroke:white; fill:none; }
    .page-header-title       { font-size:1.375rem; font-weight:800; color:var(--text); letter-spacing:-.03em; }
    .page-header-subtitle    { font-size:.8125rem; color:var(--text-3); margin-top:.2rem; }

    /* ── KPI Grid ── */
    .stats-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:1rem; margin-bottom:1.75rem; }
    .stat-card {
        background:var(--surface); border:1px solid var(--border);
        border-radius:var(--r-xl); padding:1.375rem 1.5rem 1.25rem;
        position:relative; overflow:hidden;
        box-shadow:var(--shadow-sm); transition:transform var(--t), box-shadow var(--t); cursor:default;
    }
    .stat-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); }
    .stat-card::before { content:''; position:absolute; top:0; left:0; bottom:0; width:4px; border-radius:var(--r-xl) 0 0 var(--r-xl); }
    .stat-card::after  { content:''; position:absolute; right:-20px; top:-20px; width:90px; height:90px; border-radius:50%; opacity:.055; pointer-events:none; }
    .stat-card.brand::before   { background:linear-gradient(180deg,var(--brand),#818cf8); }
    .stat-card.emerald::before { background:linear-gradient(180deg,var(--emerald),#34d399); }
    .stat-card.amber::before   { background:linear-gradient(180deg,var(--amber),#fcd34d); }
    .stat-card.sky::before     { background:linear-gradient(180deg,var(--sky),#38bdf8); }
    .stat-card.violet::before  { background:linear-gradient(180deg,var(--violet),#a78bfa); }
    .stat-card.brand::after    { background:var(--brand);   }
    .stat-card.emerald::after  { background:var(--emerald); }
    .stat-card.amber::after    { background:var(--amber);   }
    .stat-card.sky::after      { background:var(--sky);     }
    .stat-card.violet::after   { background:var(--violet);  }
    .stat-top   { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:.875rem; }
    .stat-icon  { width:42px; height:42px; border-radius:var(--r-md); display:flex; align-items:center; justify-content:center; }
    .stat-icon svg { width:20px; height:20px; fill:none; }
    .stat-icon.brand   { background:var(--brand-light); }  .stat-icon.brand   svg { stroke:var(--brand); }
    .stat-icon.emerald { background:var(--emerald-soft); } .stat-icon.emerald svg { stroke:var(--emerald-dark); }
    .stat-icon.amber   { background:var(--amber-soft);  }  .stat-icon.amber   svg { stroke:var(--amber-dark); }
    .stat-icon.sky     { background:var(--sky-soft);    }  .stat-icon.sky     svg { stroke:var(--sky); }
    .stat-icon.violet  { background:var(--violet-soft); }  .stat-icon.violet  svg { stroke:var(--violet); }
    .stat-value { font-size:2rem; font-weight:800; color:var(--text); letter-spacing:-.05em; line-height:1; }
    .stat-label { font-size:.72rem; font-weight:600; color:var(--text-3); text-transform:uppercase; letter-spacing:.07em; margin-top:.375rem; }

    /* Large stat cards for revenue */
    .stat-card-large {
        background:var(--surface); border:1px solid var(--border);
        border-radius:var(--r-xl); padding:1.75rem 2rem;
        display:flex; align-items:center; gap:1.5rem;
        box-shadow:var(--shadow-sm); transition:transform var(--t), box-shadow var(--t);
        position:relative; overflow:hidden;
    }
    .stat-card-large:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); }
    .stat-card-large::before { content:''; position:absolute; top:0; left:0; bottom:0; width:4px; border-radius:var(--r-xl) 0 0 var(--r-xl); }
    .stat-card-large.pink::before    { background:linear-gradient(180deg,var(--pink),#db2777); }
    .stat-card-large.emerald::before { background:linear-gradient(180deg,var(--emerald),#34d399); }
    .stat-card-large.amber::before   { background:linear-gradient(180deg,var(--amber),#fcd34d); }
    .stat-icon-large {
        width:64px; height:64px; border-radius:var(--r-lg);
        display:flex; align-items:center; justify-content:center; flex-shrink:0;
    }
    .stat-icon-large svg { width:28px; height:28px; stroke:white; fill:none; }
    .stat-content-large { flex:1; }
    .stat-label-large { font-size:.8125rem; font-weight:600; color:var(--text-3); text-transform:uppercase; letter-spacing:.05em; }
    .stat-value-large { font-size:2.125rem; font-weight:800; color:var(--text); letter-spacing:-.05em; margin:.375rem 0 .25rem; }
    .stat-sub-large { font-size:.8125rem; color:var(--text-3); }

    /* ── Section headers ── */
    .section-header {
        display:flex; align-items:center; justify-content:space-between;
        margin:2.5rem 0 1.25rem; gap:1rem; flex-wrap:wrap;
    }
    .section-header h3 {
        display:flex; align-items:center; gap:.625rem;
        font-size:1.0625rem; font-weight:800; color:var(--text); letter-spacing:-.02em;
    }
    .section-header h3 svg { width:20px; height:20px; stroke:var(--text-2); fill:none; }
    .btn-secondary {
        display:inline-flex; align-items:center; gap:.5rem;
        padding:.65rem 1.25rem; background:var(--surface); border:1.5px solid var(--border);
        border-radius:var(--r-md); font-size:.85rem; font-weight:600;
        font-family:var(--font); color:var(--text-2); cursor:pointer; transition:all var(--t);
    }
    .btn-secondary:hover { border-color:var(--brand); color:var(--brand); background:var(--brand-light); }
    .btn-secondary svg { width:15px; height:15px; stroke:currentColor; fill:none; }

    /* ── Filters ── */
    .filters-card {
        background:var(--surface); border:1px solid var(--border);
        border-radius:var(--r-xl); padding:1.5rem 1.75rem;
        box-shadow:var(--shadow-sm); margin-bottom:1.75rem;
    }
    .filters-form { display:flex; gap:1rem; align-items:flex-end; flex-wrap:wrap; }
    .filter-group { display:flex; flex-direction:column; gap:.5rem; flex:1; min-width:180px; }
    .filter-group label { font-size:.8125rem; font-weight:600; color:var(--text-3); text-transform:uppercase; letter-spacing:.05em; }
    .form-select, .form-input {
        padding:.7rem .875rem; border:1.5px solid var(--border);
        border-radius:var(--r-md); font-size:.875rem; font-family:var(--font);
        font-weight:500; background:var(--surface); color:var(--text);
        outline:none; transition:border-color var(--t);
    }
    .form-select { cursor:pointer; appearance:none; -webkit-appearance:none;
        background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
        background-repeat:no-repeat; background-position:right .625rem center; background-size:13px;
        padding-right:2.25rem;
    }
    .form-select:focus, .form-input:focus { border-color:var(--brand); }
    .btn-primary {
        display:inline-flex; align-items:center; gap:.5rem;
        padding:.7rem 1.375rem; background:linear-gradient(135deg,var(--brand),var(--brand-dark));
        border:none; border-radius:var(--r-md); color:white;
        font-size:.875rem; font-weight:700; font-family:var(--font);
        cursor:pointer; box-shadow:var(--shadow-brand); transition:all var(--t);
    }
    .btn-primary:hover { transform:translateY(-2px); box-shadow:0 8px 24px var(--brand-glow); }
    .btn-primary svg { width:15px; height:15px; stroke:white; fill:none; }

    /* ── Table ── */
    .table-card {
        background:var(--surface); border:1px solid var(--border);
        border-radius:var(--r-xl); box-shadow:var(--shadow-sm); overflow:hidden;
        margin-bottom:1.75rem;
    }
    .data-table { width:100%; border-collapse:collapse; font-size:.875rem; }
    .data-table thead { background:var(--surface-2); border-bottom:1px solid var(--border); }
    .data-table thead th {
        padding:.875rem 1.25rem; text-align:left;
        font-size:.7rem; font-weight:700; color:var(--text-4);
        text-transform:uppercase; letter-spacing:.07em; white-space:nowrap;
    }
    .data-table tbody tr { border-bottom:1px solid var(--surface-3); transition:background var(--t); }
    .data-table tbody tr:last-child { border-bottom:none; }
    .data-table tbody tr:hover { background:#fafbff; }
    .data-table tbody td { padding:.9375rem 1.25rem; vertical-align:middle; }
    .cell-name { font-weight:700; color:var(--text); font-size:.875rem; }
    .fee-amount { font-weight:600; font-size:.875rem; color:var(--text); }
    .fee-collected { color:var(--emerald-dark); font-weight:700; }
    .fee-pending { color:var(--amber-dark); font-weight:700; }
    .progress-cell { display:flex; flex-direction:column; gap:.375rem; }
    .progress-label { font-weight:700; font-size:.8125rem; color:var(--text); }
    .progress-bar-container { width:100%; height:6px; background:var(--surface-3); border-radius:var(--r-full); overflow:hidden; }
    .progress-bar-fill { height:100%; transition:width .3s ease; border-radius:var(--r-full); }
    .btn-icon {
        width:32px; height:32px; display:flex; align-items:center; justify-content:center;
        border-radius:var(--r-md); border:1.5px solid var(--border);
        background:var(--surface-2); cursor:pointer; transition:all var(--t);
    }
    .btn-icon svg { width:14px; height:14px; stroke:var(--text-3); fill:none; }
    .btn-icon:hover { border-color:var(--brand); background:var(--brand-light); }
    .btn-icon:hover svg { stroke:var(--brand); }
    .table-empty { text-align:center; padding:3.5rem 2rem; }
    .table-empty svg { width:48px; height:48px; stroke:var(--text-4); margin:0 auto 1rem; display:block; }
    .table-empty p { font-size:.9375rem; color:var(--text-3); }

    /* ── Material cards ── */
    .material-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:1rem; margin-bottom:1.75rem; }
    .material-card {
        background:var(--surface); border:1px solid var(--border);
        border-radius:var(--r-xl); padding:1.375rem 1.5rem;
        display:flex; align-items:center; gap:1rem;
        box-shadow:var(--shadow-sm); transition:transform var(--t), box-shadow var(--t);
    }
    .material-card:hover { transform:translateY(-3px); box-shadow:var(--shadow-md); }
    .material-icon {
        width:48px; height:48px; border-radius:var(--r-md);
        background:linear-gradient(135deg,var(--brand),var(--violet));
        display:flex; align-items:center; justify-content:center; flex-shrink:0;
    }
    .material-icon svg { width:22px; height:22px; stroke:white; fill:none; }
    .material-info { flex:1; }
    .material-type { font-weight:700; font-size:.9375rem; color:var(--text); margin-bottom:.375rem; }
    .material-stats { display:flex; flex-direction:column; gap:.15rem; }
    .material-count, .material-quantity { font-size:.775rem; color:var(--text-3); }

    /* ── Rank badges ── */
    .rank-badge {
        display:inline-flex; align-items:center; gap:.35rem;
        padding:.5rem .75rem; border-radius:var(--r-md);
        font-weight:700; font-size:.875rem;
    }
    .rank-1 { background:linear-gradient(135deg,#fbbf24,#f59e0b); color:white; box-shadow:0 4px 12px rgba(251,191,36,.3); }
    .rank-2 { background:linear-gradient(135deg,#94a3b8,#64748b); color:white; box-shadow:0 4px 12px rgba(148,163,184,.3); }
    .rank-3 { background:linear-gradient(135deg,#fb923c,#f97316); color:white; box-shadow:0 4px 12px rgba(251,146,60,.3); }
    .rank-badge:not(.rank-1):not(.rank-2):not(.rank-3) { background:var(--surface-3); color:var(--text); border:1px solid var(--border); }
    .rank-badge svg { width:14px; height:14px; stroke:currentColor; fill:none; }

    /* ── Chart ── */
    .chart-card {
        background:var(--surface); border:1px solid var(--border);
        border-radius:var(--r-xl); padding:2rem;
        box-shadow:var(--shadow-sm); margin-bottom:1.75rem;
    }
    .chart-container {
        display:flex; align-items:flex-end; justify-content:space-around;
        gap:1rem; height:300px; padding:1rem 0;
    }
    .chart-bar-group { flex:1; display:flex; flex-direction:column; align-items:center; gap:.5rem; }
    .chart-bar-container { width:100%; height:250px; display:flex; align-items:flex-end; justify-content:center; }
    .chart-bar {
        width:70%; background:linear-gradient(135deg,var(--brand),var(--violet));
        border-radius:var(--r-md) var(--r-md) 0 0; position:relative;
        transition:all .3s ease; display:flex; align-items:flex-start;
        justify-content:center; padding-top:.5rem; min-height:40px;
        box-shadow:0 4px 12px var(--brand-glow);
    }
    .chart-bar:hover { background:linear-gradient(135deg,var(--violet),var(--brand)); transform:scaleY(1.05); }
    .chart-value { font-size:.75rem; font-weight:700; color:white; }
    .chart-label { font-size:.8125rem; font-weight:600; color:var(--text); text-align:center; }
    .chart-sublabel { font-size:.72rem; color:var(--text-3); text-align:center; }

    /* ── Responsive ── */
    @media (max-width:1280px) { .stats-grid { grid-template-columns:repeat(2,1fr); } }
    @media (max-width:900px)  { .page-content { padding:1.25rem; } }
    @media (max-width:768px)  {
        .stats-grid { grid-template-columns:1fr; }
        .filters-form { flex-direction:column; }
        .filter-group { width:100%; }
        .page-header-block { flex-direction:column; align-items:flex-start; gap:1rem; }
        .section-header { flex-direction:column; align-items:flex-start; }
        .chart-container { overflow-x:auto; }
        .material-grid { grid-template-columns:1fr; }
    }
    @media print {
        .sidebar, .top-header, .filters-card, .section-header button, .btn-icon { display:none !important; }
        .main-content { margin-left:0 !important; }
    }
    @keyframes spin { to { transform:rotate(360deg); } }
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
                    <h2>Reports & Analytics</h2>
                    <p>Comprehensive insights and statistics</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">
            
            <!-- Page header -->
            <div class="page-header-block">
                <div class="page-header-left">
                    <div class="page-header-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    </div>
                    <div>
                        <div class="page-header-title">Reports & Analytics</div>
                        <div class="page-header-subtitle">Comprehensive insights and performance metrics</div>
                    </div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filters-card">
                <form method="GET" class="filters-form">
                    <div class="filter-group">
                        <label>DLC Office</label>
                        <select name="dlc" class="form-select" onchange="this.form.submit()">
                            <option value="all">All DLC Offices</option>
                            <?php foreach ($dlcOffices as $dlc): ?>
                                <option value="<?= $dlc['id'] ?>" <?= $dlcFilter == $dlc['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dlc['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label>Start Date</label>
                        <input type="date" name="start_date" class="form-input" value="<?= $startDate ?>">
                    </div>
                    <div class="filter-group">
                        <label>End Date</label>
                        <input type="date" name="end_date" class="form-input" value="<?= $endDate ?>">
                    </div>
                    <button type="submit" class="btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        Apply Filters
                    </button>
                </form>
            </div>

            <!-- Overall Statistics -->
            <div class="section-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                    Overall Statistics
                </h3>
            </div>

            <div class="stats-grid">
                <div class="stat-card violet">
                    <div class="stat-top">
                        <div class="stat-icon violet">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg>
                        </div>
                    </div>
                    <div class="stat-value"><?= $overallStats['total_dlc'] ?></div>
                    <div class="stat-label">DLC Offices</div>
                </div>
                
                <div class="stat-card emerald">
                    <div class="stat-top">
                        <div class="stat-icon emerald">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                        </div>
                    </div>
                    <div class="stat-value"><?= $overallStats['total_atc'] ?></div>
                    <div class="stat-label">ATC Centers</div>
                </div>
                
                <div class="stat-card sky">
                    <div class="stat-top">
                        <div class="stat-icon sky">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </div>
                    </div>
                    <div class="stat-value"><?= $overallStats['total_admissions'] ?></div>
                    <div class="stat-label">Total Students</div>
                </div>
                
                <div class="stat-card amber">
                    <div class="stat-top">
                        <div class="stat-icon amber">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        </div>
                    </div>
                    <div class="stat-value"><?= $overallStats['total_inquiries'] ?></div>
                    <div class="stat-label">Total Inquiries</div>
                </div>
            </div>

            <!-- Revenue Statistics -->
            <div class="section-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    Revenue Overview
                </h3>
            </div>

            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));">
                <div class="stat-card-large pink">
                    <div class="stat-icon-large" style="background: linear-gradient(135deg, #ec4899, #db2777);">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div class="stat-content-large">
                        <div class="stat-label-large">Total Revenue</div>
                        <div class="stat-value-large">₹ <?= number_format($revenueStats['total_revenue'], 0) ?></div>
                        <div class="stat-sub-large">Expected from all admissions</div>
                    </div>
                </div>
                
                <div class="stat-card-large emerald">
                    <div class="stat-icon-large" style="background: linear-gradient(135deg, #10b981, #059669);">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div class="stat-content-large">
                        <div class="stat-label-large">Collected</div>
                        <div class="stat-value-large">₹ <?= number_format($revenueStats['total_collected'], 0) ?></div>
                        <div class="stat-sub-large">
                            <?php 
                            $collectionRate = $revenueStats['total_revenue'] > 0 
                                ? round(($revenueStats['total_collected'] / $revenueStats['total_revenue']) * 100, 1) 
                                : 0;
                            ?>
                            <?= $collectionRate ?>% collection rate
                        </div>
                    </div>
                </div>
                
                <div class="stat-card-large amber">
                    <div class="stat-icon-large" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div class="stat-content-large">
                        <div class="stat-label-large">Pending</div>
                        <div class="stat-value-large">₹ <?= number_format($revenueStats['total_pending'], 0) ?></div>
                        <div class="stat-sub-large">Outstanding amount</div>
                    </div>
                </div>
            </div>

            <!-- DLC-wise Revenue Breakdown -->
            <div class="section-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    Revenue by DLC Office
                </h3>
                <button class="btn-secondary" onclick="window.print()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    Print Report
                </button>
            </div>

            <div class="table-card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>DLC Office</th>
                            <th>Location</th>
                            <th>ATC Centers</th>
                            <th>Students</th>
                            <th>Total Revenue</th>
                            <th>Collected</th>
                            <th>Pending</th>
                            <th>Collection %</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dlcRevenue)): ?>
                            <tr>
                                <td colspan="9" class="table-empty">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                                    <p>No data available.</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($dlcRevenue as $dlc): ?>
                                <?php 
                                    $dlcCollectionRate = $dlc['total_revenue'] > 0 
                                        ? round(($dlc['collected'] / $dlc['total_revenue']) * 100, 1) 
                                        : 0;
                                ?>
                                <tr>
                                    <td>
                                        <div class="cell-name"><?= htmlspecialchars($dlc['dlc_name']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($dlc['district']) ?></td>
                                    <td><strong><?= $dlc['atc_count'] ?></strong></td>
                                    <td><strong><?= $dlc['total_students'] ?></strong></td>
                                    <td class="fee-amount">₹ <?= number_format($dlc['total_revenue'], 0) ?></td>
                                    <td class="fee-collected">₹ <?= number_format($dlc['collected'], 0) ?></td>
                                    <td class="fee-pending">₹ <?= number_format($dlc['pending'], 0) ?></td>
                                    <td>
                                        <div class="progress-cell">
                                            <span class="progress-label"><?= $dlcCollectionRate ?>%</span>
                                            <div class="progress-bar-container">
                                                <div class="progress-bar-fill" style="width: <?= min($dlcCollectionRate, 100) ?>%; background: <?= $dlcCollectionRate >= 75 ? '#10b981' : ($dlcCollectionRate >= 50 ? '#f59e0b' : '#ef4444') ?>;"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <button class="btn-icon" onclick="viewDLCDetails(<?= $dlc['id'] ?>)" title="View Details">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>


            <!-- Dispatch Statistics -->
            <div class="section-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3"/><path d="m15 9 6-6"/></svg>
                    Material Dispatch Overview
                </h3>
            </div>

            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                <div class="stat-card violet">
                    <div class="stat-top">
                        <div class="stat-icon violet">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3"/><path d="m15 9 6-6"/></svg>
                        </div>
                    </div>
                    <div class="stat-value"><?= $dispatchStats['total_dispatches'] ?? 0 ?></div>
                    <div class="stat-label">Total Dispatches</div>
                </div>
                
                <div class="stat-card amber">
                    <div class="stat-top">
                        <div class="stat-icon amber">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        </div>
                    </div>
                    <div class="stat-value"><?= ($dispatchStats['created'] ?? 0) + ($dispatchStats['sent_to_dlc'] ?? 0) ?></div>
                    <div class="stat-label">Pending</div>
                </div>
                
                <div class="stat-card sky">
                    <div class="stat-top">
                        <div class="stat-icon sky">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </div>
                    </div>
                    <div class="stat-value"><?= $dispatchStats['forwarded_to_atc'] ?? 0 ?></div>
                    <div class="stat-label">In Transit</div>
                </div>
                
                <div class="stat-card emerald">
                    <div class="stat-top">
                        <div class="stat-icon emerald">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                    </div>
                    <div class="stat-value"><?= $dispatchStats['delivered'] ?? 0 ?></div>
                    <div class="stat-label">Delivered</div>
                </div>
                
                <div class="stat-card brand">
                    <div class="stat-top">
                        <div class="stat-icon brand">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                        </div>
                    </div>
                    <div class="stat-value"><?= $dispatchStats['total_items'] ?? 0 ?></div>
                    <div class="stat-label">Total Items</div>
                </div>
            </div>

            <!-- Material Type Breakdown -->
            <?php if (!empty($materialBreakdown)): ?>
            <div class="section-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                    Material Type Distribution
                </h3>
            </div>

            <div class="material-grid">
                <?php foreach ($materialBreakdown as $material): ?>
                    <div class="material-card">
                        <div class="material-icon">
                            <?php
                            $icon = match($material['material_type']) {
                                'Books' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>',
                                'Certificates' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15h6"/><path d="M9 18h6"/></svg>',
                                'Marksheets' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
                                default => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>'
                            };
                            echo $icon;
                            ?>
                        </div>
                        <div class="material-info">
                            <div class="material-type"><?= htmlspecialchars($material['material_type']) ?></div>
                            <div class="material-stats">
                                <span class="material-count"><?= $material['dispatch_count'] ?> dispatches</span>
                                <span class="material-quantity"><?= number_format($material['total_quantity']) ?> items</span>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Top Performing ATCs -->
            <?php if (!empty($topATCs)): ?>
            <div class="section-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                    Top 10 Performing ATC Centers
                </h3>
            </div>

            <div class="table-card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>ATC Center</th>
                            <th>DLC Office</th>
                            <th>Students</th>
                            <th>Revenue Collected</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($topATCs as $index => $atc): ?>
                            <tr>
                                <td>
                                    <div class="rank-badge rank-<?= $index + 1 ?>">
                                        <?php if ($index < 3): ?>
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                                        <?php endif; ?>
                                        #<?= $index + 1 ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="cell-name"><?= htmlspecialchars($atc['atc_name']) ?></div>
                                </td>
                                <td><?= htmlspecialchars($atc['dlc_name']) ?></td>
                                <td><strong><?= $atc['student_count'] ?></strong></td>
                                <td class="fee-collected">₹ <?= number_format($atc['revenue_collected'], 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <!-- Monthly Trend -->
            <?php if (!empty($monthlyTrend)): ?>
            <div class="section-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    Monthly Revenue Trend (Last 6 Months)
                </h3>
            </div>

            <div class="chart-card">
                <div class="chart-container">
                    <?php 
                    $maxRevenue = max(array_column($monthlyTrend, 'revenue'));
                    foreach ($monthlyTrend as $month): 
                        $percentage = $maxRevenue > 0 ? ($month['revenue'] / $maxRevenue) * 100 : 0;
                        $monthName = date('M Y', strtotime($month['month'] . '-01'));
                    ?>
                        <div class="chart-bar-group">
                            <div class="chart-bar-container">
                                <div class="chart-bar" style="height: <?= $percentage ?>%;" title="₹<?= number_format($month['revenue'], 0) ?>">
                                    <span class="chart-value">₹<?= number_format($month['revenue'] / 1000, 0) ?>K</span>
                                </div>
                            </div>
                            <div class="chart-label"><?= $monthName ?></div>
                            <div class="chart-sublabel"><?= $month['admissions'] ?> students</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </main>
</div>

<!-- DLC Details Modal -->
<div class="modal-overlay" id="dlcDetailsModal" style="position: fixed; inset: 0; background: rgba(10,15,30,.55); backdrop-filter: blur(5px); z-index: 2000; display: none; align-items: center; justify-content: center; padding: 1.5rem;">
    <div class="modal-card" style="background: var(--surface); border-radius: var(--r-xl); border: 1px solid var(--border); box-shadow: var(--shadow-lg); width: 100%; max-width: 1000px; overflow: hidden;">
        <div class="modal-header" style="display: flex; align-items: center; justify-content: space-between; padding: 1.5rem 2rem; border-bottom: 1px solid var(--border); background: var(--surface-2);">
            <h3 style="display: flex; align-items: center; gap: .625rem; font-size: 1.125rem; font-weight: 800; color: var(--text); margin: 0;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 22px; height: 22px;"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg>
                <span id="dlcDetailsTitle">DLC Details</span>
            </h3>
            <button type="button" class="modal-close" onclick="closeDLCModal()" aria-label="Close" style="width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: var(--r-md); border: 1.5px solid var(--border); background: var(--surface); cursor: pointer; transition: all var(--t);">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 16px; height: 16px; stroke: var(--text-3);"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        
        <div class="modal-body" id="dlcDetailsContent" style="padding: 2rem; max-height: calc(90vh - 200px); overflow-y: auto;">
            <div style="text-align: center; padding: 3rem;">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width: 48px; height: 48px; margin: 0 auto; animation: spin 1s linear infinite; stroke: var(--text-4);"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                <p style="margin-top: 1rem; color: var(--text-3);">Loading details...</p>
            </div>
        </div>
        
        <div class="modal-footer" style="display: flex; gap: .75rem; padding: 1.25rem 2rem; border-top: 1px solid var(--border); background: var(--surface-2);">
            <button type="button" class="btn-secondary" onclick="closeDLCModal()">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                Close
            </button>
        </div>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<style>
.modal-overlay.active { display: flex !important; }
.modal-close:hover { border-color: var(--brand); background: var(--brand-light); }
.modal-close:hover svg { stroke: var(--brand); }
.modal-body::-webkit-scrollbar { width: 8px; }
.modal-body::-webkit-scrollbar-track { background: var(--surface-3); border-radius: var(--r-full); }
.modal-body::-webkit-scrollbar-thumb { background: var(--border-2); border-radius: var(--r-full); }
.modal-body::-webkit-scrollbar-thumb:hover { background: var(--text-4); }
</style>
<script>
async function viewDLCDetails(dlcId) {
    document.getElementById('dlcDetailsModal').classList.add('active');
    
    try {
        const response = await fetch(`atc_centers.php?action=get_dlc_atcs&dlc_id=${dlcId}`);
        const data = await response.json();
        
        if (data.success) {
            displayDLCDetails(data);
        } else {
            document.getElementById('dlcDetailsContent').innerHTML = `
                <div style="text-align: center; padding: 3rem;">
                    <p style="color: var(--text-3);">Error loading details</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error:', error);
        document.getElementById('dlcDetailsContent').innerHTML = `
            <div style="text-align: center; padding: 3rem;">
                <p style="color: var(--text-3);">Error loading DLC details</p>
            </div>
        `;
    }
}

function displayDLCDetails(data) {
    document.getElementById('dlcDetailsTitle').textContent = 'DLC ATC Centers';
    document.getElementById('dlcDetailsContent').innerHTML = `
        <div style="padding: 1.5rem;">
            <p style="color: var(--text-2);">Detailed breakdown will be displayed here</p>
        </div>
    `;
}

function closeDLCModal() {
    document.getElementById('dlcDetailsModal').classList.remove('active');
}

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('dlcDetailsModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                closeDLCModal();
            }
        });
    }
});
</script>
</body>
</html>
