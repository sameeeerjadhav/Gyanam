<?php
/**
 * Gyanam Portal — Head Office Dashboard
 * L — Major Revamp (L1: rename cards, L2: clickable admissions/inquiries, L3: reporting cards)
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
$greeting = getGreeting();

try { ensurePerformanceIndexes($pdo); } catch (Exception $e) {}

/**
 * Top ATCs by HO share paid (Completed share_payments), optional center-type + period filters.
 *
 * @return list<array{atc_name:string,atc_code:?string,center_type:?string,dlc_name:?string,student_count:int,revenue_collected:float}>
 */
function fetchTopPerformingAtcs(PDO $pdo, string $centerType = '', string $period = 'all', int $limit = 10): array {
    $centerType = trim($centerType);
    $period = strtolower(trim($period));
    if (!in_array($period, ['today', 'month', 'year', 'all'], true)) {
        $period = 'all';
    }
    $limit = max(1, min(25, $limit));

    // Period applies to share payments (admin revenue), not ATC student fee collections
    $dateSql = '';
    if ($period === 'today') {
        $dateSql = ' AND DATE(sp.created_at) = CURDATE()';
    } elseif ($period === 'month') {
        $dateSql = ' AND sp.created_at >= DATE_FORMAT(CURDATE(), \'%Y-%m-01\')';
    } elseif ($period === 'year') {
        $dateSql = ' AND YEAR(sp.created_at) = YEAR(CURDATE())';
    }

    $typeSql = '';
    $params = [];
    if ($centerType === 'Abacus') {
        $typeSql = " AND atc.center_type LIKE ?";
        $params[] = '%Abacus%';
    } elseif ($centerType === 'Vedic Maths') {
        $typeSql = " AND atc.center_type LIKE ?";
        $params[] = '%Vedic%';
    } elseif ($centerType === 'IT') {
        $typeSql = " AND (
            atc.center_type = 'IT'
            OR atc.center_type LIKE '%+ IT'
            OR atc.center_type LIKE 'IT +%'
            OR atc.center_type LIKE '%+ IT +%'
        )";
    }

    $sql = "
        SELECT atc.name AS atc_name,
               atc.atc_code,
               atc.center_type,
               dlc.name AS dlc_name,
               (SELECT COUNT(*) FROM admissions adm
                 WHERE adm.atc_id = atc.id AND adm.status = 'Active') AS student_count,
               COALESCE(SUM(CASE WHEN sp.status = 'Completed' THEN sp.total_share_amount ELSE 0 END), 0) AS revenue_collected
        FROM atc_centers atc
        LEFT JOIN dlc_offices dlc ON atc.dlc_id = dlc.id
        LEFT JOIN share_payments sp ON atc.id = sp.atc_id $dateSql
        WHERE atc.status = 'Active'
          $typeSql
        GROUP BY atc.id
        HAVING student_count > 0 OR revenue_collected > 0
        ORDER BY revenue_collected DESC, student_count DESC
        LIMIT $limit
    ";

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        try {
            $sql2 = str_replace('atc.atc_code,', "NULL AS atc_code,", $sql);
            $st = $pdo->prepare($sql2);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {
            return [];
        }
    }
}

// AJAX: filtered top ATCs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'top_atcs') {
    header('Content-Type: application/json; charset=utf-8');
    $centerType = (string)($_POST['center_type'] ?? '');
    $period = (string)($_POST['period'] ?? 'all');
    $rows = fetchTopPerformingAtcs($pdo, $centerType, $period, 10);
    echo json_encode(['success' => true, 'rows' => $rows, 'center_type' => $centerType, 'period' => $period]);
    exit;
}

// Birthday push notifications: use cron/birthday_notifications.php (not every dashboard load)

// ── Stats (session-cached 60s) ────────────────────────────────────────────────
$dashCacheKey = 'admin_dash_stats_v1';
$dashCacheAt  = 'admin_dash_stats_at';
$useDashCache = isset($_SESSION[$dashCacheKey], $_SESSION[$dashCacheAt])
    && (time() - (int)$_SESSION[$dashCacheAt]) < 60;

