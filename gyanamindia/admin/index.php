<?php
/**
 * Gyanam Portal — Head Office Dashboard
 * L — Major Revamp (L1: rename cards, L2: clickable admissions/inquiries, L3: reporting cards)
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$greeting = getGreeting();

try { checkBirthdayNotifications($pdo); } catch (Exception $e) {}

// ── Stats ─────────────────────────────────────────────────────────────────────
$totalUsers = $totalDLC = $totalATC = $totalInquiries = $totalAdmissions = 0;
$pendingExam = 0; $todayBirthdays = []; $expiringATCs = [];

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
        LIMIT 500
    ");
    $allStudents = $peStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get identifiers of students who have already passed OR attempted the MAIN exam
    $attemptedIds = [];
    if (function_exists('fetchAllExamResults') && defined('EXAM_API_TOKEN') && EXAM_API_TOKEN !== 'PASTE_YOUR_TOKEN_HERE') {
        $erRes = fetchAllExamResults();
        if ($erRes['success'] && isset($erRes['data']['submissions'])) {
            foreach ($erRes['data']['submissions'] as $sub) {
                // Skip demo exams
                $etitle = strtolower($sub['exam_title'] ?? ($sub['exam']['title'] ?? ''));
                if (str_contains($etitle, 'demo')) continue;
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
try {
    $todayBirthdays = [];

    $aStmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(contact_person),''), name) AS name,
               dob, 'ATC Center' AS type, IFNULL(district,'') AS location,
               IFNULL(mobile,'') AS mobile
        FROM atc_centers
        WHERE dob IS NOT NULL
          AND DATE_FORMAT(dob, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')
          AND status = 'Active'
        ORDER BY name ASC
    ");
    $aStmt->execute();
    $todayBirthdays = array_merge($todayBirthdays, $aStmt->fetchAll(PDO::FETCH_ASSOC));

    $dStmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(contact_person),''), name) AS name,
               dob, 'DLC Office' AS type, IFNULL(district,'') AS location,
               IFNULL(mobile,'') AS mobile
        FROM dlc_offices
        WHERE dob IS NOT NULL
          AND DATE_FORMAT(dob, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')
          AND status = 'Active'
        ORDER BY name ASC
    ");
    $dStmt->execute();
    $todayBirthdays = array_merge($todayBirthdays, $dStmt->fetchAll(PDO::FETCH_ASSOC));

    $mStmt = $pdo->prepare("
        SELECT person_name AS name,
               birth_date AS dob, 'Manual Entry' AS type, IFNULL(description,'') AS location,
               IFNULL(mobile,'') AS mobile
        FROM birthdays
        WHERE birth_date IS NOT NULL
          AND DATE_FORMAT(birth_date, '%m-%d') = DATE_FORMAT(CURDATE(), '%m-%d')
          AND status = 'Active'
        ORDER BY name ASC
    ");
    $mStmt->execute();
    $todayBirthdays = array_merge($todayBirthdays, $mStmt->fetchAll(PDO::FETCH_ASSOC));

    usort($todayBirthdays, static function ($left, $right) {
        return strcasecmp((string)($left['name'] ?? ''), (string)($right['name'] ?? ''));
    });
} catch (Exception $e) {}

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

// ── Report stats (embedded from reports.php, no filter) ──────────────────
$revenueStats    = ['total_revenue'=>0,'total_collected'=>0,'total_pending'=>0];
$dlcRevenue      = [];
$dispatchStats   = ['total_dispatches'=>0,'created'=>0,'sent_to_dlc'=>0,'forwarded_to_atc'=>0,'delivered'=>0,'total_items'=>0];
$materialBreakdown = [];
$topATCs         = [];
$monthlyTrend    = [];

try {
    $revenueStats = $pdo->query("
        SELECT COALESCE(SUM(course_fees - discount_amount),0) AS total_revenue,
               COALESCE(SUM(fees_paid),0)    AS total_collected,
               COALESCE(SUM(fees_pending),0) AS total_pending
        FROM admissions WHERE status='Active'
    ")->fetch(PDO::FETCH_ASSOC) ?: $revenueStats;
} catch(Exception $e){}

try {
    $dlcRevenue = $pdo->query("
        SELECT dlc.id, dlc.name AS dlc_name, dlc.district,
               COUNT(DISTINCT atc.id) AS atc_count,
               COUNT(DISTINCT adm.id) AS total_students,
               COALESCE(SUM(adm.course_fees-adm.discount_amount),0) AS total_revenue,
               COALESCE(SUM(adm.fees_paid),0)    AS collected,
               COALESCE(SUM(adm.fees_pending),0) AS pending
        FROM dlc_offices dlc
        LEFT JOIN atc_centers atc ON dlc.id=atc.dlc_id AND atc.status='Active'
        LEFT JOIN admissions  adm ON atc.id=adm.atc_id AND adm.status='Active'
        GROUP BY dlc.id ORDER BY collected DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

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
} catch(Exception $e){}

try {
    $materialBreakdown = $pdo->query("
        SELECT material_type, COUNT(*) AS dispatch_count, SUM(quantity) AS total_quantity
        FROM dispatches GROUP BY material_type ORDER BY total_quantity DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

try {
    $topATCs = $pdo->query("
        SELECT atc.name AS atc_name, dlc.name AS dlc_name,
               COUNT(adm.id) AS student_count,
               SUM(adm.fees_paid) AS revenue_collected
        FROM atc_centers atc
        LEFT JOIN dlc_offices dlc ON atc.dlc_id=dlc.id
        LEFT JOIN admissions  adm ON atc.id=adm.atc_id AND adm.status='Active'
        WHERE atc.status='Active'
        GROUP BY atc.id ORDER BY revenue_collected DESC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}

try {
    $monthlyTrend = $pdo->query("
        SELECT DATE_FORMAT(admission_date,'%Y-%m') AS month,
               COUNT(*) AS admissions,
               SUM(course_fees-discount_amount) AS revenue,
               SUM(fees_paid) AS collected
        FROM admissions
        WHERE admission_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(admission_date,'%Y-%m') ORDER BY month ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e){}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Head Office | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📚</text></svg>">
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
    .bday-wish { margin-left:auto; font-size:1.3rem; }

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

        <div class="page-content">

            <!-- ═══ OVERVIEW CARDS ═══ -->
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

            <!-- ═══ Birthdays ═══ -->
            <div class="bday-panel" style="margin-top:1.5rem;">
                <div class="bday-panel-header">
                    <div class="bday-panel-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        🎂 Today's Birthdays — ATC & DLC
                    </div>
                    <span class="bday-date"><?= date('d F Y') ?></span>
                </div>
                <div class="bday-panel-body">
                <?php if (empty($todayBirthdays)): ?>
                    <div class="bday-empty">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>
                        <p>No birthdays today</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($todayBirthdays as $b): ?>
                    <div class="bday-row">
                        <div class="bday-avatar"><?= mb_strtoupper(mb_substr($b['name'], 0, 1)) ?></div>
                        <div class="bday-info">
                            <div class="bday-name"><?= htmlspecialchars($b['name']) ?></div>
                            <div class="bday-tag"><?= htmlspecialchars($b['type']) ?></div>
                        </div>
                        <?php if (!empty($b['mobile'])): ?>
                        <button onclick="sendBdayWish('<?= addslashes(htmlspecialchars($b['name'])) ?>', '<?= htmlspecialchars($b['mobile']) ?>')" style="margin-left:auto;display:inline-flex;align-items:center;gap:.35rem;padding:.4rem .85rem;border-radius:999px;border:none;background:#25d366;color:#fff;font-size:.75rem;font-weight:700;cursor:pointer;white-space:nowrap;font-family:inherit;transition:opacity .15s" onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                            <svg viewBox="0 0 24 24" fill="white" style="width:13px;height:13px"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.67-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.076 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421-7.403h-.004c-1.425 0-2.835.356-4.06 1.031l-.291.169-3.015-.787.804 2.93-.189.301A7.002 7.002 0 003.02 9.414c0 3.866 3.113 7.012 6.938 7.012 1.893 0 3.672-.652 5.093-1.849 1.42-1.198 2.33-2.926 2.33-4.856 0-3.866-3.113-7.012-6.938-7.012m6.938 13.6H4.059A8.968 8.968 0 000 11.5C0 5.477 5.507 0.5 12 0.5s12 4.977 12 11-5.507 11-12 11z"/></svg>
                            Send Wish
                        </button>
                        <?php else: ?>
                        <div class="bday-wish" style="margin-left:auto;">🎉</div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
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

            <!-- ═══ REVENUE OVERVIEW ═══ -->
            <div class="rpt-section">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                Revenue Overview
            </div>
            <div class="rev-grid">
                <div class="rev-card">
                    <div class="rev-icon" style="background:linear-gradient(135deg,#ec4899,#db2777)">
                        <svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                    </div>
                    <div>
                        <div class="rev-label">Total Revenue</div>
                        <div class="rev-value">₹ <?= number_format($revenueStats['total_revenue'],0) ?></div>
                        <div class="rev-sub">Expected from all admissions</div>
                    </div>
                </div>
                <div class="rev-card">
                    <div class="rev-icon" style="background:linear-gradient(135deg,#10b981,#059669)">
                        <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                    </div>
                    <div>
                        <div class="rev-label">Collected</div>
                        <div class="rev-value">₹ <?= number_format($revenueStats['total_collected'],0) ?></div>
                        <div class="rev-sub"><?= $revenueStats['total_revenue']>0 ? round($revenueStats['total_collected']/$revenueStats['total_revenue']*100,1) : 0 ?>% collection rate</div>
                    </div>
                </div>
                <div class="rev-card">
                    <div class="rev-icon" style="background:linear-gradient(135deg,#f59e0b,#d97706)">
                        <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div>
                        <div class="rev-label">Pending</div>
                        <div class="rev-value">₹ <?= number_format($revenueStats['total_pending'],0) ?></div>
                        <div class="rev-sub">Outstanding amount</div>
                    </div>
                </div>
            </div>

            <!-- ═══ DLC REVENUE TABLE ═══ -->
            <div class="rpt-section">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                Revenue by DLC Office
            </div>
            <div class="rpt-table-wrap">
                <table class="rpt-table">
                    <thead><tr><th>DLC Office</th><th>Location</th><th>ATCs</th><th>Students</th><th>Revenue</th><th>Collected</th><th>Pending</th><th>%</th></tr></thead>
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

            <!-- ═══ TOP ATCs ═══ -->
            <?php if(!empty($topATCs)): ?>
            <div class="rpt-section">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                Top 10 ATC Centers
            </div>
            <div class="rpt-table-wrap">
                <table class="rpt-table">
                    <thead><tr><th>Rank</th><th>ATC Center</th><th>DLC Office</th><th>Students</th><th>Revenue Collected</th></tr></thead>
                    <tbody>
                    <?php foreach($topATCs as $i=>$atc): ?>
                    <tr>
                        <td>
                            <span class="rank-badge <?= $i===0?'rank-1':($i===1?'rank-2':($i===2?'rank-3':'rank-other')) ?>">
                                <?php if($i<3): ?><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg><?php endif; ?>
                                #<?= $i+1 ?>
                            </span>
                        </td>
                        <td style="font-weight:800"><?= htmlspecialchars($atc['atc_name']) ?></td>
                        <td style="color:#6b7280"><?= htmlspecialchars($atc['dlc_name']??'—') ?></td>
                        <td><strong><?= $atc['student_count'] ?></strong></td>
                        <td style="color:#059669;font-weight:700">₹<?= number_format($atc['revenue_collected'],0) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- ═══ MONTHLY TREND CHART ═══ -->
            <?php if(!empty($monthlyTrend)):
                $maxRev = max(array_column($monthlyTrend,'revenue')?:[1]);
            ?>
            <div class="rpt-section">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Monthly Admissions &amp; Revenue (Last 6 Months)
            </div>
            <div class="rpt-table-wrap" style="padding:1.25rem 1.5rem 1rem">
                <div class="chart-wrap">
                    <?php foreach($monthlyTrend as $m):
                        $h = $maxRev>0 ? max(6,round(($m['revenue']/$maxRev)*140)) : 6;
                        $lbl = date('M Y', strtotime($m['month'].'-01'));
                    ?>
                    <div class="chart-col">
                        <div class="chart-bar-box">
                            <div class="chart-bar" style="height:<?= $h ?>px" title="<?= $lbl ?>: ₹<?= number_format($m['revenue'],0) ?>"></div>
                        </div>
                        <div class="chart-mlbl"><?= date('M', strtotime($m['month'].'-01')) ?></div>
                        <div class="chart-msub"><?= $m['admissions'] ?> adm</div>
                    </div>
                    <?php endforeach; ?>
                </div>
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
function sendBdayWish(name, mobile) {
    const msg = encodeURIComponent('Dear ' + name + ',\n\nWarm Birthday Greetings from the entire Gyanam India family!\n\nOn this special occasion, we extend our heartfelt wishes to you. May this new year of your life bring you great health, abundant happiness, and continued success in everything you pursue.\n\nWe are grateful to have you as a valued part of the Gyanam India community. May your day be as wonderful as the joy you bring to everyone around you.\n\nWith warm regards,\nTeam Gyanam India');
    const num = mobile.replace(/\D/g, '');
    window.open('https://wa.me/91' + num + '?text=' + msg, '_blank');
}
</script>
</body>
</html>
