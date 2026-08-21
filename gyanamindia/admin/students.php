<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo      = getDBConnection();
$userName = sanitize(getUserName());

// ── Load filter options ─────────────────────────────────────────────────────
$dlcOffices = $pdo->query("SELECT id, name FROM dlc_offices WHERE status='Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$atcCenters = $pdo->query("SELECT id, name, dlc_id FROM atc_centers WHERE status='Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// ── Read filters ────────────────────────────────────────────────────────────
$filterDlc    = isset($_GET['dlc_id'])   && $_GET['dlc_id']   !== '' ? (int)$_GET['dlc_id']   : null;
$filterAtc    = isset($_GET['atc_id'])   && $_GET['atc_id']   !== '' ? (int)$_GET['atc_id']   : null;
$filterSearch = isset($_GET['search'])   ? trim($_GET['search'])    : '';
$filtered     = $filterDlc !== null || $filterAtc !== null || $filterSearch !== '';

// ── Fetch students (only when a filter is applied) ──────────────────────────
$students   = [];
$totalCount = 0;

if ($filtered) {
    $where  = ['a.status = ?'];
    $params = ['Active'];

    if ($filterAtc !== null) {
        $where[]  = 'a.atc_id = ?';
        $params[] = $filterAtc;
    } elseif ($filterDlc !== null) {
        $where[]  = 'atc.dlc_id = ?';
        $params[] = $filterDlc;
    }

    if ($filterSearch !== '') {
        $where[]  = "(CONCAT(a.first_name,' ',COALESCE(a.middle_name,''),' ',a.last_name) LIKE ? OR a.roll_no LIKE ? OR a.mobile LIKE ?)";
        $s = '%' . $filterSearch . '%';
        $params = array_merge($params, [$s, $s, $s]);
    }

    // Count + paginate
    $countSql = "SELECT COUNT(*) FROM admissions a
                 LEFT JOIN atc_centers atc ON atc.id = a.atc_id
                 LEFT JOIN dlc_offices dlc ON dlc.id = atc.dlc_id
                 WHERE " . implode(' AND ', $where);
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $pager = paginationMeta((int)$countStmt->fetchColumn(), paginationParams(25));
    $totalCount = $pager['total'];

    // Try with ho_share_paid column; fall back if it doesn't exist
    try {
        $sql = "SELECT a.id, a.roll_no,
                       CONCAT(a.first_name,' ',COALESCE(NULLIF(TRIM(a.middle_name),''),''),' ',a.last_name) AS student_name,
                       a.course, a.admission_date, a.mobile, a.photo,
                       COALESCE(a.ho_share_paid,0) AS ho_share_paid,
                       atc.id  AS atc_id,
                       atc.name AS atc_name,
                       dlc.name AS dlc_name
                FROM admissions a
                LEFT JOIN atc_centers atc ON atc.id = a.atc_id
                LEFT JOIN dlc_offices dlc ON dlc.id = atc.dlc_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY a.admission_date DESC, student_name ASC
                LIMIT {$pager['per_page']} OFFSET {$pager['offset']}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // ho_share_paid column may not exist — retry without it
        $sql = "SELECT a.id, a.roll_no,
                       CONCAT(a.first_name,' ',COALESCE(NULLIF(TRIM(a.middle_name),''),''),' ',a.last_name) AS student_name,
                       a.course, a.admission_date, a.mobile, a.photo,
                       0 AS ho_share_paid,
                       atc.id  AS atc_id,
                       atc.name AS atc_name,
                       dlc.name AS dlc_name
                FROM admissions a
                LEFT JOIN atc_centers atc ON atc.id = a.atc_id
                LEFT JOIN dlc_offices dlc ON dlc.id = atc.dlc_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY a.admission_date DESC, student_name ASC
                LIMIT {$pager['per_page']} OFFSET {$pager['offset']}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Resolve share paid status via share_payments JSON ──────────────────
    $atcIds  = array_values(array_unique(array_column($rows, 'atc_id')));
    $paidMap = [];

    if (!empty($atcIds)) {
        $placeholders = implode(',', array_fill(0, count($atcIds), '?'));
        $spStmt = $pdo->prepare(
            "SELECT student_ids FROM share_payments
             WHERE atc_id IN ($placeholders) AND status = 'Completed'"
        );
        $spStmt->execute($atcIds);
        foreach ($spStmt->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $ids = json_decode($json, true);
            if (is_array($ids)) {
                foreach ($ids as $sid) $paidMap[(int)$sid] = true;
            }
        }
    }

    // Merge share status into each row
    foreach ($rows as &$r) {
        $r['share_paid'] = isset($paidMap[$r['id']]) || !empty($r['ho_share_paid']);
    }
    unset($r);
    $students = $rows;
} else {
    $pager = paginationMeta(0, paginationParams(25));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Students — Admin | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎓</text></svg>">
    <!-- Export libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.8.2/jspdf.plugin.autotable.min.js"></script>

    <style>
    /* ── Design tokens ── */
    :root {
        --font: 'Sora', sans-serif;
        --mono: 'JetBrains Mono', monospace;
        --bg: #f0f2f7;
        --surface: #ffffff;
        --surface-raised: #f8f9fc;
        --border: #e4e8f0;
        --text: #0f1523;
        --text-2: #4a5568;
        --text-3: #8896a5;
        --indigo: #4f6ef7;
        --indigo-dark: #3a57e8;
        --indigo-soft: #eef1fe;
        --violet: #7c3aed;
        --violet-soft: #f5f3ff;
        --emerald: #00c48c;
        --emerald-dark: #00a376;
        --emerald-soft: #e6faf4;
        --amber: #f59e0b;
        --amber-soft: #fffbeb;
        --shadow-sm: 0 1px 3px rgba(0,0,0,.06), 0 1px 2px rgba(0,0,0,.04);
        --shadow-md: 0 4px 16px rgba(0,0,0,.07);
        --r-sm: 6px; --r-md: 10px; --r-lg: 14px; --r-xl: 20px; --r-full: 9999px;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: var(--font); background: var(--bg); color: var(--text); }

    /* ── Page header ── */
    .page-header-block {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 1.75rem; padding: 1.5rem 1.75rem;
        background: var(--surface); border-radius: var(--r-xl);
        border: 1px solid var(--border); box-shadow: var(--shadow-sm);
    }
    .page-header-left { display: flex; align-items: center; gap: 1.125rem; }
    .page-header-icon {
        width: 48px; height: 48px; border-radius: var(--r-lg);
        background: linear-gradient(135deg, var(--indigo), var(--violet));
        display: flex; align-items: center; justify-content: center;
        box-shadow: 0 8px 20px rgba(79,110,247,.28); flex-shrink: 0;
    }
    .page-header-icon svg { width: 24px; height: 24px; stroke: white; }
    .page-header-title { font-size: 1.375rem; font-weight: 800; color: #0f1523 !important; letter-spacing: -.03em; z-index: 10; position: relative; }
    .page-header-subtitle { font-size: .8125rem; color: var(--text-3); margin-top: .15rem; }

    /* ── Filter card ── */
    .filter-card {
        background: var(--surface); border-radius: var(--r-xl);
        border: 1px solid var(--border); box-shadow: var(--shadow-sm);
        padding: 1.375rem 1.5rem; margin-bottom: 1.5rem;
    }
    .filter-card-title {
        font-size: .8rem; font-weight: 700; color: var(--text-3);
        text-transform: uppercase; letter-spacing: .06em; margin-bottom: 1rem;
    }
    .filter-row { display: flex; align-items: center; gap: .875rem; flex-wrap: wrap; }
    .filter-group { display: flex; flex-direction: column; gap: .35rem; flex: 1; min-width: 160px; }
    .filter-label { font-size: .76rem; font-weight: 700; color: var(--text-2); }
    .filter-select, .filter-input {
        padding: .72rem 2.25rem .72rem .875rem; border: 1.5px solid var(--border);
        border-radius: var(--r-md); font-size: .875rem; font-family: var(--font);
        font-weight: 500; background: var(--surface-raised); color: var(--text);
        outline: none; transition: border-color .18s, box-shadow .18s;
        appearance: none; -webkit-appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%238896a5' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right .625rem center; background-size: 14px;
        width: 100%;
    }
    .filter-input {
        background-image: none;
        padding: .72rem .875rem;
    }
    .filter-select:focus, .filter-input:focus {
        border-color: var(--indigo); box-shadow: 0 0 0 3px rgba(79,110,247,.1);
    }
    .btn-filter {
        display: inline-flex; align-items: center; gap: .5rem;
        padding: .78rem 1.5rem;
        background: linear-gradient(135deg, var(--indigo), var(--indigo-dark));
        border: none; border-radius: var(--r-md); color: white;
        font-size: .875rem; font-weight: 700; font-family: var(--font);
        cursor: pointer; white-space: nowrap;
        box-shadow: 0 4px 14px rgba(79,110,247,.3);
        transition: all .2s ease; align-self: flex-end; margin-top: 1.1rem;
    }
    .btn-filter:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(79,110,247,.35); }
    .btn-filter svg { width: 15px; height: 15px; }
    .btn-clear {
        display: inline-flex; align-items: center; gap: .4rem;
        padding: .78rem 1.1rem;
        background: var(--surface); border: 1.5px solid var(--border);
        border-radius: var(--r-md); color: var(--text-2);
        font-size: .875rem; font-weight: 600; font-family: var(--font);
        cursor: pointer; white-space: nowrap; transition: all .18s;
        text-decoration: none; align-self: flex-end; margin-top: 1.1rem;
    }
    .btn-clear:hover { border-color: var(--indigo); color: var(--indigo); background: var(--indigo-soft); }
    .btn-clear svg { width: 14px; height: 14px; }

    /* Active filters row */
    .active-filters {
        display: flex; align-items: center; gap: .5rem;
        flex-wrap: wrap; margin-top: .875rem; padding-top: .875rem;
        border-top: 1px solid var(--border);
    }
    .active-filters-label { font-size: .76rem; font-weight: 700; color: var(--text-3); }
    .filter-chip {
        display: inline-flex; align-items: center; gap: .35rem;
        padding: .25rem .75rem; border-radius: var(--r-full);
        font-size: .75rem; font-weight: 700;
        background: var(--indigo-soft); color: var(--indigo);
        border: 1px solid rgba(79,110,247,.2);
    }

    /* ── Toolbar ── */
    .toolbar {
        display: flex; align-items: center; justify-content: space-between;
        margin-bottom: 1rem; gap: 1rem; flex-wrap: wrap;
    }
    .toolbar-left { display: flex; align-items: center; gap: .75rem; }
    .toolbar-title { font-size: 1.0625rem; font-weight: 800; color: var(--text); letter-spacing: -.02em; }
    .toolbar-count {
        font-size: .75rem; font-weight: 700; color: var(--text-3);
        background: var(--surface); border: 1px solid var(--border);
        padding: .2rem .6rem; border-radius: var(--r-full);
    }

    /* ── Table ── */
    .table-wrap {
        background: var(--surface); border-radius: var(--r-xl);
        border: 1px solid var(--border); box-shadow: var(--shadow-sm); overflow: hidden;
    }
    .data-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    .data-table thead { background: var(--surface-raised); }
    .data-table thead th {
        padding: 1rem 1.25rem; text-align: left; font-size: .72rem; font-weight: 700;
        color: var(--text-3); text-transform: uppercase; letter-spacing: .06em;
        border-bottom: 1px solid var(--border); white-space: nowrap;
    }
    .data-table tbody tr { border-bottom: 1px solid #f3f5f9; transition: background .15s; }
    .data-table tbody tr:last-child { border-bottom: none; }
    .data-table tbody tr:hover { background: #fafbff; }
    .data-table tbody td { padding: 1rem 1.25rem; vertical-align: middle; }

    /* ── Student photo cell ── */
    .student-cell { display: flex; align-items: center; gap: .875rem; }
    .student-photo {
        width: 42px; height: 42px; border-radius: var(--r-md); flex-shrink: 0;
        object-fit: cover; border: 1.5px solid var(--border);
    }
    .student-avatar {
        width: 42px; height: 42px; border-radius: var(--r-md); flex-shrink: 0;
        display: flex; align-items: center; justify-content: center;
        font-size: .875rem; font-weight: 800; color: white;
        background: linear-gradient(135deg, var(--indigo), var(--violet));
    }
    .student-name  { font-size: .9rem; font-weight: 700; color: var(--text); }
    .student-roll  { font-size: .75rem; color: var(--text-3); font-family: var(--mono); margin-top: .1rem; }

    .cell-main { font-weight: 600; color: var(--text); }
    .cell-sub  { font-size: .8rem; color: var(--text-3); margin-top: .1rem; }

    /* ── Status badge ── */
    .share-badge {
        display: inline-flex; align-items: center; gap: .4rem;
        padding: .3rem .85rem; border-radius: var(--r-full);
        font-size: .74rem; font-weight: 700; white-space: nowrap;
    }
    .share-badge-dot { width: 6px; height: 6px; border-radius: 50%; }
    .share-paid    { background: var(--emerald-soft); color: var(--emerald-dark); border: 1px solid #b3f0de; }
    .share-paid    .share-badge-dot { background: var(--emerald); }
    .share-pending { background: var(--amber-soft); color: #92400e; border: 1px solid #fde68a; }
    .share-pending .share-badge-dot { background: var(--amber); }

    /* ── ATC/DLC cell ── */
    .org-badge {
        display: inline-block; padding: .2rem .6rem;
        border-radius: var(--r-full); font-size: .72rem; font-weight: 700;
        background: var(--indigo-soft); color: var(--indigo);
        border: 1px solid rgba(79,110,247,.15);
    }

    /* ── Empty state ── */
    .empty-state {
        text-align: center; padding: 5rem 2rem;
    }
    .empty-icon {
        width: 72px; height: 72px; border-radius: var(--r-xl);
        background: linear-gradient(135deg, var(--indigo-soft), var(--violet-soft));
        border: 1px solid rgba(79,110,247,.15);
        display: flex; align-items: center; justify-content: center;
        margin: 0 auto 1.25rem;
    }
    .empty-icon svg { width: 32px; height: 32px; stroke: var(--indigo); }
    .empty-title { font-size: 1.0625rem; font-weight: 800; color: var(--text-2); margin-bottom: .4rem; }
    .empty-sub   { font-size: .875rem; color: var(--text-3); line-height: 1.55; }

    /* ── Responsive ── */
    @media (max-width: 1024px) { .filter-row { gap: .6rem; } }
    @media (max-width: 768px) {
        .filter-row { flex-direction: column; }
        .filter-group { min-width: unset; }
        .btn-filter, .btn-clear { align-self: stretch; justify-content: center; }
        .page-header-block { flex-direction: column; align-items: flex-start; gap: 1rem; }
        .data-table { display: block; overflow-x: auto; }
    }
    /* ── Print styles ── */
    @media print {
        .dashboard-layout { display: block !important; }
        .sidebar, .top-header, .filter-card, .toolbar .export-btn-group { display: none !important; }
        .main-content { margin: 0 !important; padding: 0 !important; box-shadow: none !important; }
        .table-wrap { box-shadow: none !important; border: none !important; }
        .data-table th, .data-table td { font-size: 11px !important; }
        .share-badge { border: 1px solid #ccc !important; }
    }
    /* ── Export button group ── */
    .export-btn-group { display: flex; gap: .4rem; flex-wrap: wrap; }
    .exp-btn {
        display: inline-flex; align-items: center; gap: .3rem;
        padding: .45rem .8rem; border-radius: 8px; border: 1.5px solid;
        font-size: .74rem; font-weight: 700; font-family: var(--font);
        cursor: pointer; white-space: nowrap; transition: all .18s;
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
                <div class="header-title-pack" style="margin-left: 1rem; display: flex; flex-direction: column; justify-content: center;">
                    <h1 style="font-size: 1.1rem; font-weight: 800; color: var(--text); margin: 0; line-height: 1.2;">Students</h1>
                    <p style="font-size: 0.75rem; color: var(--text-3); margin: 0; line-height: 1.2; margin-top: 0.15rem;">Browse admitted students by ATC Center or DLC Office</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">

            <!-- Optional Top Count -->
            <?php if ($filtered && $totalCount > 0): ?>
            <div style="background:var(--indigo-soft);border:1px solid rgba(79,110,247,.2);border-radius:var(--r-lg);padding:.6rem 1.1rem;font-size:.83rem;font-weight:700;color:var(--indigo);margin-bottom:1.5rem;display:inline-block;">
                <?= $totalCount ?> student<?= $totalCount !== 1 ? 's' : '' ?> found
            </div>
            <?php endif; ?>

            <!-- ── Filter Card ── -->
            <div class="filter-card">
                <div class="filter-card-title">Filter Students</div>
                <form method="GET" id="filterForm">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label class="filter-label" for="dlc_id">DLC Office</label>
                            <select name="dlc_id" id="dlc_id" class="filter-select" onchange="filterATCByDLC()">
                                <option value="">— All DLCs —</option>
                                <?php foreach ($dlcOffices as $d): ?>
                                    <option value="<?= $d['id'] ?>" <?= $filterDlc === (int)$d['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($d['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group">
                            <label class="filter-label" for="atc_id">ATC Center</label>
                            <select name="atc_id" id="atc_id" class="filter-select">
                                <option value="">— All ATCs —</option>
                                <?php foreach ($atcCenters as $a): ?>
                                    <option value="<?= $a['id'] ?>"
                                            data-dlc="<?= $a['dlc_id'] ?>"
                                            <?= $filterAtc === (int)$a['id'] ? 'selected' : '' ?>
                                            <?= ($filterDlc && (int)$a['dlc_id'] !== $filterDlc) ? 'style="display:none"' : '' ?>>
                                        <?= htmlspecialchars($a['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-group" style="flex:1.5">
                            <label class="filter-label" for="search">Search</label>
                            <input type="text" name="search" id="search" class="filter-input"
                                   placeholder="Name, Reg ID or Mobile…"
                                   value="<?= htmlspecialchars($filterSearch) ?>">
                        </div>

                        <button type="submit" class="btn-filter">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                            Filter Students
                        </button>

                        <?php if ($filtered): ?>
                        <a href="students.php" class="btn-clear">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            Clear
                        </a>
                        <?php endif; ?>
                    </div>

                    <?php if ($filtered): ?>
                    <div class="active-filters">
                        <span class="active-filters-label">Active Filters:</span>
                        <?php
                        if ($filterDlc) {
                            $dlcName = '';
                            foreach ($dlcOffices as $d) if ((int)$d['id'] === $filterDlc) { $dlcName = $d['name']; break; }
                            echo "<span class='filter-chip'>DLC: " . htmlspecialchars($dlcName) . "</span>";
                        }
                        if ($filterAtc) {
                            $atcName = '';
                            foreach ($atcCenters as $a) if ((int)$a['id'] === $filterAtc) { $atcName = $a['name']; break; }
                            echo "<span class='filter-chip'>ATC: " . htmlspecialchars($atcName) . "</span>";
                        }
                        if ($filterSearch) echo "<span class='filter-chip'>Search: " . htmlspecialchars($filterSearch) . "</span>";
                        ?>
                    </div>
                    <?php endif; ?>
                </form>
            </div>

            <!-- ── Student Table ── -->
            <?php if (!$filtered): ?>
            <!-- Empty state — not yet filtered -->
            <div class="table-wrap">
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                    </div>
                    <div class="empty-title">Select a filter to view students</div>
                    <div class="empty-sub">Choose a DLC Office, ATC Center, or enter a search term above,<br>then click <strong>Filter Students</strong> to load the list.</div>
                </div>
            </div>

            <?php elseif (empty($students)): ?>
            <!-- Filtered but no results -->
            <div class="table-wrap">
                <div class="empty-state">
                    <div class="empty-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                    </div>
                    <div class="empty-title">No students found</div>
                    <div class="empty-sub">No active students match the selected filters. Try adjusting your criteria.</div>
                </div>
            </div>

            <?php else: ?>
            <!-- Results table -->
            <div class="toolbar">
                <div class="toolbar-left">
                    <span class="toolbar-title">Student List</span>
                    <span class="toolbar-count"><?= $totalCount ?> record<?= $totalCount !== 1 ? 's' : '' ?></span>
                </div>
                <div class="export-btn-group">
                    <button class="exp-btn exp-copy"  onclick="exportCopy()"  title="Copy to clipboard">📋 Copy</button>
                    <button class="exp-btn exp-csv"   onclick="exportCSV()"   title="Download CSV">📄 CSV</button>
                    <button class="exp-btn exp-excel" onclick="exportExcel()" title="Download Excel">📊 Excel</button>
                    <button class="exp-btn exp-pdf"   onclick="exportPDF()"   title="Download PDF">📑 PDF</button>
                    <button class="exp-btn exp-print" onclick="printStudents()" title="Print">🖨️ Print</button>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Reg ID</th>
                            <th>Course</th>
                            <th>Admission Date</th>
                            <th>ATC / DLC</th>
                            <th>Contact</th>
                            <th>Share Paid</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($students as $s):
                        $name   = trim(preg_replace('/\s+/', ' ', $s['student_name']));
                        $parts  = array_filter(explode(' ', $name));
                        $initials = substr(implode('', array_map(function($w){ return strtoupper($w[0]); }, $parts)), 0, 2);
                        $admDate  = $s['admission_date'] ? date('d M Y', strtotime($s['admission_date'])) : '—';
                    ?>
                    <tr>
                        <!-- Photo + Name -->
                        <td>
                            <div class="student-cell">
                                <?php if ($s['photo']): ?>
                                    <img class="student-photo"
                                         src="../<?= htmlspecialchars($s['photo']) ?>"
                                         alt="<?= htmlspecialchars($name) ?>"
                                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                                    <div class="student-avatar" style="display:none"><?= $initials ?></div>
                                <?php else: ?>
                                    <div class="student-avatar"><?= $initials ?></div>
                                <?php endif; ?>
                                <div>
                                    <div class="student-name"><?= htmlspecialchars($name) ?></div>
                                </div>
                            </div>
                        </td>

                        <!-- Reg ID -->
                        <td>
                            <span style="font-family:var(--mono);font-size:.82rem;font-weight:600;color:var(--indigo)">
                                <?= 'GYANAM' . $s['id'] ?>
                            </span>
                        </td>

                        <!-- Course -->
                        <td><span class="cell-main"><?= htmlspecialchars($s['course'] ?? '—') ?></span></td>

                        <!-- Admission Date -->
                        <td><span class="cell-main"><?= $admDate ?></span></td>

                        <!-- ATC / DLC -->
                        <td>
                            <?php if ($s['atc_name']): ?>
                                <div class="cell-main"><?= htmlspecialchars($s['atc_name']) ?></div>
                                <?php if ($s['dlc_name']): ?>
                                <div class="cell-sub"><?= htmlspecialchars($s['dlc_name']) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:var(--text-3)">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Contact -->
                        <td>
                            <?php if ($s['mobile']): ?>
                                <div class="cell-main"><?= htmlspecialchars($s['mobile']) ?></div>
                            <?php else: ?>
                                <span style="color:var(--text-3)">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Share Paid Status -->
                        <td>
                            <?php if ($s['share_paid']): ?>
                                <span class="share-badge share-paid">
                                    <span class="share-badge-dot"></span>Share Paid
                                </span>
                            <?php else: ?>
                                <span class="share-badge share-pending">
                                    <span class="share-badge-dot"></span>Pending
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= renderPagination($pager, 'students') ?>
            <?php endif; ?>

        </div><!-- /page-content -->
    </main>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
/* ── ATC filter by DLC ───────────────────────────────────────────────────── */
function filterATCByDLC() {
    const dlcId  = document.getElementById('dlc_id').value;
    const atcSel = document.getElementById('atc_id');

    Array.from(atcSel.options).forEach(opt => {
        if (!opt.value) { opt.style.display = ''; return; }
        opt.style.display = (!dlcId || opt.dataset.dlc === dlcId) ? '' : 'none';
    });

    // If the currently selected ATC doesn't match the new DLC, reset it
    const sel = atcSel.options[atcSel.selectedIndex];
    if (sel && sel.dataset.dlc && sel.dataset.dlc !== dlcId) {
        atcSel.value = '';
    }
}

// On page load, apply DLC filter to ATC dropdown if one was already selected
(function () {
    const dlcVal = document.getElementById('dlc_id').value;
    if (dlcVal) filterATCByDLC();
})();

// ── Export helpers ─────────────────────────────────────────────────────────
function getTableData() {
    const headers = ['Student Name','Reg ID','Course','Admission Date','ATC','DLC','Mobile','Share Paid'];
    const rows = [];
    document.querySelectorAll('.data-table tbody tr').forEach(tr => {
        const tds = tr.querySelectorAll('td');
        if (!tds.length) return;
        rows.push([
            tds[0]?.querySelector('.student-name')?.textContent?.trim() || '',
            tds[1]?.textContent?.trim() || '',
            tds[2]?.textContent?.trim() || '',
            tds[3]?.textContent?.trim() || '',
            tds[4]?.querySelector('.cell-main')?.textContent?.trim() || '',
            tds[4]?.querySelector('.cell-sub')?.textContent?.trim()  || '',
            tds[5]?.textContent?.trim() || '',
            tds[6]?.textContent?.trim() || ''
        ]);
    });
    return { headers, rows };
}

function exportCopy() {
    const { headers, rows } = getTableData();
    const text = [headers, ...rows].map(r => r.join('\t')).join('\n');
    navigator.clipboard.writeText(text).then(() => alert('✅ Copied ' + rows.length + ' rows to clipboard!'));
}

function exportCSV() {
    const { headers, rows } = getTableData();
    const csv = [headers, ...rows].map(r => r.map(c => '"'+String(c).replace(/"/g,'""')+'"').join(',')).join('\n');
    const a = Object.assign(document.createElement('a'), { href: 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv), download: 'students_' + Date.now() + '.csv' });
    a.click();
}

function exportExcel() {
    const { headers, rows } = getTableData();
    const ws = XLSX.utils.aoa_to_sheet([headers, ...rows]);
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Students');
    XLSX.writeFile(wb, 'students_' + Date.now() + '.xlsx');
}

function exportPDF() {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape' });
    const { headers, rows } = getTableData();
    doc.setFontSize(13); doc.text('Student List — Gyanam India', 14, 14);
    doc.setFontSize(9); doc.text('Generated: ' + new Date().toLocaleString('en-IN'), 14, 21);
    doc.autoTable({ head: [headers], body: rows, startY: 26, styles: { fontSize: 8 }, headStyles: { fillColor: [79,110,247] } });
    doc.save('students_' + Date.now() + '.pdf');
}

function printStudents() {
    const { headers, rows } = getTableData();
    if (!rows.length) { alert('No student data to print.'); return; }

    const filterInfo = <?= json_encode(
        ($filterDlc || $filterAtc || $filterSearch)
            ? array_filter([
                $filterDlc  ? ('DLC: ' . ($dlcOffices[array_search($filterDlc, array_column($dlcOffices,'id'))]['name'] ?? '')) : null,
                $filterAtc  ? ('ATC: ' . ($atcCenters[array_search($filterAtc, array_column($atcCenters,'id'))]['name'] ?? '')) : null,
                $filterSearch ? ('Search: "' . $filterSearch . '"') : null,
            ])
            : []
    ) ?>;

    const now = new Date().toLocaleString('en-IN', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});
    const filterLine = filterInfo.length ? '<p style="margin:.2rem 0 0;font-size:12px;color:#64748b">Filters: ' + filterInfo.join(' &nbsp;|&nbsp; ') + '</p>' : '';

    const thHtml  = headers.map(h => `<th>${h}</th>`).join('');
    const rowsHtml = rows.map(r => '<tr>' + r.map(c => `<td>${c}</td>`).join('') + '</tr>').join('');

    const html = `<!DOCTYPE html><html><head><meta charset="UTF-8">
    <title>Students — Gyanam India</title>
    <style>
        body{font-family:Arial,sans-serif;margin:1cm;font-size:11px;color:#111}
        h2{margin:0;font-size:16px}p{margin:0 0 8px}
        table{width:100%;border-collapse:collapse;margin-top:12px}
        th{background:#4361ee;color:#fff;padding:6px 8px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.04em}
        td{padding:5px 8px;border-bottom:1px solid #e5e7eb;vertical-align:top}
        tr:nth-child(even) td{background:#f8fafc}
        .footer{margin-top:14px;font-size:10px;color:#94a3b8;text-align:right}
        @media print{@page{margin:1cm}}
    </style></head><body>
    <h2>Student List &mdash; Gyanam India</h2>
    <p style="font-size:12px;color:#64748b">Generated: ${now} &nbsp;&bull;&nbsp; ${rows.length} student(s)</p>
    ${filterLine}
    <table><thead><tr>${thHtml}</tr></thead><tbody>${rowsHtml}</tbody></table>
    <div class="footer">Gyanam India &mdash; Confidential</div>
    </body></html>`;

    const w = window.open('', '_blank', 'width=1000,height=700');
    w.document.write(html);
    w.document.close();
    w.focus();
    setTimeout(() => { w.print(); w.close(); }, 400);
}
</script>
</body>
</html>