if ($useDashCache) {
    extract($_SESSION[$dashCacheKey], EXTR_OVERWRITE);
} else {
$totalUsers = $totalDLC = $totalATC = $totalInquiries = $totalAdmissions = 0;
$pendingExam = 0;

try { $totalUsers      = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn(); }            catch (Exception $e) {}
try { $totalDLC        = $pdo->query("SELECT COUNT(*) FROM dlc_offices")->fetchColumn(); }     catch (Exception $e) {}
try { $totalATC        = $pdo->query("SELECT COUNT(*) FROM atc_centers")->fetchColumn(); }     catch (Exception $e) {}
try { $totalInquiries  = $pdo->query("SELECT COUNT(*) FROM inquiries")->fetchColumn(); }       catch (Exception $e) {}
try { $totalAdmissions = $pdo->query("SELECT COUNT(*) FROM admissions")->fetchColumn(); }      catch (Exception $e) {}

try {
    $pendingExam = $pdo->query("
        SELECT COUNT(*) FROM admissions
        WHERE status = 'Active'
          AND (exam_date IS NULL OR exam_date >= CURDATE())
    ")->fetchColumn();
} catch (Exception $e) {}

$_SESSION[$dashCacheKey] = compact('totalUsers', 'totalDLC', 'totalATC', 'totalInquiries', 'totalAdmissions', 'pendingExam');
$_SESSION[$dashCacheAt] = time();
}

$expiringATCs = [];

// Keep pendingExam as int for templates
$pendingExam = (int)$pendingExam;

// ── Ops counts: certificates / dispatches / materials ─────────────────────────
$certifiedStudents   = 0;
$certPrintPending    = 0;
$todayDispatches     = 0;
$pendingDispatches   = 0; // in-transit material_dispatches (status = Dispatched)
$courseMaterialPending = 0; // With Material students not yet dispatched

try {
    if (function_exists('ensureIssuedCertificatesTable')) {
        ensureIssuedCertificatesTable($pdo);
    }
    $certifiedStudents = (int)$pdo->query('SELECT COUNT(*) FROM issued_certificates')->fetchColumn();
} catch (Exception $e) {}
try {
    $issuedAlt = (int)$pdo->query("SELECT COUNT(*) FROM certificates WHERE status = 'Issued'")->fetchColumn();
    if ($issuedAlt > $certifiedStudents) {
        $certifiedStudents = $issuedAlt;
    }
} catch (Exception $e) {}
try {
    $certPrintPending = (int)$pdo->query("SELECT COUNT(*) FROM certificates WHERE status = 'Pending'")->fetchColumn();
} catch (Exception $e) {}
try {
    $todayDispatches = (int)$pdo->query("
        SELECT COUNT(*) FROM material_dispatches
        WHERE DATE(COALESCE(dispatch_date, created_at)) = CURDATE()
    ")->fetchColumn();
} catch (Exception $e) {}
try {
    $pendingDispatches = (int)$pdo->query("
        SELECT COUNT(*) FROM material_dispatches WHERE status = 'Dispatched'
    ")->fetchColumn();
} catch (Exception $e) {}
try {
    $courseMaterialPending = (int)$pdo->query("
        SELECT COUNT(*) FROM admissions
        WHERE material_type = 'With Material' AND status = 'Active'
          AND id NOT IN (SELECT DISTINCT admission_id FROM material_dispatch_students WHERE admission_id IS NOT NULL)
    ")->fetchColumn();
} catch (Exception $e) {
    try {
        $courseMaterialPending = (int)$pdo->query("
            SELECT COUNT(*) FROM admissions
            WHERE material_type = 'With Material' AND status = 'Active'
              AND id NOT IN (SELECT DISTINCT admission_id FROM material_dispatch_students)
        ")->fetchColumn();
    } catch (Exception $e2) {}
}

// ── Pending Exam breakdown: per-ATC list for the clickable modal ────────────
$pendingExamList = [];
try {
    // Fetch all Active admissions grouped by ATC center
    $peStmt = $pdo->query("
        SELECT a.id,
               CONCAT(a.first_name,' ',COALESCE(NULLIF(TRIM(a.middle_name),''),''),' ',a.last_name) AS name,
               a.registration_id, a.roll_no, a.course, a.photo,
               atc.name AS atc_name, atc.atc_code
        FROM admissions a
        JOIN atc_centers atc ON atc.id = a.atc_id
        WHERE a.status = 'Active'
        ORDER BY atc.name ASC, a.first_name ASC
        LIMIT 200
    ");
    $allStudents = $peStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get identifiers of students who have already passed OR attempted the MAIN exam
    $attemptedIds = [];
    if (function_exists('examIntegrationReady') && examIntegrationReady()) {
        $erRes = fetchAllExamResultsComplete();
        if ($erRes['success'] && isset($erRes['data']['submissions'])) {
            foreach ($erRes['data']['submissions'] as $sub) {
                if (function_exists('examSubmissionIsDemo') && examSubmissionIsDemo($sub)) {
                    continue;
                }
                $sid = $sub['student']['identifier'] ?? '';
                if ($sid) $attemptedIds[$sid] = true;
            }
        }
    }

    // Filter to only students who have NOT attempted the main exam
    foreach ($allStudents as $s) {
        $regId = $s['registration_id'] ?: ('GYANAM' . $s['id']);
        if (!isset($attemptedIds[$regId])) {
            $pendingExamList[] = $s;
        }
    }
    $pendingExam = count($pendingExamList); // override count with accurate number
} catch (Exception $e) {}

// ── L3: Reporting stats ───────────────────────────────────────────────────────
$reportedStudents = $pendingReporting = 0;
$reportedList = $pendingList = [];

try {
    // Reported = share has been paid (ho_share_paid = 1 or share_payment_date is set)
    $rStmt = $pdo->query("
        SELECT a.id, CONCAT(a.first_name,' ',a.last_name) AS name,
               a.course, a.roll_no, a.photo,
               atc.name AS atc_name
        FROM admissions a
        JOIN atc_centers atc ON atc.id = a.atc_id
        WHERE a.status = 'Active'
          AND a.ho_share_paid = 1
        ORDER BY a.first_name ASC
        LIMIT 100
    ");
    $reportedList     = $rStmt->fetchAll(PDO::FETCH_ASSOC);
    $reportedStudents = count($reportedList);
} catch (Exception $e) {
    // Try alternative column names
    try {
        $rStmt = $pdo->query("
            SELECT a.id, CONCAT(a.first_name,' ',a.last_name) AS name,
                   a.course, a.roll_no, a.photo,
                   atc.name AS atc_name
            FROM admissions a
            JOIN atc_centers atc ON atc.id = a.atc_id
            WHERE a.status = 'Active'
              AND EXISTS (
                  SELECT 1 FROM share_payments sp WHERE sp.admission_id = a.id
              )
            ORDER BY a.first_name ASC LIMIT 100
        ");
        $reportedList     = $rStmt->fetchAll(PDO::FETCH_ASSOC);
        $reportedStudents = count($reportedList);
    } catch (Exception $e2) {}
}

try {
    $pStmt = $pdo->query("
        SELECT a.id, CONCAT(a.first_name,' ',a.last_name) AS name,
               a.course, a.roll_no, a.photo,
               atc.name AS atc_name
        FROM admissions a
        JOIN atc_centers atc ON atc.id = a.atc_id
        WHERE a.status = 'Active'
          AND (a.ho_share_paid = 0 OR a.ho_share_paid IS NULL)
        ORDER BY atc.name ASC, a.first_name ASC
        LIMIT 100
    ");
    $pendingList      = $pStmt->fetchAll(PDO::FETCH_ASSOC);
    $pendingReporting = count($pendingList);
} catch (Exception $e) {
    try {
        $pStmt = $pdo->query("
            SELECT a.id, CONCAT(a.first_name,' ',a.last_name) AS name,
                   a.course, a.roll_no, a.photo,
                   atc.name AS atc_name
            FROM admissions a
            JOIN atc_centers atc ON atc.id = a.atc_id
            WHERE a.status = 'Active'
              AND NOT EXISTS (
                  SELECT 1 FROM share_payments sp WHERE sp.admission_id = a.id
              )
            ORDER BY atc.name ASC, a.first_name ASC LIMIT 100
        ");
        $pendingList      = $pStmt->fetchAll(PDO::FETCH_ASSOC);
        $pendingReporting = count($pendingList);
    } catch (Exception $e2) {}
}

// ── L2: Admissions + Inquiries detail lists ───────────────────────────────────
$admissionsList  = $inquiriesList = [];
try {
    $s = $pdo->query("
        SELECT a.id, CONCAT(a.first_name,' ',a.last_name) AS name,
               a.roll_no, a.course, a.photo, atc.name AS atc_name, a.admission_date
        FROM admissions a
        JOIN atc_centers atc ON atc.id = a.atc_id
        ORDER BY a.admission_date DESC LIMIT 200
    ");
    $admissionsList = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

try {
    $s = $pdo->query("
        SELECT i.id, CONCAT(i.first_name,' ',i.last_name) AS name,
               i.interested_course AS course, i.mobile,
               atc.name AS atc_name, i.created_at, i.status
        FROM inquiries i
        JOIN atc_centers atc ON atc.id = i.atc_id
        ORDER BY i.created_at DESC LIMIT 200
    ");
    $inquiriesList = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Birthdays ─────────────────────────────────────────────────────────────────
$calendarBirthdays = []; // [{name,type,mobile,month,day,dob}]
try {
    $aStmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(contact_person),''), name) AS name,
               dob, 'ATC Center' AS type, IFNULL(district,'') AS location,
               IFNULL(mobile,'') AS mobile,
               MONTH(dob) AS b_month, DAY(dob) AS b_day
        FROM atc_centers
        WHERE dob IS NOT NULL AND status = 'Active'
        ORDER BY name ASC
    ");
    $aStmt->execute();
    foreach ($aStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $calendarBirthdays[] = $row;
    }

    $dStmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(contact_person),''), name) AS name,
               dob, 'DLC Office' AS type, IFNULL(district,'') AS location,
               IFNULL(mobile,'') AS mobile,
               MONTH(dob) AS b_month, DAY(dob) AS b_day
        FROM dlc_offices
        WHERE dob IS NOT NULL AND status = 'Active'
        ORDER BY name ASC
    ");
    $dStmt->execute();
    foreach ($dStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $calendarBirthdays[] = $row;
    }

    $mStmt = $pdo->prepare("
        SELECT person_name AS name,
               birth_date AS dob, 'Manual Entry' AS type, IFNULL(description,'') AS location,
               IFNULL(mobile,'') AS mobile,
               MONTH(birth_date) AS b_month, DAY(birth_date) AS b_day
        FROM birthdays
        WHERE birth_date IS NOT NULL AND status = 'Active'
        ORDER BY name ASC
    ");
    $mStmt->execute();
    foreach ($mStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $calendarBirthdays[] = $row;
    }
} catch (Exception $e) {}

// Optional fixed holidays (month-day) for calendar legend markers
$calendarHolidays = [
    '1-26' => 'Republic Day',
    '8-15' => 'Independence Day',
    '10-2' => 'Gandhi Jayanti',
    '1-1'  => 'New Year',
    '5-1'  => 'Labour Day',
];

// ── Expiring ATCs ─────────────────────────────────────────────────────────────
try {
    $expiringATCs = $pdo->query("
        SELECT id, name, district, state, authorization_expires_at,
               DATEDIFF(authorization_expires_at, CURDATE()) AS days_left
        FROM atc_centers
        WHERE authorization_expires_at IS NOT NULL
          AND authorization_expires_at <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
          AND status = 'Active'
        ORDER BY authorization_expires_at ASC LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// ── Report stats — HO share revenue only (not ATC student fee collections) ──
$revenueStats    = [
    'total_revenue' => 0,
    'total_collected' => 0,
    'total_pending' => 0,
    'today_share' => 0,
    'txn_count' => 0,
    'atc_paid_count' => 0,
];
$dlcRevenue      = [];
$dispatchStats   = ['total_dispatches'=>0,'created'=>0,'sent_to_dlc'=>0,'forwarded_to_atc'=>0,'delivered'=>0,'total_items'=>0];
$materialBreakdown = [];
$topATCs         = [];
$monthlyTrend    = [];
$recentSharePayments = [];

$_rcKey = 'admin_dash_reports_share_v1';
$_rcAt  = 'admin_dash_reports_share_at';
if (isset($_SESSION[$_rcKey], $_SESSION[$_rcAt]) && (time() - (int)$_SESSION[$_rcAt]) < 90) {
    $cached = $_SESSION[$_rcKey];
    $revenueStats      = array_merge($revenueStats, $cached['revenueStats'] ?? []);
    $dlcRevenue        = $cached['dlcRevenue'] ?? [];
    $dispatchStats     = $cached['dispatchStats'] ?? $dispatchStats;
    $materialBreakdown = $cached['materialBreakdown'] ?? [];
    $topATCs           = $cached['topATCs'] ?? [];
    $monthlyTrend      = $cached['monthlyTrend'] ?? [];
    $recentSharePayments = $cached['recentSharePayments'] ?? [];
} else {
    try {
        $row = $pdo->query("
            SELECT
                COALESCE(SUM(CASE WHEN status = 'Completed' THEN total_share_amount ELSE 0 END), 0) AS total_collected,
                COALESCE(SUM(CASE WHEN status = 'Pending' THEN COALESCE(total_amount, total_share_amount, 0) ELSE 0 END), 0) AS total_pending,
                COALESCE(SUM(CASE WHEN status = 'Completed' AND DATE(created_at) = CURDATE() THEN total_share_amount ELSE 0 END), 0) AS today_share,
                COUNT(*) AS txn_count,
                COUNT(DISTINCT CASE WHEN status = 'Completed' THEN atc_id END) AS atc_paid_count
            FROM share_payments
        ")->fetch(PDO::FETCH_ASSOC) ?: [];
        $revenueStats['total_collected'] = (float)($row['total_collected'] ?? 0);
        $revenueStats['total_pending']   = (float)($row['total_pending'] ?? 0);
        $revenueStats['today_share']     = (float)($row['today_share'] ?? 0);
        $revenueStats['txn_count']       = (int)($row['txn_count'] ?? 0);
        $revenueStats['atc_paid_count']  = (int)($row['atc_paid_count'] ?? 0);
        // Admin "revenue" = share received (HO income), not ATC fee earnings
        $revenueStats['total_revenue']   = $revenueStats['total_collected'];
    } catch (Exception $e) {}

    try {
        $dlcRevenue = $pdo->query("
            SELECT dlc.id, dlc.name AS dlc_name, dlc.district,
                   COUNT(DISTINCT atc.id) AS atc_count,
                   (SELECT COUNT(*) FROM admissions adm
                      JOIN atc_centers a2 ON adm.atc_id = a2.id
                     WHERE a2.dlc_id = dlc.id AND adm.status = 'Active') AS total_students,
                   COALESCE(SUM(CASE WHEN sp.status = 'Completed' THEN sp.total_share_amount ELSE 0 END), 0) AS collected,
                   COALESCE(SUM(CASE WHEN sp.status = 'Pending' THEN COALESCE(sp.total_amount, sp.total_share_amount, 0) ELSE 0 END), 0) AS pending
            FROM dlc_offices dlc
            LEFT JOIN atc_centers atc ON dlc.id = atc.dlc_id AND atc.status = 'Active'
            LEFT JOIN share_payments sp ON atc.id = sp.atc_id
            GROUP BY dlc.id
            ORDER BY collected DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($dlcRevenue as &$dlcRow) {
            $dlcRow['total_revenue'] = (float)$dlcRow['collected'] + (float)$dlcRow['pending'];
        }
        unset($dlcRow);
    } catch (Exception $e) {}

    try {
        $dispatchStats = $pdo->query("
            SELECT COUNT(*) AS total_dispatches,
                   SUM(status='Created')          AS created,
                   SUM(status='Sent to DLC')      AS sent_to_dlc,
                   SUM(status='Forwarded to ATC') AS forwarded_to_atc,
                   SUM(status='Delivered')        AS delivered,
                   SUM(quantity)                  AS total_items
            FROM dispatches
        ")->fetch(PDO::FETCH_ASSOC) ?: $dispatchStats;
    } catch (Exception $e) {}

    try {
        $materialBreakdown = $pdo->query("
            SELECT material_type, COUNT(*) AS dispatch_count, SUM(quantity) AS total_quantity
            FROM dispatches GROUP BY material_type ORDER BY total_quantity DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    try {
        $topATCs = fetchTopPerformingAtcs($pdo, '', 'all', 10);
    } catch (Exception $e) {}

    // Monthly: admissions count + HO share revenue (not ATC fee collections)
    try {
        $mapAdm = [];
        $mapRev = [];
        foreach ($pdo->query("
            SELECT DATE_FORMAT(admission_date,'%Y-%m') AS month, COUNT(*) AS admissions
            FROM admissions
            WHERE admission_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
            GROUP BY DATE_FORMAT(admission_date,'%Y-%m')
        ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mapAdm[$r['month']] = (int)$r['admissions'];
        }
        foreach ($pdo->query("
            SELECT DATE_FORMAT(created_at,'%Y-%m') AS month,
                   COALESCE(SUM(CASE WHEN status='Completed' THEN total_share_amount ELSE 0 END),0) AS revenue
            FROM share_payments
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
            GROUP BY DATE_FORMAT(created_at,'%Y-%m')
        ")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mapRev[$r['month']] = (float)$r['revenue'];
        }
        $monthlyTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $sk = date('Y-m', strtotime("-{$i} months"));
            $monthlyTrend[] = [
                'month' => $sk,
                'admissions' => $mapAdm[$sk] ?? 0,
                'revenue' => $mapRev[$sk] ?? 0,
                'collected' => $mapRev[$sk] ?? 0,
            ];
        }
    } catch (Exception $e) {}

    try {
        $recentSharePayments = $pdo->query("
            SELECT sp.id, sp.total_share_amount, sp.status, sp.created_at, sp.payment_mode,
                   atc.name AS atc_name
            FROM share_payments sp
            JOIN atc_centers atc ON atc.id = sp.atc_id
            ORDER BY sp.created_at DESC
            LIMIT 6
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}

    $_SESSION[$_rcKey] = compact('revenueStats', 'dlcRevenue', 'dispatchStats', 'materialBreakdown', 'topATCs', 'monthlyTrend', 'recentSharePayments');
    $_SESSION[$_rcAt]  = time();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Head Office | Gyanam India</title>
    <?php include __DIR__ . '/../includes/head_fonts.php'; ?>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="stylesheet" href="../assets/css/atc-dash-cc.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📚</text></svg>">
    <?php require_once __DIR__ . '/../atc/_dash_cc_icons.php'; ?>
    <style>
    :root {
        --border: var(--border-color);
        --text: var(--text-primary);
        --text-3: var(--text-muted);
        --surface-2: var(--gray-50);
        --surface-3: var(--gray-100);
        --brand: var(--primary-600);
    }

    .stat-card.sky { --accent: #0ea5e9; }
    .stat-card.sky::after { background: var(--accent); }
    .stat-card.clickable { cursor: pointer; }
    .stat-card.clickable:hover { transform: translateY(-4px) !important; box-shadow: 0 14px 36px rgba(0,0,0,.12) !important; }

    /* Birthday Panel */
    .bday-panel { background:#fff; border:1px solid var(--border); border-radius:16px; overflow:hidden; box-shadow:0 2px 12px rgba(0,0,0,.05); margin-bottom:1.5rem; }
    .bday-panel-header { display:flex; align-items:center; justify-content:space-between; padding:1rem 1.5rem; border-bottom:1px solid var(--border); background:var(--surface-2); }
    .bday-panel-title { display:flex; align-items:center; gap:.5rem; font-weight:700; font-size:.95rem; color:var(--text); }
    .bday-panel-title svg { width:18px; height:18px; color:var(--brand); }
    .bday-date { font-size:.8rem; color:var(--text-3); }
    .bday-panel-body { padding:.5rem 0; max-height:320px; overflow-y:auto; }
    .bday-empty { display:flex; flex-direction:column; align-items:center; gap:.5rem; padding:2rem; color:var(--text-3); }
    .bday-empty svg { width:40px; height:40px; opacity:.4; }
    .bday-empty p { font-size:.9rem; }
    .bday-row { display:flex; align-items:center; gap:1rem; padding:.75rem 1.5rem; border-bottom:1px solid var(--border); transition:background .15s; }
    .bday-row:last-child { border-bottom:none; }
    .bday-row:hover { background:var(--surface-2); }
    .bday-avatar { width:38px; height:38px; border-radius:50%; flex-shrink:0; background:linear-gradient(135deg,#6366f1,#8b5cf6); color:#fff; display:flex; align-items:center; justify-content:center; font-size:1rem; font-weight:700; }
    .bday-name { font-weight:600; font-size:.875rem; color:var(--text); }
    .bday-tag { display:inline-block; margin-top:.2rem; font-size:.72rem; font-weight:600; background:var(--surface-3); color:var(--text-3); padding:.1rem .5rem; border-radius:99px; }
    .bday-wish { margin-left: auto; font-size: 1.3rem; }

    /* Calendar + month birthdays — compact */
    .cal-bday-wrap {
        display: grid;
        grid-template-columns: 1.15fr .85fr;
        gap: .75rem;
        margin: 1rem 0;
        align-items: start;
    }
    .cal-card, .cal-bday-side {
        background: #fff;
        border: 1px solid var(--border);
        border-radius: 12px;
        box-shadow: 0 1px 6px rgba(0,0,0,.04);
        overflow: hidden;
    }
    .cal-card { padding: .7rem .8rem .65rem; }
    .cal-nav {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: .45rem;
    }
    .cal-nav h3 {
        margin: 0;
        font-size: .88rem;
        font-weight: 800;
        color: var(--text);
        letter-spacing: -.01em;
    }
    .cal-nav-btn {
        width: 26px; height: 26px;
        border-radius: 50%;
        border: 1px solid #e5e7eb;
        background: #fff;
        color: #64748b;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background .15s, color .15s, border-color .15s;
    }
    .cal-nav-btn:hover { background: #eef2ff; color: #4f46e5; border-color: #c7d2fe; }
    .cal-nav-btn svg { width: 13px; height: 13px; }
    .cal-weekdays, .cal-days {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 2px;
    }
    .cal-weekdays span {
        text-align: center;
        font-size: .62rem;
        font-weight: 700;
        color: #94a3b8;
        padding: .15rem 0;
        text-transform: uppercase;
    }
    .cal-day {
        position: relative;
        height: 28px;
        min-height: 0;
        aspect-ratio: auto;
        border-radius: 7px;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        font-size: .74rem;
        font-weight: 700;
        color: #334155;
        cursor: default;
        border: 1px solid transparent;
        transition: background .15s;
        line-height: 1;
    }
    .cal-day.empty { visibility: hidden; }
    .cal-day.has-bday { cursor: pointer; }
    .cal-day.has-bday:hover { background: #fdf2f8; }
    .cal-day.is-today {
        background: #6366f1;
        color: #fff;
        box-shadow: 0 2px 8px rgba(99, 102, 241, .3);
    }
    .cal-day.is-holiday:not(.is-today) {
        border-color: #c4b5fd;
        background: #f5f3ff;
        color: #5b21b6;
    }
    .cal-day.is-selected:not(.is-today) {
        outline: 1.5px solid #6366f1;
        outline-offset: 0;
    }
    .cal-dot {
        width: 4px; height: 4px;
        border-radius: 50%;
        background: #ec4899;
        margin-top: 1px;
        position: absolute;
        bottom: 3px;
    }
    .cal-day.is-today .cal-dot { background: #fda4af; }
    .cal-legend {
        display: flex;
        flex-wrap: wrap;
        gap: .4rem .75rem;
        margin-top: .5rem;
        padding-top: .45rem;
        border-top: 1px solid #f1f5f9;
        font-size: .65rem;
        font-weight: 700;
        color: #64748b;
    }
    .cal-legend span { display: inline-flex; align-items: center; gap: .3rem; }
    .lg-holiday {
        width: 9px; height: 9px; border-radius: 2px;
        border: 1.5px solid #c4b5fd; background: #f5f3ff;
    }
    .lg-today {
        width: 9px; height: 9px; border-radius: 2px; background: #6366f1;
    }
    .lg-bday {
        width: 6px; height: 6px; border-radius: 50%; background: #ec4899;
    }
    .cal-bday-side-head {
        display: flex;
        align-items: center;
        gap: .4rem;
        padding: .65rem .8rem;
        border-bottom: 1px solid #f1f5f9;
        font-weight: 800;
        font-size: .82rem;
        color: var(--text);
    }
    .cal-bday-side-head .ico {
        width: 22px; height: 22px; border-radius: 50%;
        background: #fce7f3; color: #db2777;
        display: inline-flex; align-items: center; justify-content: center;
        flex-shrink: 0;
    }
    .cal-bday-side-head .ico svg { width: 12px; height: 12px; }
    .cal-bday-side-body {
        padding: .25rem 0;
        max-height: 210px;
        overflow-y: auto;
        min-height: 0;
    }
    .cal-bday-side .bday-row {
        padding: .45rem .8rem;
        gap: .55rem;
    }
    .cal-bday-side .bday-avatar {
        width: 28px !important;
        height: 28px !important;
        font-size: .72rem !important;
    }
    .cal-bday-side .bday-name { font-size: .8rem; }
    .cal-bday-side .bday-tag { font-size: .65rem; }
    .cal-bday-empty {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: .3rem;
        padding: 1.25rem .75rem;
        color: #94a3b8;
        font-weight: 600;
        font-size: .78rem;
        text-align: center;
        min-height: 120px;
    }
    .cal-bday-empty .cake { font-size: 1.15rem; }
    @media (max-width: 900px) {
        .cal-bday-wrap { grid-template-columns: 1fr; }
        .cal-bday-side-body { max-height: 160px; }
    }

    /* Detail modal */
    .detail-modal-list { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:1rem; padding:1.25rem 1.5rem; max-height:60vh; overflow-y:auto; }
    .detail-card { background:var(--gray-50,#f9fafb); border:1.5px solid var(--border-color,#e5e7eb); border-radius:14px; padding:1rem; display:flex; flex-direction:column; align-items:center; gap:.5rem; text-align:center; }
    .detail-avatar { width:56px; height:56px; border-radius:50%; background:linear-gradient(135deg,#4361ee,#8b5cf6); display:flex; align-items:center; justify-content:center; font-size:1.2rem; font-weight:800; color:#fff; }
    .detail-photo { width:56px; height:56px; border-radius:50%; object-fit:cover; border:2px solid var(--border-color,#e5e7eb); }
    .detail-name { font-size:.82rem; font-weight:800; color:var(--text-primary,#1f2937); line-height:1.3; }
    .detail-meta { font-size:.72rem; color:var(--text-muted,#9ca3af); }
    .detail-atc  { font-size:.7rem; font-weight:700; color:#4361ee; background:#eef2ff; padding:.15rem .5rem; border-radius:999px; }

    /* Pending grouped by ATC */
    .pending-list { padding:.75rem 1.5rem; max-height:60vh; overflow-y:auto; }
    .pending-atc-group { margin-bottom:1rem; }
    .pending-atc-header { font-size:.78rem; font-weight:800; color:#374151; background:#f3f4f6; padding:.5rem .75rem; border-radius:8px; margin-bottom:.4rem; display:flex; justify-content:space-between; }
    .pending-atc-count { background:#fbbf24; color:#78350f; font-size:.68rem; font-weight:800; padding:.15rem .5rem; border-radius:999px; }
    .pending-student-row { display:flex; align-items:center; gap:.6rem; padding:.4rem .5rem; border-bottom:1px solid #f3f4f6; font-size:.8rem; }
    .pending-student-row:last-child { border-bottom:none; }

    /* Dashboard detail modal */
    .modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 42, 0.45);
        backdrop-filter: blur(6px);
        -webkit-backdrop-filter: blur(6px);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 1200;
        padding: 1rem;
    }
    .modal-overlay.active { display: flex; }
    .modal-card {
        width: 100%;
        max-height: 92vh;
        overflow: hidden;
        border-radius: 16px;
        background: var(--bg-surface);
        border: 1px solid var(--border-color);
        box-shadow: var(--shadow-2xl);
        display: flex;
        flex-direction: column;
    }
    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: .95rem 1.2rem;
        border-bottom: 1px solid var(--border-color);
    }
    .modal-header h3 {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: .45rem;
        margin: 0;
        font-size: .95rem;
        font-weight: 800;
    }
    .modal-header.gradient {
        background: linear-gradient(135deg, var(--primary-600), var(--accent-600));
        border-bottom: 0;
        color: #fff;
    }
    .modal-close {
        width: 34px;
        height: 34px;
        border: 0;
        border-radius: 10px;
        background: rgba(255,255,255,.22);
        color: inherit;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
    .modal-close svg { width: 18px; height: 18px; }
    .modal-footer {
        padding: .8rem 1.2rem;
        border-top: 1px solid var(--border-color);
        background: var(--gray-50);
    }
    .btn-secondary {
        border: 1px solid var(--border-color);
        background: #fff;
        color: var(--text-primary);
        border-radius: 10px;
        padding: .5rem .9rem;
        font-size: .82rem;
        font-weight: 700;
        cursor: pointer;
    }

    @media (max-width: 768px) {
        .bday-row { flex-wrap: wrap; align-items: flex-start; }
        .bday-info { min-width: 0; }
        .bday-name { word-break: break-word; }
        .bday-wish { margin-left: 0; }
        .modal-header h3 { font-size: .88rem; }
    }

    /* ── Report sections embedded in dashboard ── */
    .rpt-section { margin: 1.75rem 0 1rem; padding-bottom: .5rem; border-bottom: 1.5px solid var(--border-color,#e5e7eb); display:flex; align-items:center; gap:.5rem; font-size:.78rem; font-weight:800; letter-spacing:.08em; text-transform:uppercase; color:var(--text-muted,#9ca3af); }
    .rpt-section svg { width:16px; height:16px; stroke:currentColor; fill:none; }

    .rev-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:1rem; margin-bottom:1.25rem; }
    .rev-card { background:#fff; border:1.5px solid var(--border-color,#e5e7eb); border-radius:16px; padding:1.5rem 1.75rem; display:flex; align-items:center; gap:1.25rem; box-shadow:0 2px 10px rgba(0,0,0,.05); }
    .rev-icon { width:56px; height:56px; border-radius:14px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .rev-icon svg { width:24px; height:24px; stroke:#fff; fill:none; }
    .rev-label { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:var(--text-muted,#9ca3af); }
    .rev-value { font-size:1.65rem; font-weight:900; color:var(--text-primary,#111); letter-spacing:-.04em; margin:.25rem 0 .15rem; }
    .rev-sub   { font-size:.75rem; color:var(--text-muted,#9ca3af); }

    .dsp-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:.85rem; margin-bottom:1.25rem; }
    .dsp-card { background:#fff; border:1.5px solid var(--border-color,#e5e7eb); border-radius:14px; padding:1.1rem 1.25rem; text-align:center; box-shadow:0 2px 8px rgba(0,0,0,.04); }
    .dsp-val { font-size:1.5rem; font-weight:900; color:var(--text-primary,#111); }
    .dsp-lbl { font-size:.68rem; font-weight:700; color:var(--text-muted,#9ca3af); text-transform:uppercase; letter-spacing:.07em; margin-top:.25rem; }

    .rpt-table-wrap { background:#fff; border:1.5px solid var(--border-color,#e5e7eb); border-radius:14px; overflow:hidden; margin-bottom:1.25rem; }
    .rpt-table { width:100%; border-collapse:collapse; font-size:.84rem; }
    .rpt-table thead { background:#f9fafb; }
    .rpt-table th { padding:.7rem 1rem; text-align:left; font-size:.68rem; font-weight:800; color:var(--text-muted,#9ca3af); text-transform:uppercase; letter-spacing:.07em; border-bottom:1px solid var(--border-color,#e5e7eb); white-space:nowrap; }
    .rpt-table tbody tr { border-bottom:1px solid #f3f4f6; transition:background .12s; }
    .rpt-table tbody tr:last-child { border-bottom:none; }
    .rpt-table tbody tr:hover { background:#f8faff; }
    .rpt-table td { padding:.8rem 1rem; vertical-align:middle; }
    .prog-bar { height:5px; background:#e5e7eb; border-radius:999px; margin-top:.3rem; overflow:hidden; }
    .prog-fill { height:100%; border-radius:999px; }

    .rank-badge { display:inline-flex; align-items:center; gap:.3rem; padding:.35rem .65rem; border-radius:8px; font-weight:800; font-size:.8rem; }
    .rank-1 { background:linear-gradient(135deg,#fbbf24,#f59e0b); color:#fff; }
    .rank-2 { background:linear-gradient(135deg,#94a3b8,#64748b); color:#fff; }
    .rank-3 { background:linear-gradient(135deg,#fb923c,#f97316); color:#fff; }
    .rank-other { background:#f3f4f6; color:#374151; }

    .chart-wrap { display:flex; align-items:flex-end; gap:.75rem; height:180px; padding:.5rem 0; overflow-x:auto; }
    .chart-col { display:flex; flex-direction:column; align-items:center; gap:.35rem; flex:1; min-width:60px; }
    .chart-bar-box { width:100%; display:flex; align-items:flex-end; justify-content:center; height:140px; }
    .chart-bar { width:65%; border-radius:6px 6px 0 0; background:linear-gradient(180deg,#6366f1,#4361ee); min-height:6px; transition:height .3s; }
    .chart-mlbl { font-size:.72rem; font-weight:700; color:var(--text-primary,#111); }
    .chart-msub { font-size:.67rem; color:var(--text-muted,#9ca3af); }

    /* Top ATC filters */
    .top-atc-head {
        display:flex; align-items:flex-end; justify-content:space-between; gap:1rem;
        flex-wrap:wrap; margin-bottom:.85rem;
    }
    .top-atc-filters { display:flex; flex-wrap:wrap; gap:.55rem; align-items:center; }
    .top-atc-filters label {
        font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em;
        color:var(--text-muted,#9ca3af); display:block; margin-bottom:.28rem;
    }
    .top-atc-filters select {
        height:38px; padding:0 .8rem; border:1.5px solid var(--border-color,#e5e7eb);
        border-radius:10px; font-family:inherit; font-size:.82rem; font-weight:600;
        background:#fff; color:#1f2937; min-width:140px;
    }
    .period-pills { display:flex; flex-wrap:wrap; gap:.35rem; }
    .period-pill {
        height:38px; padding:0 .9rem; border-radius:999px; border:1.5px solid #e5e7eb;
        background:#fff; color:#475569; font-size:.78rem; font-weight:700; cursor:pointer;
        font-family:inherit; transition:all .15s;
    }
    .period-pill.active {
        background:#4361ee; border-color:#4361ee; color:#fff;
        box-shadow:0 3px 10px rgba(67,97,238,.25);
    }
    .ctype-chip {
        display:inline-flex; padding:.15rem .5rem; border-radius:999px; font-size:.68rem;
        font-weight:800; background:#eef2ff; color:#3730a3; border:1px solid #c7d2fe;
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
                    <h2><?= $greeting ?>, <?= $userName ?>!</h2>
                    <p><?= date('l, d F Y') ?></p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content cc-dash">

            <!-- ═══ OVERVIEW CARDS (top) ═══ -->
            <div class="stats-grid">
                <!-- L1: Renamed "Total Users" → "Total Logins" -->
                <div class="stat-card purple">
                    <div class="stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Total Logins</div>
                        <div class="stat-value" data-count="<?= $totalUsers ?>">0</div>
                    </div>
                </div>
                <div class="stat-card blue">
                    <div class="stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">DLC Logins</div>
                        <div class="stat-value" data-count="<?= $totalDLC ?>">0</div>
                    </div>
                </div>
                <!-- L1: Renamed "ATC Centers" → "ATC Logins" -->
                <div class="stat-card green">
                    <div class="stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">ATC Logins</div>
                        <div class="stat-value" data-count="<?= $totalATC ?>">0</div>
                    </div>
                </div>
                <!-- L2: Clickable Inquiries -->
                <div class="stat-card amber clickable" onclick="openDetailModal('inquiries')" title="Click to view all inquiries">
                    <div class="stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Inquiries</div>
                        <div class="stat-value" data-count="<?= $totalInquiries ?>">0</div>
                    </div>
                </div>
                <!-- L2: Clickable Admissions -->
                <div class="stat-card rose clickable" onclick="openDetailModal('admissions')" title="Click to view all admissions">
                    <div class="stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Admissions</div>
                        <div class="stat-value" data-count="<?= $totalAdmissions ?>">0</div>
                    </div>
                </div>
                <div class="stat-card sky clickable" onclick="openDetailModal('pending_exam')" title="Click to view pending exam students by ATC">
                    <div class="stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Pending Exam</div>
                        <div class="stat-value" data-count="<?= $pendingExam ?>">0</div>
                    </div>
                </div>
            </div>

            <!-- ═══ OPS CARDS: certificates / dispatches / materials ═══ -->
            <div class="cc-grid cc-grid-4" style="margin-top:1rem">
                <a class="cc-card cc-card-pad" href="print_certificates.php" style="text-decoration:none;color:inherit">
                    <div class="cc-card-head">
                        <?= cc_png('icon-certified.png', 'xl', 'Certified') ?>
                        <h3>Certified Students</h3>
                    </div>
                    <div class="cc-metric-value green"><?= (int)$certifiedStudents ?></div>
                    <div class="cc-metric-label">Certificates issued</div>
                </a>
                <a class="cc-card cc-card-pad" href="dispatches.php?date=today" style="text-decoration:none;color:inherit">
                    <div class="cc-card-head">
                        <?= cc_png('icon-dispatch.png', 'xl', 'Dispatches') ?>
                        <h3>Today's Dispatches</h3>
                    </div>
                    <div class="cc-metric-value blue"><?= (int)$todayDispatches ?></div>
                    <div class="cc-metric-label">Created / sent today</div>
                </a>
                <a class="cc-card cc-card-pad" href="dispatches.php?status=Dispatched" style="text-decoration:none;color:inherit">
                    <div class="cc-card-head">
                        <?= cc_png('icon-pending.png', 'xl', 'Pending') ?>
                        <h3>Pending Dispatches</h3>
                    </div>
                    <div class="cc-metric-value orange"><?= (int)$pendingDispatches ?></div>
                    <div class="cc-metric-label">In transit (not delivered)</div>
                </a>
                <a class="cc-card cc-card-pad" href="print_certificates.php?filter=print_pending" style="text-decoration:none;color:inherit">
                    <div class="cc-card-head">
                        <?= cc_png('icon-print.png', 'xl', 'Print certificates') ?>
                        <h3>Certificate Printing Pending</h3>
                    </div>
                    <div class="cc-metric-value orange"><?= (int)$certPrintPending ?></div>
                    <div class="cc-metric-label">Awaiting print / issue</div>
                </a>
            </div>
            <div class="cc-grid cc-grid-4">
                <a class="cc-card cc-card-pad" href="dispatches.php?view=pending" style="text-decoration:none;color:inherit">
                    <div class="cc-card-head">
                        <?= cc_png('icon-books.png', 'xl', 'Course material') ?>
                        <h3>Course Material Pending</h3>
                    </div>
                    <div class="cc-metric-value red"><?= (int)$courseMaterialPending ?></div>
                    <div class="cc-metric-label">With-material students awaiting dispatch</div>
                </a>
                <a class="cc-card cc-card-pad" href="material_requirements.php?tab=pending" style="text-decoration:none;color:inherit">
                    <div class="cc-card-head">
                        <?= cc_png('icon-material.png', 'xl', 'Material requirements') ?>
                        <h3>Material Requirements</h3>
                    </div>
                    <div class="cc-kv"><span class="k">Open pending tab</span><span class="v orange">View</span></div>
                    <div class="cc-metric-label">ATC-wise material needs</div>
                </a>
            </div>

            <!-- ═══ ClassChakra-style HO Share cards (admin revenue = share only) ═══ -->
            <div class="cc-grid cc-grid-4">
                <div class="cc-card cc-card-pad">
                    <div class="cc-card-head">
                        <?= cc_png('icon-share-revenue.png', 'xl', 'Share revenue') ?>
                        <h3>Share Revenue</h3>
                        <a class="cc-link" href="share_payments.php">Show All</a>
                    </div>
                    <div class="cc-metric-label">HO income from ATC share payments</div>
                    <div class="cc-metric-value green">₹ <?= number_format((float)$revenueStats['total_collected'], 0) ?></div>
                </div>
                <div class="cc-card cc-card-pad">
                    <div class="cc-card-head">
                        <?= cc_png('icon-pending.png', 'xl', 'Pending share') ?>
                        <h3>Pending Share</h3>
                    </div>
                    <div class="cc-metric-label">Awaiting completion</div>
                    <div class="cc-metric-value orange">₹ <?= number_format((float)$revenueStats['total_pending'], 0) ?></div>
                </div>
                <div class="cc-card cc-card-pad">
                    <div class="cc-card-head">
                        <?= cc_png('icon-today-share.png', 'xl', "Today's share") ?>
                        <h3>Today's Share</h3>
                    </div>
                    <div class="cc-metric-label">Completed today</div>
                    <div class="cc-metric-value blue">₹ <?= number_format((float)($revenueStats['today_share'] ?? 0), 0) ?></div>
                </div>
                <div class="cc-card cc-card-pad">
                    <div class="cc-card-head">
                        <?= cc_png('icon-staff.png', 'xl', 'Share summary') ?>
                        <h3>Share Summary</h3>
                    </div>
                    <div class="cc-kv"><span class="k">Transactions</span><span class="v"><?= (int)($revenueStats['txn_count'] ?? 0) ?></span></div>
                    <div class="cc-kv"><span class="k">ATCs paid</span><span class="v green"><?= (int)($revenueStats['atc_paid_count'] ?? 0) ?></span></div>
                    <div class="cc-kv"><span class="k">ATC Logins</span><span class="v"><?= (int)$totalATC ?></span></div>
                </div>
            </div>

            <div class="cc-grid cc-grid-4">
                <div class="cc-card cc-card-pad">
                    <div class="cc-card-head">
                        <?= cc_png('icon-staff.png', 'xl', 'Network') ?>
                        <h3>Network</h3>
                    </div>
                    <div class="cc-kv"><span class="k">DLC Offices</span><span class="v"><?= (int)$totalDLC ?></span></div>
                    <div class="cc-kv"><span class="k">ATC Centers</span><span class="v blue"><?= (int)$totalATC ?></span></div>
                    <div class="cc-kv"><span class="k">Total Logins</span><span class="v"><?= (int)$totalUsers ?></span></div>
                </div>
                <div class="cc-card cc-card-pad" onclick="openDetailModal('admissions')" style="cursor:pointer" title="View admissions">
                    <div class="cc-card-head">
                        <?= cc_png('icon-admissions.png', 'xl', 'Admissions') ?>
                        <h3>Admissions</h3>
                    </div>
                    <div class="cc-metric-value blue"><?= (int)$totalAdmissions ?></div>
                    <div class="cc-kv"><span class="k">Inquiries</span><span class="v"><?= (int)$totalInquiries ?></span></div>
                </div>
                <div class="cc-card cc-card-pad" onclick="openDetailModal('reported')" style="cursor:pointer" title="Reported students">
                    <div class="cc-card-head">
                        <?= cc_png('icon-reported.png', 'xl', 'Reported') ?>
                        <h3>Reported Students</h3>
                    </div>
                    <div class="cc-metric-value green"><?= (int)$reportedStudents ?></div>
                    <div class="cc-kv"><span class="k">Share paid to HO</span><span class="v green">Yes</span></div>
                </div>
                <div class="cc-card cc-card-pad" onclick="openDetailModal('pending_report')" style="cursor:pointer" title="Pending reports">
                    <div class="cc-card-head">
                        <?= cc_png('icon-pending.png', 'xl', 'Pending reports') ?>
                        <h3>Pending Reports</h3>
                    </div>
                    <div class="cc-metric-value orange"><?= (int)$pendingReporting ?></div>
                    <div class="cc-kv"><span class="k">Share not yet paid</span><span class="v orange">Open</span></div>
                </div>
            </div>

            <?php if (!empty($recentSharePayments)): ?>
            <div class="cc-card" style="margin-bottom:1rem">
                <div class="cc-card-pad" style="padding-bottom:.35rem">
                    <div class="cc-card-head" style="margin-bottom:.35rem">
                        <?= cc_ico('card', 'xl') ?>
                        <h3>Recent Share Payments</h3>
                        <a class="cc-link" href="share_payments.php">Show All</a>
                    </div>
                </div>
                <table class="cc-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>ATC</th>
                            <th>Amount</th>
                            <th>Mode</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentSharePayments as $sp): ?>
                        <tr>
                            <td class="muted"><?= !empty($sp['created_at']) ? date('d M Y', strtotime($sp['created_at'])) : '—' ?></td>
                            <td class="name"><?= htmlspecialchars($sp['atc_name'] ?? '—') ?></td>
                            <td class="name" style="color:var(--cc-green)">₹ <?= number_format((float)$sp['total_share_amount'], 0) ?></td>
                            <td><?= htmlspecialchars($sp['payment_mode'] ?? '—') ?></td>
                            <td>
                                <?php $st = $sp['status'] ?? ''; ?>
                                <span class="v <?= $st === 'Completed' ? 'green' : 'orange' ?>" style="font-weight:800"><?= htmlspecialchars($st) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- ═══ L3: GYANAM HEAD OFFICE REPORTING CARDS ═══ -->
            <div style="font-size:.68rem;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--text-muted,#9ca3af);margin:1.5rem 0 .75rem;padding-bottom:.5rem;border-bottom:1px solid var(--border-color,#e5e7eb)">
                Gyanam Head Office — Reporting
            </div>
            <div class="stats-grid" style="grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
                <div class="stat-card green clickable" onclick="openDetailModal('reported')" title="Click to view reported students">
                    <div class="stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Reported Students</div>
                        <div class="stat-value"><?= $reportedStudents ?></div>
                        <div style="font-size:.72rem;color:var(--text-muted,#9ca3af);margin-top:.3rem">Share paid to HO</div>
                    </div>
                </div>
                <div class="stat-card amber clickable" onclick="openDetailModal('pending_report')" title="Click to view pending reports grouped by ATC">
                    <div class="stat-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Pending Reports</div>
                        <div class="stat-value"><?= $pendingReporting ?></div>
                        <div style="font-size:.72rem;color:var(--text-muted,#9ca3af);margin-top:.3rem">Share not yet paid</div>
                    </div>
                </div>
            </div>

            <!-- ═══ Authorization Expiry Alert Panel ═══ -->
            <?php if (!empty($expiringATCs)): ?>
            <div class="bday-panel" style="margin-top:1.5rem; border-color:#fcd34d;">
                <div class="bday-panel-header" style="background:#fffbeb; border-color:#fcd34d;">
                    <div class="bday-panel-title" style="color:#92400e;">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:#d97706"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        ⚠️ Authorization Expiry Alerts
                    </div>
                    <span class="bday-date"><?= count($expiringATCs) ?> center(s) need attention</span>
                </div>
                <div class="bday-panel-body">
                    <?php foreach ($expiringATCs as $e): ?>
                    <div class="bday-row">
                        <div class="bday-avatar" style="background:linear-gradient(135deg,#f59e0b,#d97706);">
                            <?= mb_strtoupper(mb_substr($e['name'], 0, 1)) ?>
                        </div>
                        <div class="bday-info">
                            <div class="bday-name"><?= htmlspecialchars($e['name']) ?></div>
                            <div class="bday-tag"><?= htmlspecialchars($e['district'] . ', ' . $e['state']) ?></div>
                        </div>
                        <div style="margin-left:auto; display:flex; align-items:center; gap:.75rem;">
                            <?php if ($e['days_left'] < 0): ?>
                                <span style="font-size:.75rem;font-weight:700;color:#dc2626;background:#fee2e2;padding:.2rem .6rem;border-radius:99px;">Expired <?= abs($e['days_left']) ?> days ago</span>
                            <?php else: ?>
                                <span style="font-size:.75rem;font-weight:700;color:#d97706;background:#fef3c7;padding:.2rem .6rem;border-radius:99px;"><?= $e['days_left'] === '0' ? 'Expires today!' : "Expires in {$e['days_left']} days" ?></span>
                            <?php endif; ?>
                            <a href="atc_centers.php?highlight=<?= $e['id'] ?>" style="font-size:.78rem;font-weight:700;color:#2563eb;text-decoration:none;white-space:nowrap;">Renew Now →</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ═══ Calendar + Birthdays ═══ -->
            <div class="cal-bday-wrap">
                <div class="cal-card">
                    <div class="cal-nav">
                        <button type="button" class="cal-nav-btn" id="calPrev" aria-label="Previous month">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
                        </button>
                        <h3 id="calTitle">—</h3>
                        <button type="button" class="cal-nav-btn" id="calNext" aria-label="Next month">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                        </button>
                    </div>
                    <div class="cal-weekdays">
                        <span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span><span>S</span>
                    </div>
                    <div class="cal-days" id="calDays"></div>
                    <div class="cal-legend">
                        <span><i class="lg-holiday"></i> Holiday</span>
                        <span><i class="lg-today"></i> Today</span>
                        <span><i class="lg-bday"></i> Birthday</span>
                    </div>
                </div>
                <div class="cal-bday-side">
                    <div class="cal-bday-side-head">
                        <span class="ico">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </span>
                        <span id="calBdayTitle">Birthdays</span>
                    </div>
                    <div class="cal-bday-side-body" id="calBdayList"></div>
                </div>
            </div>

            <!-- ═══ Quick Actions ═══ -->
            <div class="section-header">
                <h3>Quick Actions</h3>
            </div>
            <div class="actions-grid">
                <a href="dlc_offices.php" class="action-card">
                    <div class="action-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/></svg></div>
                    <h4>Manage DLCs</h4><p>View and manage DLC offices</p>
                </a>
                <a href="atc_centers.php" class="action-card">
                    <div class="action-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg></div>
                    <h4>Manage ATCs</h4><p>View and manage ATC centers</p>
                </a>
                <a href="students.php" class="action-card">
                    <div class="action-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg></div>
                    <h4>Admissions</h4><p>All student admissions</p>
                </a>
                <a href="reports.php" class="action-card">
                    <div class="action-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg></div>
                    <h4>View Reports</h4><p>Analytics and reporting</p>
                </a>
            </div>

            <!-- ═══ REVENUE OVERVIEW (HO Share only — not ATC fee earnings) ═══ -->
            <div class="rpt-section">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Share Revenue Overview
                <span style="margin-left:.5rem;font-size:.72rem;font-weight:600;color:#64748b;text-transform:none;letter-spacing:0">Admin income = ATC share payments only</span>
            </div>
            <div class="rev-grid">
                <div class="rev-card">
                    <div class="rev-icon" style="background:linear-gradient(135deg,#ec4899,#db2777)">
                        <svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div>
                        <div class="rev-label">Share Revenue</div>
                        <div class="rev-value">₹ <?= number_format($revenueStats['total_revenue'],0) ?></div>
                        <div class="rev-sub">Completed HO share received</div>
                    </div>
                </div>
                <div class="rev-card">
                    <div class="rev-icon" style="background:linear-gradient(135deg,#10b981,#059669)">
                        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <div class="rev-label">Share Received</div>
                        <div class="rev-value">₹ <?= number_format($revenueStats['total_collected'],0) ?></div>
                        <div class="rev-sub"><?= (int)($revenueStats['atc_paid_count'] ?? 0) ?> ATC(s) · <?= (int)($revenueStats['txn_count'] ?? 0) ?> txn</div>
                    </div>
                </div>
                <div class="rev-card">
                    <div class="rev-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706)">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div>
                        <div class="rev-label">Pending Share</div>
                        <div class="rev-value">₹ <?= number_format($revenueStats['total_pending'],0) ?></div>
                        <div class="rev-sub">Share payments awaiting completion</div>
                    </div>
                </div>
            </div>

            <!-- ═══ DLC SHARE TABLE ═══ -->
            <div class="rpt-section">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                Share Received by DLC Office
            </div>
            <div class="rpt-table-wrap">
                <table class="rpt-table">
                    <thead><tr><th>DLC Office</th><th>Location</th><th>ATCs</th><th>Students</th><th>Share Total</th><th>Received</th><th>Pending</th><th>%</th></tr></thead>
                    <tbody>
                    <?php foreach ($dlcRevenue as $dlc):
                        $pct = $dlc['total_revenue']>0 ? round($dlc['collected']/$dlc['total_revenue']*100,1) : 0;
                        $clr = $pct>=75 ? '#10b981' : ($pct>=50 ? '#f59e0b' : '#ef4444');
                    ?>
                    <tr>
                        <td style="font-weight:800"><?= htmlspecialchars($dlc['dlc_name']) ?></td>
                        <td style="color:#6b7280"><?= htmlspecialchars($dlc['district']) ?></td>
                        <td><strong><?= $dlc['atc_count'] ?></strong></td>
                        <td><strong><?= $dlc['total_students'] ?></strong></td>
                        <td style="font-weight:600">₹<?= number_format($dlc['total_revenue'],0) ?></td>
                        <td style="color:#059669;font-weight:700">₹<?= number_format($dlc['collected'],0) ?></td>
                        <td style="color:#d97706;font-weight:700">₹<?= number_format($dlc['pending'],0) ?></td>
                        <td>
                            <span style="font-weight:800;font-size:.8rem"><?= $pct ?>%</span>
                            <div class="prog-bar"><div class="prog-fill" style="width:<?= min($pct,100) ?>%;background:<?= $clr ?>"></div></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($dlcRevenue)): ?><tr><td colspan="8" style="text-align:center;padding:2rem;color:#9ca3af">No data</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- ═══ DISPATCH STATS ═══ -->
            <div class="rpt-section">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3"/><path d="m15 9 6-6"/></svg>
                Material Dispatch Overview
            </div>
            <div class="dsp-grid">
                <div class="dsp-card"><div class="dsp-val"><?= $dispatchStats['total_dispatches']??0 ?></div><div class="dsp-lbl">Total Dispatches</div></div>
                <div class="dsp-card"><div class="dsp-val" style="color:#f59e0b"><?= ($dispatchStats['created']??0)+($dispatchStats['sent_to_dlc']??0) ?></div><div class="dsp-lbl">Pending</div></div>
                <div class="dsp-card"><div class="dsp-val" style="color:#0ea5e9"><?= $dispatchStats['forwarded_to_atc']??0 ?></div><div class="dsp-lbl">In Transit</div></div>
                <div class="dsp-card"><div class="dsp-val" style="color:#10b981"><?= $dispatchStats['delivered']??0 ?></div><div class="dsp-lbl">Delivered</div></div>
                <div class="dsp-card"><div class="dsp-val" style="color:#6366f1"><?= $dispatchStats['total_items']??0 ?></div><div class="dsp-lbl">Total Items</div></div>
            </div>

            <!-- ═══ TOP ATCs (center type + period filters) ═══ -->
            <div class="rpt-section">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                Top Performing ATCs
            </div>
            <div class="top-atc-head">
                <div class="top-atc-filters">
                    <div>
                        <label for="topAtcCenterType">Center Type</label>
                        <select id="topAtcCenterType">
                            <option value="">All Types</option>
                            <option value="Abacus">Abacus</option>
                            <option value="Vedic Maths">Vedic Maths</option>
                            <option value="IT">IT</option>
                        </select>
                    </div>
                    <div>
                        <label>Period</label>
                        <div class="period-pills" id="topAtcPeriodPills">
                            <button type="button" class="period-pill" data-period="today">Today</button>
                            <button type="button" class="period-pill" data-period="month">This Month</button>
                            <button type="button" class="period-pill" data-period="year">This Year</button>
                            <button type="button" class="period-pill active" data-period="all">All Time</button>
                        </div>
                    </div>
                </div>
                <div style="font-size:.75rem;color:#94a3b8;font-weight:600" id="topAtcMeta">Ranked by share paid to HO</div>
            </div>
            <div class="rpt-table-wrap">
                <table class="rpt-table">
                    <thead>
                        <tr>
                            <th>Rank</th>
                            <th>ATC Center</th>
                            <th>Center Type</th>
                            <th>DLC Office</th>
                            <th>Students</th>
                            <th>Share Paid to HO</th>
                        </tr>
                    </thead>
                    <tbody id="topAtcTbody">
                    <?php if (empty($topATCs)): ?>
                        <tr><td colspan="6" style="text-align:center;padding:2rem;color:#9ca3af">No ATC performance data yet</td></tr>
                    <?php else: foreach ($topATCs as $i => $atc): ?>
                    <tr>
                        <td>
                            <span class="rank-badge <?= $i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':'rank-other')) ?>">
                                <?php if($i<3): ?><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg><?php endif; ?>
                                #<?= $i+1 ?>
                            </span>
                        </td>
                        <td style="font-weight:800">
                            <?= htmlspecialchars($atc['atc_name']) ?>
                            <?php if (!empty($atc['atc_code'])): ?>
                            <div style="font-size:.72rem;color:#94a3b8;font-weight:600;font-family:ui-monospace,monospace"><?= htmlspecialchars($atc['atc_code']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?php if (!empty($atc['center_type'])): ?><span class="ctype-chip"><?= htmlspecialchars($atc['center_type']) ?></span><?php else: ?>—<?php endif; ?></td>
                        <td style="color:#6b7280"><?= htmlspecialchars($atc['dlc_name']??'—') ?></td>
                        <td><strong><?= (int)$atc['student_count'] ?></strong></td>
                        <td style="color:#059669;font-weight:700">₹<?= number_format((float)$atc['revenue_collected'],0) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- ═══ MONTHLY TREND CHART ═══ -->
            <?php if(!empty($monthlyTrend)):
                $maxRev = max(array_column($monthlyTrend,'revenue')?:[1]);
            ?>
            <div class="rpt-section">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Monthly Admissions &amp; Share Revenue (Last 6 Months)
            </div>
            <div class="rpt-table-wrap" style="padding:1.25rem 1.5rem 1rem">
                <div class="chart-wrap">
                    <?php foreach($monthlyTrend as $m):
                        $h = $maxRev>0 ? max(6,round(($m['revenue']/$maxRev)*140)) : 6;
                        $lbl = date('M Y', strtotime($m['month'].'-01'));
                    ?>
                    <div class="chart-col">
                        <div class="chart-bar-box">
                            <div class="chart-bar" style="height:<?= $h ?>px" title="<?= $lbl ?>: Share ₹<?= number_format($m['revenue'],0) ?>"></div>
                        </div>
                        <div class="chart-mlbl"><?= date('M', strtotime($m['month'].'-01')) ?></div>
                        <div class="chart-msub"><?= $m['admissions'] ?> adm</div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="font-size:.72rem;color:#64748b;font-weight:600;margin-top:.75rem">Bar height = HO share received (not ATC student fees)</div>
            </div>
            <?php endif; ?>

        </div>
    </main>
</div>

<!-- ═══ DETAIL MODAL (L2 + L3) ═══ -->
<div class="modal-overlay" id="detailModal">
    <div class="modal-card" style="max-width:780px">
        <div class="modal-header gradient">
            <h3>
                <span id="detailModalTitle">Details</span>
                <span id="detailModalCount" style="font-size:.72rem;font-weight:700;background:rgba(255,255,255,.25);padding:.2rem .65rem;border-radius:999px"></span>
            </h3>
            <button type="button" class="modal-close" onclick="closeDetailModal()" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <div id="detailModalBody"></div>
        <div class="modal-footer" style="text-align:right">
            <button type="button" class="btn-secondary" onclick="closeDetailModal()">Close</button>
        </div>
    </div>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
const DATA = {
    admissions:   <?= json_encode($admissionsList,   JSON_HEX_TAG) ?>,
    inquiries:    <?= json_encode($inquiriesList,    JSON_HEX_TAG) ?>,
    reported:     <?= json_encode($reportedList,     JSON_HEX_TAG) ?>,
    pending_report: <?= json_encode($pendingList,    JSON_HEX_TAG) ?>,
    pending_exam: <?= json_encode($pendingExamList,  JSON_HEX_TAG) ?>,
};
const MODAL_TITLES = {
    admissions:     'All Admissions',
    inquiries:      'All Inquiries',
    reported:       'Reported Students (Share Paid to HO)',
    pending_report: 'Pending Reports — Grouped by ATC',
    pending_exam:   'Pending Exam — Students Yet to Appear (Main Exam Only)',
};

function openDetailModal(type) {
    const items = DATA[type] || [];
    document.getElementById('detailModalTitle').textContent = MODAL_TITLES[type];
    document.getElementById('detailModalCount').textContent = items.length + ' record(s)';

    let html = '';

    if (type === 'pending_report' || type === 'reported' || type === 'pending_exam') {
        // Grouped list view by ATC
        const groups = {};
        items.forEach(s => {
            const k = s.atc_name || 'Unknown';
            if (!groups[k]) groups[k] = [];
            groups[k].push(s);
        });

        // For pending_exam: show summary header (X centers, Y students)
        let summaryHtml = '';
        if (type === 'pending_exam') {
            const centerCount = Object.keys(groups).length;
            summaryHtml = `<div style="padding:.6rem 1.5rem;background:#eff6ff;border-bottom:1px solid #bfdbfe;font-size:.8rem;font-weight:700;color:#1e40af">
                📊 ${centerCount} center${centerCount !== 1 ? 's' : ''} &nbsp;·&nbsp; ${items.length} student${items.length !== 1 ? 's' : ''} yet to appear for main exam
            </div>`;
        }

        html = summaryHtml + '<div class="pending-list">';
        if (!items.length) {
            html += '<p style="text-align:center;padding:2rem;color:#9ca3af">No students found.</p>';
        } else {
            Object.entries(groups).forEach(([atc, students]) => {
                const badge = type === 'pending_report' ? 'pending' : (type === 'pending_exam' ? 'not examined' : 'reported');
                const badgeColor = type === 'pending_exam' ? 'background:#dbeafe;color:#1e40af' : '';
                html += `<div class="pending-atc-group">
                    <div class="pending-atc-header">
                        <span>${atc}${type === 'pending_exam' && students[0]?.atc_code ? ' <span style="font-size:.68rem;color:#6b7280;font-weight:600">('+students[0].atc_code+')</span>' : ''}</span>
                        <span class="pending-atc-count" style="${badgeColor}">${students.length} ${badge}</span>
                    </div>`;
                students.forEach(s => {
                    const init = (s.name||'?')[0].toUpperCase();
                    const avatarHtml = s.photo
                        ? `<img src="../${s.photo}" style="width:30px;height:30px;border-radius:50%;object-fit:cover;border:1.5px solid #e5e7eb;flex-shrink:0" onerror="this.outerHTML='<div class=\\'bday-avatar\\' style=\\'width:30px;height:30px;font-size:.75rem\\'>${init}</div>'">`
                        : `<div class="bday-avatar" style="width:30px;height:30px;font-size:.75rem">${init}</div>`;
                    html += `<div class="pending-student-row">
                        ${avatarHtml}
                        <span style="font-weight:600">${s.name}</span>
                        <span style="color:#9ca3af;margin-left:auto">${s.course||'—'}</span>
                    </div>`;
                });
                html += '</div>';
            });
        }
        html += '</div>';

    } else {
        // TABLE format for admissions & inquiries
        if (!items.length) {
            html = '<p style="text-align:center;padding:2.5rem;color:#9ca3af;font-weight:600">No records found.</p>';
        } else {
            const isInq = type === 'inquiries';
            const cols  = isInq
                ? ['Student', 'Mobile', 'Course Interested', 'ATC Center', 'Status', 'Date']
                : ['Student', 'Roll No', 'Course', 'ATC Center', 'Date'];

            const ths = cols.map(c =>
                `<th style="padding:.65rem 1rem;text-align:left;font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;border-bottom:2px solid #e5e7eb;white-space:nowrap;background:#f9fafb">${c}</th>`
            ).join('');

            html = `<div style="overflow-x:auto;max-height:62vh;overflow-y:auto">
                <table style="width:100%;border-collapse:collapse;font-size:.84rem">
                <thead><tr>${ths}</tr></thead><tbody>`;

            items.forEach((s, i) => {
                const init = (s.name||'?').split(' ').filter(Boolean).map(w=>w[0]).slice(0,2).join('').toUpperCase();
                const bg   = i % 2 === 0 ? '#fff' : '#f9fafb';

                // Avatar / photo — show ONLY one: photo if exists, else initial
                const photoCell = s.photo
                    ? `<img src="../${s.photo}" style="width:32px;height:32px;border-radius:50%;object-fit:cover;border:1.5px solid #e5e7eb;vertical-align:middle;margin-right:.6rem;flex-shrink:0" onerror="this.outerHTML='<span style=\\'display:inline-flex;width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#4361ee,#8b5cf6);color:#fff;font-size:.7rem;font-weight:800;align-items:center;justify-content:center;vertical-align:middle;margin-right:.6rem;flex-shrink:0\\'>${init}</span>'">`
                    : `<span style="display:inline-flex;width:32px;height:32px;border-radius:50%;background:linear-gradient(135deg,#4361ee,#8b5cf6);color:#fff;font-size:.7rem;font-weight:800;align-items:center;justify-content:center;vertical-align:middle;margin-right:.6rem;flex-shrink:0">${init}</span>`;

                const rowStyle = `background:${bg};border-bottom:1px solid #f3f4f6;transition:background .12s`;
                const nameCell = `<td style="padding:.65rem 1rem;vertical-align:middle"><span style="display:inline-flex;align-items:center">${photoCell}<strong style="color:#111827">${s.name}</strong></span></td>`;
                const atcBadge = `<span style="background:#eef2ff;color:#4338ca;font-size:.72rem;font-weight:700;padding:.2rem .6rem;border-radius:999px">${s.atc_name||'—'}</span>`;
                const td = (v, extra='') => `<td style="padding:.65rem 1rem;color:#4b5563${extra}">${v}</td>`;

                if (isInq) {
                    const sc = s.status === 'Converted' ? '#10b981' : s.status === 'Hot' ? '#ef4444' : '#6b7280';
                    const dt = s.created_at ? new Date(s.created_at).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—';
                    html += `<tr style="${rowStyle}">${nameCell}${td(s.mobile||'—')}${td(s.course||'—')}<td style="padding:.65rem 1rem">${atcBadge}</td>${td(`<span style="color:${sc};font-weight:700">${s.status||'—'}</span>`)}${td(dt,';white-space:nowrap;color:#9ca3af')}</tr>`;
                } else {
                    const dt = s.admission_date ? new Date(s.admission_date+'T00:00:00').toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}) : '—';
                    html += `<tr style="${rowStyle}">${nameCell}${td(s.roll_no||'—', ';font-family:monospace')}${td(s.course||'—')}<td style="padding:.65rem 1rem">${atcBadge}</td>${td(dt,';white-space:nowrap;color:#9ca3af')}</tr>`;
                }
            });

            html += '</tbody></table></div>';
        }
    }

    document.getElementById('detailModalBody').innerHTML = html;
    document.getElementById('detailModal').classList.add('active');
}

function closeDetailModal() {
    document.getElementById('detailModal').classList.remove('active');
}

// ── Dashboard calendar + birthdays ───────────────────────────────────────────
(function initDashCalendar() {
    const daysEl = document.getElementById('calDays');
    const titleEl = document.getElementById('calTitle');
    const listEl = document.getElementById('calBdayList');
    const listTitle = document.getElementById('calBdayTitle');
    if (!daysEl || !titleEl || !listEl) return;

    const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    const raw = <?= json_encode(array_map(static function ($r) {
        return [
            'name' => (string)($r['name'] ?? ''),
            'type' => (string)($r['type'] ?? ''),
            'mobile' => (string)($r['mobile'] ?? ''),
            'month' => (int)($r['b_month'] ?? 0),
            'day' => (int)($r['b_day'] ?? 0),
        ];
    }, $calendarBirthdays ?? []), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const holidays = <?= json_encode($calendarHolidays ?? [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const byKey = {};
    raw.forEach(b => {
        if (!b.month || !b.day) return;
        const k = b.month + '-' + b.day;
        (byKey[k] || (byKey[k] = [])).push(b);
    });

    const now = new Date();
    let viewY = now.getFullYear();
    let viewM = now.getMonth(); // 0-based
    let selectedDay = null;

    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function renderList(monthIdx, dayFilter) {
        const monthNum = monthIdx + 1;
        let items = [];
        Object.keys(byKey).forEach(k => {
            const [m, d] = k.split('-').map(Number);
            if (m !== monthNum) return;
            if (dayFilter && d !== dayFilter) return;
            byKey[k].forEach(b => items.push({ ...b, day: d }));
        });
        items.sort((a, b) => a.day - b.day || a.name.localeCompare(b.name));

        listTitle.textContent = dayFilter
            ? ('Birthdays — ' + dayFilter + ' ' + MONTHS[monthIdx])
            : ('Birthdays — ' + MONTHS[monthIdx]);

        if (!items.length) {
            listEl.innerHTML = '<div class="cal-bday-empty"><div class="cake">🎂</div><div>No birthdays this ' + (dayFilter ? 'day' : 'month') + '</div></div>';
            return;
        }

        listEl.innerHTML = items.map(b => {
            const init = esc((b.name || '?').trim().charAt(0).toUpperCase());
            const wish = b.mobile
                ? `<button type="button" onclick="sendBdayWish('${esc(b.name).replace(/'/g, "\\'")}', '${esc(b.mobile)}')" style="margin-left:auto;display:inline-flex;align-items:center;gap:.35rem;padding:.35rem .7rem;border-radius:999px;border:none;background:#25d366;color:#fff;font-size:.72rem;font-weight:700;cursor:pointer;font-family:inherit">Wish</button>`
                : `<span class="bday-wish" style="margin-left:auto">🎉</span>`;
            return `<div class="bday-row">
                <div class="bday-avatar" style="width:34px;height:34px;font-size:.85rem">${init}</div>
                <div class="bday-info">
                    <div class="bday-name">${esc(b.name)}</div>
                    <div class="bday-tag">${esc(b.type)} · ${b.day} ${MONTHS[monthIdx].slice(0,3)}</div>
                </div>
                ${wish}
            </div>`;
        }).join('');
    }

    function render() {
        titleEl.textContent = MONTHS[viewM] + ' ' + viewY;
        const first = new Date(viewY, viewM, 1);
        // Monday-first: Sun=0 -> 6, Mon=1 -> 0
        let startPad = first.getDay() - 1;
        if (startPad < 0) startPad = 6;
        const dim = new Date(viewY, viewM + 1, 0).getDate();
        const todayY = now.getFullYear(), todayM = now.getMonth(), todayD = now.getDate();

        let html = '';
        for (let i = 0; i < startPad; i++) html += '<div class="cal-day empty"></div>';
        for (let d = 1; d <= dim; d++) {
            const key = (viewM + 1) + '-' + d;
            const hasBday = !!(byKey[key] && byKey[key].length);
            const isHoliday = !!holidays[key];
            const isToday = viewY === todayY && viewM === todayM && d === todayD;
            const isSelected = selectedDay === d;
            const cls = ['cal-day'];
            if (hasBday) cls.push('has-bday');
            if (isToday) cls.push('is-today');
            if (isHoliday) cls.push('is-holiday');
            if (isSelected) cls.push('is-selected');
            const title = [
                isHoliday ? holidays[key] : '',
                hasBday ? (byKey[key].length + ' birthday(s)') : ''
            ].filter(Boolean).join(' · ');
            html += `<div class="${cls.join(' ')}" data-day="${d}" title="${esc(title)}">
                <span>${d}</span>
                ${hasBday ? '<span class="cal-dot"></span>' : ''}
            </div>`;
        }
        daysEl.innerHTML = html;
        daysEl.querySelectorAll('.cal-day.has-bday').forEach(el => {
            el.addEventListener('click', () => {
                selectedDay = Number(el.dataset.day);
                render();
                renderList(viewM, selectedDay);
            });
        });
        renderList(viewM, null);
    }

    document.getElementById('calPrev')?.addEventListener('click', () => {
        selectedDay = null;
        viewM -= 1;
        if (viewM < 0) { viewM = 11; viewY -= 1; }
        render();
    });
    document.getElementById('calNext')?.addEventListener('click', () => {
        selectedDay = null;
        viewM += 1;
        if (viewM > 11) { viewM = 0; viewY += 1; }
        render();
    });

    render();
})();

function sendBdayWish(name, mobile) {
    const msg = encodeURIComponent('Dear ' + name + ',\n\nWarm Birthday Greetings from the entire Gyanam India family!\n\nOn this special occasion, we extend our heartfelt wishes to you. May this new year of your life bring you great health, abundant happiness, and continued success in everything you pursue.\n\nWe are grateful to have you as a valued part of the Gyanam India community. May your day be as wonderful as the joy you bring to everyone around you.\n\nWith warm regards,\nTeam Gyanam India');
    const num = mobile.replace(/\D/g, '');
    window.open('https://wa.me/91' + num + '?text=' + msg, '_blank');
}

// ── Top Performing ATCs filters ──────────────────────────────────────────────
let topAtcPeriod = 'all';
const PERIOD_LABELS = { today: 'Today', month: 'This Month', year: 'This Year', all: 'All Time' };

function escHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function fmtInr(n) {
    return '₹' + Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 0 });
}

function renderTopAtcRows(rows) {
    const tbody = document.getElementById('topAtcTbody');
    if (!tbody) return;
    if (!rows || !rows.length) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2rem;color:#9ca3af">No ATC performance data for this filter</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map((atc, i) => {
        const rankCls = i === 0 ? 'rank-1' : (i === 1 ? 'rank-2' : (i === 2 ? 'rank-3' : 'rank-other'));
        const crown = i < 3
            ? '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>'
            : '';
        const code = atc.atc_code
            ? `<div style="font-size:.72rem;color:#94a3b8;font-weight:600;font-family:ui-monospace,monospace">${escHtml(atc.atc_code)}</div>`
            : '';
        const ctype = atc.center_type
            ? `<span class="ctype-chip">${escHtml(atc.center_type)}</span>`
            : '—';
        return `<tr>
            <td><span class="rank-badge ${rankCls}">${crown} #${i + 1}</span></td>
            <td style="font-weight:800">${escHtml(atc.atc_name)}${code}</td>
            <td>${ctype}</td>
            <td style="color:#6b7280">${escHtml(atc.dlc_name || '—')}</td>
            <td><strong>${Number(atc.student_count || 0)}</strong></td>
            <td style="color:#059669;font-weight:700">${fmtInr(atc.revenue_collected)}</td>
        </tr>`;
    }).join('');
}

async function loadTopAtcs() {
    const typeSel = document.getElementById('topAtcCenterType');
    const meta = document.getElementById('topAtcMeta');
    const tbody = document.getElementById('topAtcTbody');
    if (!typeSel || !tbody) return;

    const centerType = typeSel.value || '';
    const typeLabel = centerType || 'All Types';
    if (meta) meta.textContent = `${typeLabel} · ${PERIOD_LABELS[topAtcPeriod] || 'All Time'} · by share paid to HO`;
    tbody.style.opacity = '.45';

    const fd = new FormData();
    fd.append('action', 'top_atcs');
    fd.append('center_type', centerType);
    fd.append('period', topAtcPeriod);

    try {
        const res = await fetch('index.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) renderTopAtcRows(data.rows || []);
        else tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2rem;color:#ef4444">Could not load ranking</td></tr>';
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:2rem;color:#ef4444">Network error</td></tr>';
    } finally {
        tbody.style.opacity = '1';
    }
}

(function initTopAtcFilters() {
    const typeSel = document.getElementById('topAtcCenterType');
    const pills = document.getElementById('topAtcPeriodPills');
    if (!typeSel || !pills) return;
    typeSel.addEventListener('change', loadTopAtcs);
    pills.querySelectorAll('.period-pill').forEach(btn => {
        btn.addEventListener('click', () => {
            pills.querySelectorAll('.period-pill').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            topAtcPeriod = btn.dataset.period || 'all';
            loadTopAtcs();
        });
    });
})();
</script>
</body>
</html>
