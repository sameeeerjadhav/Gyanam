<?php
/**
 * Gyanam Portal — Admin: Share Payments Tracking
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());

// --- AJAX for fetching students ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_payment_students') {
    header('Content-Type: application/json');
    try {
        $paymentId = (int)$_POST['payment_id'];
        $stmt = $pdo->prepare("SELECT student_ids FROM share_payments WHERE id = ?");
        $stmt->execute([$paymentId]);
        $jsonIds = $stmt->fetchColumn();
        if (!$jsonIds) { echo json_encode(['success'=>false, 'message'=>'Payment record not found.']); exit; }
        
        $studentIds = json_decode($jsonIds, true);
        if (!is_array($studentIds) || empty($studentIds)) { echo json_encode(['success'=>true, 'students'=>[]]); exit; }
        
        $inClause = implode(',', array_fill(0, count($studentIds), '?'));
        $stmt = $pdo->prepare("
            SELECT a.roll_no, a.registration_id, a.course,
                   TRIM(CONCAT(a.first_name, ' ', COALESCE(a.middle_name, ''), ' ', a.last_name)) as student_name,
                   COALESCE(c.ho_share, 0) as ho_share, COALESCE(c.ho_share_type, 'fixed') as ho_share_type,
                   a.course_fees, a.discount_amount
            FROM admissions a
            LEFT JOIN courses c ON a.course_id = c.id
            WHERE a.id IN ($inClause)
        ");
        $stmt->execute($studentIds);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $processed = [];
        foreach ($students as $s) {
            $netFee = floatval($s['course_fees'] ?? 0) - floatval($s['discount_amount'] ?? 0);
            $shareVal = floatval($s['ho_share']);
            $shareAmt = ($s['ho_share_type'] === 'percent') ? round(($netFee * $shareVal) / 100, 2) : $shareVal;
            $processed[] = [
                'roll_no' => $s['roll_no'],
                'registration_id' => $s['registration_id'],
                'student_name' => $s['student_name'],
                'course' => $s['course'],
                'share_amount' => $shareAmt
            ];
        }
        echo json_encode(['success'=>true, 'students'=>$processed]);
    } catch (Exception $e) { echo json_encode(['success'=>false, 'message'=>$e->getMessage()]); }
    exit;
}

// Filters
$atcFilter = $_GET['atc'] ?? 'all';
$statusFilter = $_GET['status'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Get overall statistics
$stmt = $pdo->query("
    SELECT 
        COUNT(DISTINCT atc_id) as total_atc_paid,
        COUNT(*) as total_transactions,
        COALESCE(SUM(CASE WHEN status = 'Completed' THEN total_share_amount ELSE 0 END), 0) as total_received,
        COALESCE(SUM(CASE WHEN status = 'Completed' THEN transaction_fee ELSE 0 END), 0) as total_fees_collected,
        COALESCE(SUM(CASE WHEN status = 'Pending' THEN total_amount ELSE 0 END), 0) as pending_amount
    FROM share_payments
");
$overallStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get ATC-wise summary
$atcSummaryQuery = "
    SELECT 
        atc.id,
        atc.name as atc_name,
        atc.district,
        dlc.name as dlc_name,
        COUNT(DISTINCT a.id) as total_students,
        COUNT(DISTINCT sp.id) as payment_count,
        COALESCE(SUM(CASE WHEN sp.status = 'Completed' THEN sp.total_share_amount ELSE 0 END), 0) as total_paid,
        COALESCE(SUM(CASE WHEN sp.status = 'Completed' THEN sp.transaction_fee ELSE 0 END), 0) as fees_paid,
        (
            SELECT COUNT(DISTINCT JSON_EXTRACT(student_ids, '$[*]'))
            FROM share_payments
            WHERE atc_id = atc.id AND status = 'Completed'
        ) as students_paid_count
    FROM atc_centers atc
    LEFT JOIN dlc_offices dlc ON atc.dlc_id = dlc.id
    LEFT JOIN admissions a ON atc.id = a.atc_id AND a.status = 'Active'
    LEFT JOIN share_payments sp ON atc.id = sp.atc_id
    WHERE 1=1
";

if ($atcFilter !== 'all') {
    $atcSummaryQuery .= " AND atc.id = " . intval($atcFilter);
}

$atcSummaryQuery .= " GROUP BY atc.id ORDER BY total_paid DESC";

$stmt = $pdo->query($atcSummaryQuery);
$atcSummary = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment transactions
$transactionsQuery = "
    SELECT 
        sp.*,
        atc.name as atc_name,
        atc.district as atc_district,
        dlc.name as dlc_name
    FROM share_payments sp
    JOIN atc_centers atc ON sp.atc_id = atc.id
    LEFT JOIN dlc_offices dlc ON atc.dlc_id = dlc.id
    WHERE 1=1
";

$params = [];

if ($atcFilter !== 'all') {
    $transactionsQuery .= " AND sp.atc_id = ?";
    $params[] = $atcFilter;
}

if ($statusFilter !== 'all') {
    $transactionsQuery .= " AND sp.status = ?";
    $params[] = $statusFilter;
}

if ($dateFrom) {
    $transactionsQuery .= " AND DATE(sp.created_at) >= ?";
    $params[] = $dateFrom;
}

if ($dateTo) {
    $transactionsQuery .= " AND DATE(sp.created_at) <= ?";
    $params[] = $dateTo;
}

// Count + paginate transactions
$countTxSql = "SELECT COUNT(*) FROM share_payments sp
               JOIN atc_centers atc ON sp.atc_id = atc.id
               LEFT JOIN dlc_offices dlc ON atc.dlc_id = dlc.id
               WHERE 1=1";
$countTxParams = [];
if ($atcFilter !== 'all') {
    $countTxSql .= " AND sp.atc_id = ?";
    $countTxParams[] = $atcFilter;
}
if ($statusFilter !== 'all') {
    $countTxSql .= " AND sp.status = ?";
    $countTxParams[] = $statusFilter;
}
if ($dateFrom) {
    $countTxSql .= " AND DATE(sp.created_at) >= ?";
    $countTxParams[] = $dateFrom;
}
if ($dateTo) {
    $countTxSql .= " AND DATE(sp.created_at) <= ?";
    $countTxParams[] = $dateTo;
}
$countTxStmt = $pdo->prepare($countTxSql);
$countTxStmt->execute($countTxParams);
$txPager = paginationMeta((int)$countTxStmt->fetchColumn(), paginationParams(25));

$transactionsQuery .= " ORDER BY sp.created_at DESC
                        LIMIT {$txPager['per_page']} OFFSET {$txPager['offset']}";

$stmt = $pdo->prepare($transactionsQuery);
$stmt->execute($params);
$transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get ATC list for filter
$stmt = $pdo->query("SELECT id, name FROM atc_centers WHERE status = 'Active' ORDER BY name");
$atcList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly trend
$stmt = $pdo->query("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as transaction_count,
        SUM(CASE WHEN status = 'Completed' THEN total_share_amount ELSE 0 END) as revenue
    FROM share_payments
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC
");
$monthlyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Share Payments — Admin | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💰</text></svg>">

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
            background: linear-gradient(135deg, #00c48c, #00a376);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-glow-emerald);
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

        .page-header-right {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .live-badge {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            background: var(--color-emerald-soft);
            border: 1.5px solid #b3f0de;
            border-radius: var(--radius-full);
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--color-emerald-dark);
            letter-spacing: 0.02em;
        }

        .live-dot {
            width: 8px;
            height: 8px;
            background: var(--color-emerald);
            border-radius: 50%;
            animation: pulse-dot 2s infinite;
        }

        @keyframes pulse-dot {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(0.85); }
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

        .kpi-card.emerald::before { background: linear-gradient(90deg, var(--color-emerald), #34d399); }
        .kpi-card.indigo::before { background: linear-gradient(90deg, var(--color-indigo), #818cf8); }
        .kpi-card.amber::before { background: linear-gradient(90deg, var(--color-amber), #fbbf24); }
        .kpi-card.rose::before { background: linear-gradient(90deg, var(--color-rose), #fb7185); }

        .kpi-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 1.25rem;
        }

        .kpi-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .kpi-icon.emerald { background: var(--color-emerald-soft); }
        .kpi-icon.indigo { background: var(--color-indigo-soft); }
        .kpi-icon.amber { background: var(--color-amber-soft); }
        .kpi-icon.rose { background: var(--color-rose-soft); }

        .kpi-icon.emerald svg { stroke: var(--color-emerald-dark); }
        .kpi-icon.indigo svg { stroke: var(--color-indigo); }
        .kpi-icon.amber svg { stroke: var(--color-amber); }
        .kpi-icon.rose svg { stroke: var(--color-rose); }

        .kpi-icon svg { width: 22px; height: 22px; }

        .kpi-trend {
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.3rem 0.6rem;
            border-radius: var(--radius-full);
        }

        .kpi-trend.up { background: var(--color-emerald-soft); color: var(--color-emerald-dark); }
        .kpi-trend.neutral { background: var(--color-slate-soft); color: var(--color-text-muted); }

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

        .filter-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem 1.75rem;
            border-bottom: 1px solid var(--color-border);
            background: var(--color-surface-raised);
        }

        .filter-panel-title {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--color-text-primary);
        }

        .filter-panel-title svg {
            width: 18px;
            height: 18px;
            stroke: var(--color-indigo);
        }

        .btn-ghost {
            display: flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.5rem 1rem;
            background: transparent;
            border: 1.5px solid var(--color-border);
            border-radius: var(--radius-md);
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--color-text-secondary);
            cursor: pointer;
            font-family: var(--font-primary);
            transition: all 0.2s ease;
        }

        .btn-ghost:hover {
            background: #fff5f5;
            border-color: #fca5a5;
            color: var(--color-rose);
        }

        .filter-panel-body {
            padding: 1.5rem 1.75rem;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto;
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

        .form-control:hover {
            border-color: var(--color-border-strong);
            background: var(--color-surface);
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
            white-space: nowrap;
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

        .section-dot.emerald { background: var(--color-emerald); }
        .section-dot.indigo { background: var(--color-indigo); }
        .section-dot.amber { background: var(--color-amber); }

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

        .btn-outline {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.6rem 1.25rem;
            background: var(--color-surface);
            border: 1.5px solid var(--color-border);
            border-radius: var(--radius-md);
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--color-text-secondary);
            cursor: pointer;
            font-family: var(--font-primary);
            transition: all 0.2s ease;
        }

        .btn-outline:hover {
            border-color: var(--color-indigo);
            color: var(--color-indigo);
            background: var(--color-indigo-soft);
        }

        .btn-outline svg { width: 15px; height: 15px; }

        /* ===== TABLE ===== */
        .table-wrap {
            background: var(--color-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--color-border);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
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

        .data-table tbody tr:last-child {
            border-bottom: none;
        }

        .data-table tbody tr:hover {
            background: #fafbff;
        }

        .data-table tbody td {
            padding: 1.1rem 1.25rem;
            color: var(--color-text-primary);
            font-size: 0.9rem;
        }

        /* Cell helpers */
        .cell-primary {
            font-weight: 600;
            color: var(--color-text-primary);
        }

        .cell-secondary {
            font-size: 0.8rem;
            color: var(--color-text-muted);
            margin-top: 0.15rem;
        }

        .cell-mono {
            font-family: var(--font-mono);
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--color-indigo);
        }

        .cell-amount {
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }

        .cell-amount.positive { color: var(--color-emerald-dark); }
        .cell-amount.neutral { color: var(--color-text-primary); }

        /* Progress bar */
        .progress-wrap {
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .progress-track {
            flex: 1;
            height: 6px;
            background: var(--color-border);
            border-radius: var(--radius-full);
            overflow: hidden;
            min-width: 80px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--color-emerald), #34d399);
            border-radius: var(--radius-full);
            transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .progress-text {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--color-emerald-dark);
            min-width: 40px;
            font-variant-numeric: tabular-nums;
        }

        .progress-count {
            font-weight: 700;
            color: var(--color-emerald-dark);
        }

        /* Badges */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.3rem 0.75rem;
            border-radius: var(--radius-full);
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.01em;
        }

        .badge-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
        }

        .badge.success { background: var(--color-emerald-soft); color: var(--color-emerald-dark); border: 1px solid #b3f0de; }
        .badge.success .badge-dot { background: var(--color-emerald); }

        .badge.warning { background: var(--color-amber-soft); color: #92400e; border: 1px solid #fde68a; }
        .badge.warning .badge-dot { background: var(--color-amber); }

        .badge.pending { background: var(--color-amber-soft); color: #92400e; border: 1px solid #fde68a; }
        .badge.pending .badge-dot { background: var(--color-amber); animation: pulse-dot 1.5s infinite; }

        .badge.failed { background: var(--color-rose-soft); color: #9f1239; border: 1px solid #fecdd3; }
        .badge.failed .badge-dot { background: var(--color-rose); }

        .badge.completed { background: var(--color-emerald-soft); color: var(--color-emerald-dark); border: 1px solid #b3f0de; }
        .badge.completed .badge-dot { background: var(--color-emerald); }

        /* Action button */
        .btn-action {
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--color-surface-raised);
            border: 1.5px solid var(--color-border);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-action:hover {
            background: var(--color-indigo-soft);
            border-color: var(--color-indigo);
        }

        .btn-action:hover svg { stroke: var(--color-indigo); }
        .btn-action svg { width: 16px; height: 16px; stroke: var(--color-text-muted); transition: stroke 0.2s; }

        /* Empty state */
        .table-empty {
            text-align: center;
            padding: 3.5rem 2rem !important;
        }

        .empty-icon {
            width: 48px;
            height: 48px;
            background: var(--color-surface-raised);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1rem;
            border: 1px solid var(--color-border);
        }

        .empty-icon svg { width: 24px; height: 24px; stroke: var(--color-text-muted); }
        .empty-title { font-size: 0.9rem; font-weight: 600; color: var(--color-text-secondary); }
        .empty-sub { font-size: 0.8rem; color: var(--color-text-muted); margin-top: 0.25rem; }

        /* ===== CHART ===== */
        .chart-card {
            background: var(--color-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--color-border);
            box-shadow: var(--shadow-sm);
            padding: 1.75rem;
            margin-bottom: 2rem;
        }

        .chart-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2rem;
        }

        .chart-area {
            display: flex;
            align-items: flex-end;
            gap: 1rem;
            height: 200px;
        }

        .chart-col {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            height: 100%;
            gap: 0.5rem;
        }

        .chart-bar-wrap {
            flex: 1;
            width: 100%;
            display: flex;
            align-items: flex-end;
            justify-content: center;
        }

        .chart-bar {
            width: 100%;
            max-width: 56px;
            background: linear-gradient(180deg, #00c48c 0%, #00a376 100%);
            border-radius: var(--radius-md) var(--radius-md) 4px 4px;
            min-height: 12px;
            position: relative;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
        }

        .chart-bar:hover {
            filter: brightness(1.1);
            transform: scaleY(1.02);
            transform-origin: bottom;
        }

        .chart-bar-tooltip {
            position: absolute;
            top: -36px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--color-slate);
            color: white;
            padding: 0.3rem 0.6rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 700;
            white-space: nowrap;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s;
            font-family: var(--font-mono);
        }

        .chart-bar:hover .chart-bar-tooltip { opacity: 1; }

        .chart-bar-tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 4px solid transparent;
            border-top-color: var(--color-slate);
        }

        .chart-month {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--color-text-muted);
            text-align: center;
            white-space: nowrap;
        }

        .chart-txns {
            font-size: 0.7rem;
            color: var(--color-text-muted);
            text-align: center;
        }

        /* ===== MODAL ===== */
        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 21, 35, 0.5);
            backdrop-filter: blur(4px);
            z-index: 1000;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-panel {
            background: var(--color-surface);
            border-radius: var(--radius-xl);
            border: 1px solid var(--color-border);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 760px;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
            animation: modalIn 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        @keyframes modalIn {
            from { opacity: 0; transform: scale(0.95) translateY(8px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }

        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1.25rem 1.75rem;
            border-bottom: 1px solid var(--color-border);
        }

        .modal-header-left {
            display: flex;
            align-items: center;
            gap: 0.875rem;
        }

        .modal-icon {
            width: 40px;
            height: 40px;
            background: var(--color-indigo-soft);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-icon svg { width: 20px; height: 20px; stroke: var(--color-indigo); }

        .modal-title {
            font-size: 1.0625rem;
            font-weight: 800;
            color: var(--color-text-primary);
            letter-spacing: -0.02em;
        }

        .modal-subtitle {
            font-size: 0.8rem;
            color: var(--color-text-muted);
        }

        .btn-close {
            width: 34px;
            height: 34px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--color-surface-raised);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-close:hover { background: var(--color-rose-soft); border-color: #fca5a5; }
        .btn-close:hover svg { stroke: var(--color-rose); }
        .btn-close svg { width: 16px; height: 16px; stroke: var(--color-text-muted); }

        .modal-body {
            padding: 1.75rem;
            overflow-y: auto;
            flex: 1;
        }

        .modal-footer {
            padding: 1.25rem 1.75rem;
            border-top: 1px solid var(--color-border);
            display: flex;
            justify-content: flex-end;
        }

        /* Modal Content */
        .detail-section {
            margin-bottom: 1.75rem;
        }

        .detail-section-title {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--color-text-muted);
            margin-bottom: 1rem;
            padding-bottom: 0.625rem;
            border-bottom: 1px solid var(--color-border);
        }

        .detail-section-title svg { width: 16px; height: 16px; }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
        }

        .detail-item {
            background: var(--color-surface-raised);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            padding: 0.875rem 1rem;
        }

        .detail-item.wide {
            grid-column: 1 / -1;
        }

        .detail-item-label {
            font-size: 0.75rem;
            color: var(--color-text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 0.35rem;
        }

        .detail-item-value {
            font-size: 0.9375rem;
            font-weight: 700;
            color: var(--color-text-primary);
        }

        .detail-item-value.mono {
            font-family: var(--font-mono);
            font-size: 0.85rem;
        }

        .detail-item-value.positive { color: var(--color-emerald-dark); }

        /* Student list in modal */
        .student-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 0.625rem;
        }

        .student-chip {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.625rem 0.875rem;
            background: var(--color-surface-raised);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
        }

        .student-chip-num {
            width: 22px;
            height: 22px;
            background: var(--color-indigo-soft);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.7rem;
            font-weight: 800;
            color: var(--color-indigo);
            flex-shrink: 0;
        }

        .student-chip-id {
            font-size: 0.8125rem;
            font-weight: 600;
            color: var(--color-text-secondary);
            font-family: var(--font-mono);
        }

        /* Loading */
        .loading-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 3rem;
            gap: 0.875rem;
        }

        .spinner {
            width: 36px;
            height: 36px;
            border: 3px solid var(--color-border);
            border-top-color: var(--color-indigo);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 1280px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }

        @media (max-width: 1024px) {
            .filter-panel-body {
                grid-template-columns: 1fr 1fr;
            }
        }

        @media (max-width: 768px) {
            .kpi-grid { grid-template-columns: 1fr; }
            .filter-panel-body { grid-template-columns: 1fr; }
            .page-header-block { flex-direction: column; align-items: flex-start; gap: 1rem; }
            .detail-grid { grid-template-columns: 1fr 1fr; }
        }

        @media print {
            .sidebar, .top-header, .filter-panel, .btn-action, .btn-outline, .btn-export, .modal-overlay { display: none !important; }
            .main-content { margin-left: 0 !important; }
            .kpi-card { box-shadow: none; border: 1px solid #ccc; }
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
                    <h2>Share Payments</h2>
                    <p>Track & manage ATC share payments</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content">

            <!-- Page Header -->
            <div class="page-header-block">
                <div class="page-header-left">
                    <div class="page-header-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div>
                        <h1 class="page-header-title">Share Payments</h1>
                        <p class="page-header-subtitle">Monitor ATC share payments &amp; transaction history</p>
                    </div>
                </div>
                <div class="page-header-right">
                    <span class="live-badge">
                        <span class="live-dot"></span>
                        Live Tracking
                    </span>
                    <button class="btn-outline" onclick="window.print()">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                        Print Report
                    </button>
                </div>
            </div>

            <!-- KPI Grid -->
            <div class="kpi-grid">
                <div class="kpi-card emerald">
                    <div class="kpi-top">
                        <div class="kpi-icon emerald">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        </div>
                        <span class="kpi-trend up">↑ Share</span>
                    </div>
                    <div class="kpi-value">₹<?= number_format($overallStats['total_received'], 0) ?></div>
                    <div class="kpi-label">Total Received</div>
                </div>

                <div class="kpi-card indigo">
                    <div class="kpi-top">
                        <div class="kpi-icon indigo">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                        </div>
                        <span class="kpi-trend neutral">Centers</span>
                    </div>
                    <div class="kpi-value"><?= $overallStats['total_atc_paid'] ?></div>
                    <div class="kpi-label">ATCs Paid</div>
                </div>

                <div class="kpi-card amber">
                    <div class="kpi-top">
                        <div class="kpi-icon amber">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                        </div>
                        <span class="kpi-trend neutral">All time</span>
                    </div>
                    <div class="kpi-value"><?= $overallStats['total_transactions'] ?></div>
                    <div class="kpi-label">Total Transactions</div>
                </div>

                <div class="kpi-card rose">
                    <div class="kpi-top">
                        <div class="kpi-icon rose">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        </div>
                        <span class="kpi-trend neutral">Fees</span>
                    </div>
                    <div class="kpi-value">₹<?= number_format($overallStats['total_fees_collected'], 0) ?></div>
                    <div class="kpi-label">Transaction Fees</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-panel">
                <div class="filter-panel-header">
                    <span class="filter-panel-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                        Filter Payments
                    </span>
                    <button type="button" class="btn-ghost" onclick="window.location.href='share_payments.php'">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:14px;height:14px;"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></svg>
                        Reset Filters
                    </button>
                </div>
                <form method="GET" class="filter-panel-body">
                    <div class="filter-group">
                        <label class="filter-label">ATC Center</label>
                        <select name="atc" class="form-control">
                            <option value="all">All ATC Centers</option>
                            <?php foreach ($atcList as $atc): ?>
                                <option value="<?= $atc['id'] ?>" <?= $atcFilter == $atc['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($atc['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">Status</label>
                        <select name="status" class="form-control">
                            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Status</option>
                            <option value="Completed" <?= $statusFilter === 'Completed' ? 'selected' : '' ?>>Completed</option>
                            <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
                            <option value="Failed" <?= $statusFilter === 'Failed' ? 'selected' : '' ?>>Failed</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">From Date</label>
                        <input type="date" name="date_from" class="form-control" value="<?= $dateFrom ?>">
                    </div>
                    <div class="filter-group">
                        <label class="filter-label">To Date</label>
                        <input type="date" name="date_to" class="form-control" value="<?= $dateTo ?>">
                    </div>
                    <button type="submit" class="btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                        Apply
                    </button>
                </form>
            </div>

            <!-- ATC Summary Section -->
            <div class="section-head">
                <div class="section-head-left">
                    <span class="section-dot indigo"></span>
                    <h2 class="section-title">ATC-wise Payment Summary</h2>
                    <span class="section-count"><?= count($atcSummary) ?> centers</span>
                </div>
                <button class="btn-outline" onclick="exportToExcel()">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Export Excel
                </button>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ATC Center</th>
                            <th>DLC Office</th>
                            <th>Total Students</th>
                            <th>Payment Progress</th>
                            <th>Remaining</th>
                            <th>Txns</th>
                            <th>Total Paid</th>
                            <th>Fees</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($atcSummary)): ?>
                            <tr>
                                <td colspan="9" class="table-empty">
                                    <div class="empty-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                                    </div>
                                    <div class="empty-title">No records found</div>
                                    <div class="empty-sub">No ATC payment data available.</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($atcSummary as $atc):
                                $remaining = $atc['total_students'] - $atc['students_paid_count'];
                                $paymentRate = $atc['total_students'] > 0 ? ($atc['students_paid_count'] / $atc['total_students']) * 100 : 0;
                            ?>
                                <tr>
                                    <td>
                                        <div class="cell-primary"><?= htmlspecialchars($atc['atc_name']) ?></div>
                                        <div class="cell-secondary"><?= htmlspecialchars($atc['district']) ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($atc['dlc_name']) ?></td>
                                    <td><strong><?= $atc['total_students'] ?></strong></td>
                                    <td>
                                        <div class="progress-wrap">
                                            <span class="progress-count"><?= $atc['students_paid_count'] ?></span>
                                            <div class="progress-track">
                                                <div class="progress-fill" style="width: <?= min($paymentRate, 100) ?>%;"></div>
                                            </div>
                                            <span class="progress-text"><?= number_format($paymentRate, 0) ?>%</span>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($remaining > 0): ?>
                                            <span class="badge warning"><span class="badge-dot"></span><?= $remaining ?> pending</span>
                                        <?php else: ?>
                                            <span class="badge success"><span class="badge-dot"></span>All paid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= $atc['payment_count'] ?></td>
                                    <td class="cell-amount positive">₹<?= number_format($atc['total_paid'], 0) ?></td>
                                    <td class="cell-amount neutral">₹<?= number_format($atc['fees_paid'], 0) ?></td>
                                    <td>
                                        <button class="btn-action" onclick="viewATCDetails(<?= $atc['id'] ?>)" title="View Details">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Transactions Section -->
            <div class="section-head">
                <div class="section-head-left">
                    <span class="section-dot emerald"></span>
                    <h2 class="section-title">Recent Transactions</h2>
                    <span class="section-count">Latest 50</span>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Payment ID</th>
                            <th>Date &amp; Time</th>
                            <th>ATC Center</th>
                            <th>Students</th>
                            <th>Share Amount</th>
                            <th>Txn Fee</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="9" class="table-empty">
                                    <div class="empty-icon">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                    </div>
                                    <div class="empty-title">No transactions found</div>
                                    <div class="empty-sub">Try adjusting your filters.</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $txn):
                                $studentIds = json_decode($txn['student_ids'], true);
                                $statusClass = strtolower($txn['status']);
                            ?>
                                <tr>
                                    <td><span class="cell-mono">#<?= $txn['id'] ?></span></td>
                                    <td>
                                        <div class="cell-primary"><?= date('d M Y', strtotime($txn['created_at'])) ?></div>
                                        <div class="cell-secondary"><?= date('h:i A', strtotime($txn['created_at'])) ?></div>
                                    </td>
                                    <td>
                                        <div class="cell-primary"><?= htmlspecialchars($txn['atc_name']) ?></div>
                                        <div class="cell-secondary"><?= htmlspecialchars($txn['dlc_name']) ?></div>
                                    </td>
                                    <td><strong><?= count($studentIds) ?></strong></td>
                                    <td class="cell-amount positive">₹<?= number_format($txn['total_share_amount'], 0) ?></td>
                                    <td class="cell-amount neutral">₹<?= number_format($txn['transaction_fee'], 0) ?></td>
                                    <td class="cell-amount neutral"><strong>₹<?= number_format($txn['total_amount'], 0) ?></strong></td>
                                    <td>
                                        <span class="badge <?= $statusClass ?>">
                                            <span class="badge-dot"></span>
                                            <?= $txn['status'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn-action" onclick="viewTransactionDetails(<?= $txn['id'] ?>)" title="View Details">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?= renderPagination($txPager, 'transactions') ?>

            <!-- Monthly Trend -->
            <?php if (!empty($monthlyTrend)): ?>
            <div class="section-head">
                <div class="section-head-left">
                    <span class="section-dot amber"></span>
                    <h2 class="section-title">Monthly Revenue Trend</h2>
                    <span class="section-count">Last 6 months</span>
                </div>
            </div>

            <div class="chart-card">
                <div class="chart-area">
                    <?php
                    $maxRevenue = max(array_column($monthlyTrend, 'revenue'));
                    foreach ($monthlyTrend as $month):
                        $pct = $maxRevenue > 0 ? ($month['revenue'] / $maxRevenue) * 100 : 0;
                        $monthName = date('M Y', strtotime($month['month'] . '-01'));
                        $barHeight = max(12, round($pct * 1.6)); // scale to 160px max
                    ?>
                        <div class="chart-col">
                            <div class="chart-bar-wrap">
                                <div class="chart-bar" style="height: <?= $barHeight ?>px;">
                                    <div class="chart-bar-tooltip">₹<?= number_format($month['revenue'] / 1000, 1) ?>K</div>
                                </div>
                            </div>
                            <div class="chart-month"><?= $monthName ?></div>
                            <div class="chart-txns"><?= $month['transaction_count'] ?> txns</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /page-content -->
    </main>
</div>

<!-- Transaction Detail Modal -->
<div class="modal-overlay" id="transactionModal">
    <div class="modal-panel">
        <div class="modal-header">
            <div class="modal-header-left">
                <div class="modal-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                </div>
                <div>
                    <div class="modal-title" id="modalTitle">Transaction Details</div>
                    <div class="modal-subtitle" id="modalSubtitle">Payment information &amp; student list</div>
                </div>
            </div>
            <button class="btn-close" onclick="closeModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div class="modal-body" id="modalBody">
            <div class="loading-state">
                <div class="spinner"></div>
                <span style="font-size: 0.875rem; color: var(--color-text-muted);">Loading details…</span>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-ghost" onclick="closeModal()">Close</button>
        </div>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
const transactions = <?= json_encode($transactions) ?>;

function exportToExcel() {
    alert('Export functionality will download the payment summary as Excel.\n\nThis feature requires backend implementation.');
}

function viewATCDetails(atcId) {
    window.location.href = `atc_centers.php?action=view&id=${atcId}`;
}

function viewTransactionDetails(txnId) {
    const txn = transactions.find(t => t.id == txnId);
    if (!txn) return;

    const studentIds = JSON.parse(txn.student_ids || '[]');
    const statusClass = txn.status.toLowerCase();

    document.getElementById('modalTitle').textContent = `Transaction #${txn.id}`;
    document.getElementById('modalSubtitle').textContent = `${txn.atc_name} · ${studentIds.length} students`;
    
    // Set loading state
    document.getElementById('modalBody').innerHTML = `
        <div class="loading-state">
            <div class="spinner"></div>
            <span style="font-size: 0.875rem; color: var(--color-text-muted);">Loading details…</span>
        </div>
    `;
    document.getElementById('transactionModal').classList.add('active');

    // Fetch actual student details
    fetch('share_payments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=get_payment_students&payment_id=' + txnId
    })
    .then(r => r.json())
    .then(res => {
        let studentsHtml = '';
        if (!res.success) {
            studentsHtml = `<div style="padding:1rem;color:#dc2626">${res.message || 'Failed to load'}</div>`;
        } else if (res.students.length === 0) {
            studentsHtml = `<div style="padding:1rem;color:var(--color-text-muted)">No students found.</div>`;
        } else {
            studentsHtml = res.students.map((s, i) => `
                <div class="student-item" style="display:flex; justify-content:space-between; align-items:center; padding: 0.8rem 1rem; border-bottom: 1px solid var(--color-border); background: var(--color-surface-raised); border-radius: var(--radius-md); margin-bottom: 0.5rem;">
                    <div>
                        <div style="font-weight:700; color:var(--color-text-primary); font-size:0.9rem;">${i+1}. ${s.student_name}</div>
                        <div style="font-size:0.75rem; color:var(--color-text-muted); font-family:var(--font-mono); margin-top:0.2rem;">${s.roll_no} &bull; ${s.registration_id} &bull; ${s.course}</div>
                    </div>
                    <div style="font-weight:700; color:var(--color-emerald-dark);">₹${parseFloat(s.share_amount).toFixed(2)}</div>
                </div>
            `).join('');
        }

        const html = `
            <div class="detail-section">
                <div class="detail-section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    Payment Information
                </div>
                <div class="detail-grid">
                    <div class="detail-item">
                        <div class="detail-item-label">Payment ID</div>
                        <div class="detail-item-value mono">#${txn.id}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-item-label">Status</div>
                        <div class="detail-item-value">
                            <span class="badge ${statusClass}"><span class="badge-dot"></span>${txn.status}</span>
                        </div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-item-label">ATC Center</div>
                        <div class="detail-item-value">${txn.atc_name}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-item-label">DLC Office</div>
                        <div class="detail-item-value">${txn.dlc_name || '—'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-item-label">Created</div>
                        <div class="detail-item-value">${new Date(txn.created_at).toLocaleString('en-IN')}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-item-label">Paid At</div>
                        <div class="detail-item-value">${txn.paid_at ? new Date(txn.paid_at).toLocaleString('en-IN') : '—'}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-item-label">Share Amount</div>
                        <div class="detail-item-value positive">₹${Number(txn.total_share_amount).toLocaleString('en-IN')}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-item-label">Transaction Fee</div>
                        <div class="detail-item-value">₹${Number(txn.transaction_fee).toLocaleString('en-IN')}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-item-label">Total Amount</div>
                        <div class="detail-item-value positive"><strong>₹${Number(txn.total_amount).toLocaleString('en-IN')}</strong></div>
                    </div>
                    ${txn.razorpay_payment_id ? `
                    <div class="detail-item wide">
                        <div class="detail-item-label">Razorpay Payment ID</div>
                        <div class="detail-item-value mono">${txn.razorpay_payment_id}</div>
                    </div>` : ''}
                </div>
            </div>

            <div class="detail-section">
                <div class="detail-section-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Students Included (${studentIds.length})
                </div>
                <div style="max-height: 250px; overflow-y: auto; padding-right: 0.5rem;">
                    ${studentsHtml}
                </div>
            </div>
        `;

        document.getElementById('modalBody').innerHTML = html;
    }).catch(err => {
        document.getElementById('modalBody').innerHTML = `<div style="padding:2rem; text-align:center; color:#dc2626;">Failed to fetch details.</div>`;
    });
}

function closeModal() {
    document.getElementById('transactionModal').classList.remove('active');
}

document.getElementById('transactionModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeModal();
});
</script>

</body>
</html>