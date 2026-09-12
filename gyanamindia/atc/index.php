<?php
/**
 * Gyanam Portal — ATC Center Dashboard
 * Tasks F + G: Collections filters + Notice Board redesign
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

// ── AJAX: Collection filter (Task F) ───────────────────────────────────────
if (isset($_GET['ajax_collection'])) {
    header('Content-Type: application/json');
    $pdo = getDBConnection();
    $aId = $_SESSION['atc_id'] ?? null;
    $mode = $_GET['mode'] ?? 'all';  // 'all' | 'month' | 'custom'
    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;

    $where = 'a.atc_id = ?';
    $params = [$aId];

    if ($mode === 'month') {
        $where .= ' AND MONTH(fp.payment_date)=MONTH(CURDATE()) AND YEAR(fp.payment_date)=YEAR(CURDATE())';
    } elseif ($mode === 'custom' && $from && $to) {
        $where .= ' AND DATE(fp.payment_date) BETWEEN ? AND ?';
        $params[] = $from;
        $params[] = $to;
    }

    try {
        $s = $pdo->prepare("SELECT COALESCE(SUM(fp.amount),0) FROM fee_payments fp JOIN admissions a ON fp.admission_id=a.id WHERE $where");
        $s->execute($params);
        echo json_encode(['total' => (float) $s->fetchColumn()]);
    } catch (Exception $e) {
        echo json_encode(['total' => 0]);
    }
    exit;
}

// ── AJAX: Today's transactions detail (Task F) ────────────────────────────
if (isset($_GET['ajax_transactions'])) {
    header('Content-Type: application/json');
    $pdo = getDBConnection();
    $aId = $_SESSION['atc_id'] ?? null;
    $date = $_GET['date'] ?? date('Y-m-d');
    try {
        $s = $pdo->prepare("
            SELECT fp.amount, fp.payment_mode, fp.payment_date,
                   CONCAT(a.first_name,' ',a.last_name) AS student_name,
                   a.roll_no, a.course
            FROM fee_payments fp
            JOIN admissions a ON fp.admission_id = a.id
            WHERE a.atc_id = ? AND DATE(fp.payment_date) = ?
            ORDER BY fp.payment_date DESC
        ");
        $s->execute([$aId, $date]);
        echo json_encode(['rows' => $s->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Exception $e) {
        echo json_encode(['rows' => []]);
    }
    exit;
}

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$greeting = getGreeting();
$atcId = $_SESSION['atc_id'] ?? null;
try { ensurePerformanceIndexes($pdo); } catch (Exception $e) {}

/* ── EXISTING Stats ────────────────────────────────── */
$totalInquiries = $totalTelephonic = $totalAdmissions = $convertedInquiries = 0;
$totalFees = $paidFees = $pendingFees = $collectionPercentage = 0;

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inquiries WHERE atc_id = ?");
    $stmt->execute([$atcId]);
    $totalInquiries = (int) $stmt->fetchColumn();
} catch (Exception $e) {
}
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM telephonic_inquiries WHERE atc_id = ?");
    $stmt->execute([$atcId]);
    $totalTelephonic = (int) $stmt->fetchColumn();
} catch (Exception $e) {
}
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE atc_id = ?");
    $stmt->execute([$atcId]);
    $totalAdmissions = (int) $stmt->fetchColumn();
} catch (Exception $e) {
}
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inquiries WHERE atc_id = ? AND status = 'Converted'");
    $stmt->execute([$atcId]);
    $convertedInquiries = (int) $stmt->fetchColumn();
} catch (Exception $e) {
}
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(COALESCE(fees_total, net_payable, course_fees)),0) as total_fees,
               COALESCE(SUM(fees_paid),0) as paid_fees,
               COALESCE(SUM(fees_pending),0) as pending_fees
        FROM admissions WHERE atc_id = ? AND status = 'Active'
    ");
    $stmt->execute([$atcId]);
    $f = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalFees = $f['total_fees'] ?? 0;
    $paidFees = $f['paid_fees'] ?? 0;
    $pendingFees = $f['pending_fees'] ?? 0;
    $collectionPercentage = $totalFees > 0 ? round(($paidFees / $totalFees) * 100, 1) : 0;
} catch (Exception $e) {
}

/* ── NEW Extra Stats ───────────────────────────────── */
$grandTotalCollected = 0;
$todayCash = 0;
$todayOnline = 0;
try {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(fp.amount),0) FROM fee_payments fp JOIN admissions a ON fp.admission_id = a.id WHERE a.atc_id = ?");
    $stmt->execute([$atcId]);
    $grandTotalCollected = (float) $stmt->fetchColumn();
    $stmt = $pdo->prepare("
        SELECT payment_mode, COALESCE(SUM(fp.amount),0) as total
        FROM fee_payments fp JOIN admissions a ON fp.admission_id = a.id
        WHERE a.atc_id = ? AND DATE(fp.payment_date) = CURDATE()
        GROUP BY payment_mode
    ");
    $stmt->execute([$atcId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['payment_mode'] === 'Cash')
            $todayCash = (float) $r['total'];
        if ($r['payment_mode'] === 'Online')
            $todayOnline = (float) $r['total'];
    }
} catch (Exception $e) {
}

$totalCourses = $activeCourses = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) as total, SUM(status='Active') as active FROM courses WHERE atc_id = ?");
    $stmt->execute([$atcId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    $totalCourses = (int) ($r['total'] ?? 0);
    $activeCourses = (int) ($r['active'] ?? 0);
} catch (Exception $e) {
}

$certsDistributed = $certsPending = 0;
try {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM certificates WHERE atc_id = ? GROUP BY status");
    $stmt->execute([$atcId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
        if ($c['status'] === 'Issued')
            $certsDistributed = (int) $c['cnt'];
        if ($c['status'] === 'Pending')
            $certsPending = (int) $c['cnt'];
    }
} catch (Exception $e) {
}

/* ── Exam Tracking (Task D) ───────────────────────────────── */
$totalExams = $pendingExams = $conductedExams = 0;
$examStudentsAll = $examStudentsPending = $examStudentsConducted = [];
try {
    // Total exams for this ATC
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_schedules WHERE atc_id = ?");
    $stmt->execute([$atcId]);
    $totalExams = (int) $stmt->fetchColumn();

    // Pending = exam_date >= today (future or today)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM exam_schedules WHERE atc_id = ? AND exam_date >= CURDATE()");
    $stmt->execute([$atcId]);
    $pendingExams = (int) $stmt->fetchColumn();

    // Conducted = exam_date < today (past)
    $conductedExams = $totalExams - $pendingExams;

    // Fetch student details for each category (with photo)
    $examBaseSQL = "
        SELECT a.id, CONCAT(a.first_name,' ',COALESCE(a.middle_name,''),' ',a.last_name) AS student_name,
               a.course, a.roll_no, a.photo,
               es.exam_date, es.exam_time, es.exam_hall
        FROM exam_schedules es
        JOIN admissions a ON a.id = es.admission_id
        WHERE es.atc_id = ?
    ";

    $s1 = $pdo->prepare($examBaseSQL . ' ORDER BY es.exam_date DESC LIMIT 50');
    $s1->execute([$atcId]);
    $examStudentsAll = $s1->fetchAll(PDO::FETCH_ASSOC);

    $s2 = $pdo->prepare($examBaseSQL . " AND es.exam_date >= CURDATE() ORDER BY es.exam_date ASC LIMIT 50");
    $s2->execute([$atcId]);
    $examStudentsPending = $s2->fetchAll(PDO::FETCH_ASSOC);

    $s3 = $pdo->prepare($examBaseSQL . " AND es.exam_date < CURDATE() ORDER BY es.exam_date DESC LIMIT 50");
    $s3->execute([$atcId]);
    $examStudentsConducted = $s3->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

$totalStudents = $activeStudents = $activePaid = $activeUnpaid = 0;
try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_students,
            SUM(status = 'Active') AS active_students,
            SUM(status = 'Active' AND COALESCE(fees_pending, 0) <= 0) AS active_paid,
            SUM(status = 'Active' AND COALESCE(fees_pending, 0) > 0) AS active_unpaid
        FROM admissions
        WHERE atc_id = ?
    ");
    $stmt->execute([$atcId]);
    $agg = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $totalStudents  = (int)($agg['total_students'] ?? 0);
    $activeStudents = (int)($agg['active_students'] ?? 0);
    $activePaid     = (int)($agg['active_paid'] ?? 0);
    $activeUnpaid   = (int)($agg['active_unpaid'] ?? 0);
} catch (Exception $e) {
}

$recentInquiries = [];
try {
    $stmt = $pdo->prepare("
        SELECT CONCAT(first_name,' ',COALESCE(last_name,'')) AS name,
               COALESCE(interested_course, '') AS course_interested,
               created_at, status
        FROM inquiries WHERE atc_id = ?
        ORDER BY created_at DESC LIMIT 6
    ");
    $stmt->execute([$atcId]);
    $recentInquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// Popular courses — use 'course' column (actual DB column name)
$popularCourses = [];
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(course_name, course, 'Unknown') as cname, COUNT(*) as cnt
        FROM admissions WHERE atc_id = ?
        GROUP BY cname ORDER BY cnt DESC LIMIT 5
    ");
    $stmt->execute([$atcId]);
    $popularCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    try {
        $stmt = $pdo->prepare("SELECT course as cname, COUNT(*) as cnt FROM admissions WHERE atc_id = ? GROUP BY course ORDER BY cnt DESC LIMIT 5");
        $stmt->execute([$atcId]);
        $popularCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e2) {
    }
}

$monthlyLabels = [];
$monthlyData = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(admission_date,'%b %Y') as month,
               DATE_FORMAT(admission_date,'%Y-%m') as sk, COUNT(*) as cnt
        FROM admissions WHERE atc_id = ? AND admission_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY sk, month ORDER BY sk ASC
    ");
    $stmt->execute([$atcId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $monthlyLabels[] = $r['month'];
        $monthlyData[] = (int) $r['cnt'];
    }
} catch (Exception $e) {
}

$revenueLabels = [];
$revenueData = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(fp.payment_date,'%b %Y') as month,
               DATE_FORMAT(fp.payment_date,'%Y-%m') as sk,
               COALESCE(SUM(fp.amount),0) as total
        FROM fee_payments fp JOIN admissions a ON fp.admission_id = a.id
        WHERE a.atc_id = ? AND fp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY sk, month ORDER BY sk ASC
    ");
    $stmt->execute([$atcId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $revenueLabels[] = $r['month'];
        $revenueData[] = (float) $r['total'];
    }
} catch (Exception $e) {
}

$birthdays = [];
try {
    $stmt = $pdo->prepare("SELECT CONCAT(first_name,' ',COALESCE(middle_name,''),' ',last_name) as name, dob, course, IFNULL(mobile,'') as mobile FROM admissions WHERE atc_id = ? AND status='Active' AND MONTH(dob)=MONTH(CURDATE()) AND DAY(dob)=DAY(CURDATE()) ORDER BY first_name ASC");
    $stmt->execute([$atcId]);
    $birthdays = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

$tickerNotifs = [];
try {
    $tickerNotifs = getNotificationsForUser($pdo, getUserId(), getUserRole(), null, $atcId, 5);
} catch (Exception $e) {
}

$activeBanners = [];
try {
    $activeBanners = getActiveAnnouncements($pdo, 'ATC', 8, (int)$atcId);
} catch (Exception $e) {
}

/* ── Task E: Reported Students / Pending Reports (HO Share) ──────────────── */
$reportedStudents = [];
$pendingReportStudents = [];
$reportedCount = 0;
$pendingReportCount = 0;
try {
    $paidIds = getHoSharePaidAdmissionIds($pdo, (int)$atcId);

    // Preview lists for modal (cap at 200) — counts use separate queries
    $allRows = $pdo->prepare("
        SELECT id, CONCAT(first_name,' ',COALESCE(middle_name,''),' ',last_name) AS name,
               course, roll_no, photo
        FROM admissions WHERE atc_id = ? AND status = 'Active'
        ORDER BY first_name ASC
        LIMIT 200
    ");
    $allRows->execute([$atcId]);
    foreach ($allRows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($paidIds[(int)$row['id']])) {
            $reportedStudents[] = $row;
        } else {
            $pendingReportStudents[] = $row;
        }
    }

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE atc_id = ? AND status = 'Active'");
    $cntStmt->execute([$atcId]);
    $totalActive = (int)$cntStmt->fetchColumn();

    if (!empty($paidIds)) {
        $idList = array_keys($paidIds);
        $placeholders = implode(',', array_fill(0, count($idList), '?'));
        $params = array_merge([(int)$atcId], $idList);
        $rStmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE atc_id = ? AND status = 'Active' AND id IN ($placeholders)");
        $rStmt->execute($params);
        $reportedCount = (int)$rStmt->fetchColumn();
    } else {
        $reportedCount = 0;
    }
    $pendingReportCount = max(0, $totalActive - $reportedCount);
} catch (Exception $e) {
    $reportedStudents = [];
    $pendingReportStudents = [];
    $reportedCount = 0;
    $pendingReportCount = 0;
}

/* ── Dashboard Widget Data ────────────────────────────────── */
$recentEnrollments = [];
try {
    $stmt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',COALESCE(middle_name,''),' ',last_name) AS name, course, admission_date, photo, roll_no FROM admissions WHERE atc_id = ? ORDER BY admission_date DESC, id DESC LIMIT 5");
    $stmt->execute([$atcId]);
    $recentEnrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

$pendingApprovals = [];
$pendingApprovalCount = 0;
try {
    $stmt = $pdo->prepare("SELECT cr.id, cr.field_label, cr.new_value, cr.requested_at, CONCAT(a.first_name,' ',a.last_name) AS student_name, a.roll_no FROM change_requests cr JOIN admissions a ON cr.admission_id = a.id WHERE cr.atc_id = ? AND cr.status = 'Pending' ORDER BY cr.requested_at DESC LIMIT 5");
    $stmt->execute([$atcId]);
    $pendingApprovals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pendingApprovalCount = count($pendingApprovals);
} catch (Exception $e) {
}

$upcomingDueFees = [];
try {
    $stmt = $pdo->prepare("SELECT id, CONCAT(first_name,' ',COALESCE(middle_name,''),' ',last_name) AS name, course, photo, roll_no, COALESCE(net_payable, fees_total, 0) AS net_payable, COALESCE(fees_paid, 0) AS fees_paid, COALESCE(fees_pending, 0) AS fees_pending FROM admissions WHERE atc_id = ? AND status = 'Active' AND COALESCE(fees_pending, 0) > 0 ORDER BY fees_pending DESC LIMIT 5");
    $stmt->execute([$atcId]);
    $upcomingDueFees = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// ── ClassChakra dashboard extras ───────────────────────────────────────────
$todayAdmissions = 0;
$todayInquiries = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM admissions WHERE atc_id = ? AND admission_date = CURDATE()");
    $stmt->execute([$atcId]);
    $todayAdmissions = (int)$stmt->fetchColumn();
} catch (Exception $e) {}
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM inquiries WHERE atc_id = ? AND DATE(created_at) = CURDATE()");
    $stmt->execute([$atcId]);
    $todayInquiries = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM telephonic_inquiries WHERE atc_id = ? AND DATE(created_at) = CURDATE()");
    $stmt->execute([$atcId]);
    $todayInquiries += (int)$stmt->fetchColumn();
} catch (Exception $e) {}

$openEnquiries = max(0, ($totalInquiries + $totalTelephonic) - $convertedInquiries);
$certsTotal = $certsDistributed + $certsPending;
$certsPct = $certsTotal > 0 ? (int)round(($certsDistributed / $certsTotal) * 100) : 0;

// Fix courses count via ATC fee structure when master courses aren't ATC-scoped
if ($totalCourses === 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT c.id) AS total,
                   SUM(CASE WHEN c.status = 'Active' AND (COALESCE(acf.fee_with_material,0)>0 OR COALESCE(acf.fee_without_material,0)>0 OR COALESCE(acf.final_fee,0)>0) THEN 1 ELSE 0 END) AS active
            FROM courses c
            INNER JOIN atc_course_fees acf ON acf.course_id = c.id AND acf.atc_id = ?
        ");
        $stmt->execute([$atcId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $totalCourses = (int)($r['total'] ?? 0);
        $activeCourses = (int)($r['active'] ?? 0);
    } catch (Exception $e) {}
}

$recentPayments = [];
try {
    $stmt = $pdo->prepare("
        SELECT fp.id, fp.amount, fp.payment_mode, fp.payment_date, fp.receipt_no,
               CONCAT(a.first_name,' ',COALESCE(a.last_name,'')) AS student_name,
               a.roll_no, a.course
        FROM fee_payments fp
        JOIN admissions a ON a.id = fp.admission_id
        WHERE a.atc_id = ?
        ORDER BY fp.payment_date DESC, fp.id DESC
        LIMIT 8
    ");
    $stmt->execute([$atcId]);
    $recentPayments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$recentExamsDash = array_slice($examStudentsConducted ?: $examStudentsAll, 0, 6);

$popularEnquiryCourses = [];
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(interested_course),''),'Unknown') AS cname, COUNT(*) AS cnt
        FROM inquiries WHERE atc_id = ?
        GROUP BY cname ORDER BY cnt DESC LIMIT 6
    ");
    $stmt->execute([$atcId]);
    $popularEnquiryCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

// Prefer richer recent inquiry rows (already loaded above)
try {
    if (empty($recentInquiries)) {
        $stmt = $pdo->prepare("
            SELECT CONCAT(first_name,' ',COALESCE(last_name,'')) AS name,
                   COALESCE(interested_course, '') AS course_interested,
                   created_at, status
            FROM inquiries WHERE atc_id = ?
            ORDER BY created_at DESC LIMIT 6
        ");
        $stmt->execute([$atcId]);
        $recentInquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {}

// Monthly trend series — always build 12 months; UI toggles 6 vs 12
$monthlyLabels12 = [];
$monthlyData12 = [];
$revenueData12 = [];
$monthlyLabels = [];
$monthlyData = [];
$revenueLabels = [];
$revenueData = [];
try {
    $mapAdm = [];
    $mapRev = [];
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(admission_date,'%Y-%m') AS sk, COUNT(*) AS cnt
        FROM admissions WHERE atc_id = ? AND admission_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
        GROUP BY sk
    ");
    $stmt->execute([$atcId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $mapAdm[$r['sk']] = (int)$r['cnt'];
    }
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(fp.payment_date,'%Y-%m') AS sk, COALESCE(SUM(fp.amount),0) AS total
        FROM fee_payments fp JOIN admissions a ON fp.admission_id = a.id
        WHERE a.atc_id = ? AND fp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
        GROUP BY sk
    ");
    $stmt->execute([$atcId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $mapRev[$r['sk']] = (float)$r['total'];
    }
    for ($i = 11; $i >= 0; $i--) {
        $sk = date('Y-m', strtotime("-{$i} months"));
        $label = date('M Y', strtotime($sk . '-01'));
        $monthlyLabels12[] = $label;
        $monthlyData12[] = $mapAdm[$sk] ?? 0;
        $revenueData12[] = $mapRev[$sk] ?? 0;
    }
    // Default view: last 6 months
    $monthlyLabels = array_slice($monthlyLabels12, -6);
    $monthlyData = array_slice($monthlyData12, -6);
    $revenueLabels = $monthlyLabels;
    $revenueData = array_slice($revenueData12, -6);
} catch (Exception $e) {}

// Course mix for pie / bar charts
$chartByCourse = [];
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(NULLIF(TRIM(course), ''), 'Other') AS label,
               COUNT(*) AS students,
               COALESCE(SUM(CASE WHEN status = 'Active' THEN fees_paid ELSE 0 END), 0) AS collected
        FROM admissions
        WHERE atc_id = ?
        GROUP BY label
        ORDER BY students DESC
        LIMIT 8
    ");
    $stmt->execute([$atcId]);
    $chartByCourse = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) {
    $chartByCourse = [];
}

// Fee payment status pie fallback data
$chartFeeStatus = ['Paid' => 0, 'Partial' => 0, 'Pending' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN fees_pending <= 0 AND fees_paid > 0 THEN 1 ELSE 0 END) AS paid,
            SUM(CASE WHEN fees_paid > 0 AND fees_pending > 0 THEN 1 ELSE 0 END) AS partial,
            SUM(CASE WHEN COALESCE(fees_paid, 0) <= 0 THEN 1 ELSE 0 END) AS pending
        FROM admissions
        WHERE atc_id = ? AND status = 'Active'
    ");
    $stmt->execute([$atcId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $chartFeeStatus = [
        'Paid' => (int)($row['paid'] ?? 0),
        'Partial' => (int)($row['partial'] ?? 0),
        'Pending' => (int)($row['pending'] ?? 0),
    ];
} catch (Exception $e) {}

$conversionRate = $totalInquiries > 0 ? round(($convertedInquiries / $totalInquiries) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — ATC Center | Gyanam India</title>
    <?php include __DIR__ . '/../includes/head_fonts.php'; ?>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="stylesheet" href="../assets/css/atc-dash-cc.css">
    <link rel="icon"
        href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📚</text></svg>">
    <style>
        /* ── Section Headers ── */
        .dash-section-label {
            font-size: 0.68rem;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--text-muted);
            margin: 2rem 0 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        /* ── News banner ── */
        .news-banner {
            background: linear-gradient(135deg, #eff6ff, #f0f9ff);
            border: 1px solid #bfdbfe;
            border-left: 4px solid #2563eb;
            border-radius: var(--radius-lg);
            padding: 0.75rem 1.1rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: flex-start;
            gap: 0.85rem;
        }

        .news-banner-label {
            background: #2563eb;
            color: #fff;
            font-size: 0.65rem;
            font-weight: 800;
            letter-spacing: 1px;
            text-transform: uppercase;
            padding: 0.2rem 0.55rem;
            border-radius: var(--radius-sm);
            white-space: nowrap;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .news-items {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
            flex: 1;
        }

        .news-item {
            font-size: 0.82rem;
            color: var(--text-primary);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .news-item::before {
            content: '•';
            color: #2563eb;
            font-size: 1rem;
            line-height: 1;
        }

        .news-item .news-time {
            font-size: 0.7rem;
            color: var(--text-muted);
            font-weight: 500;
            margin-left: auto;
        }

        /* ── Notice Board Cards (Task G) ── */
        .nb-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .nb-card {
            background: #fff;
            border: 1.5px solid var(--border-color, #e5e7eb);
            border-radius: 16px;
            padding: 1.1rem 1.25rem;
            display: flex;
            flex-direction: column;
            gap: .6rem;
            transition: box-shadow .2s;
        }

        .nb-card:hover {
            box-shadow: 0 6px 24px rgba(0, 0, 0, .09);
        }

        .nb-urgent {
            border-color: #fca5a5;
            background: linear-gradient(135deg, #fff5f5, #fff);
        }

        .nb-card-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .nb-badge {
            font-size: .68rem;
            font-weight: 800;
            padding: .2rem .6rem;
            border-radius: 999px;
        }

        .nb-badge-regular {
            background: #eff6ff;
            color: #1d4ed8;
        }

        .nb-badge-urgent {
            background: #fee2e2;
            color: #b91c1c;
            animation: pulse2 1.8s infinite;
        }

        @keyframes pulse2 {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: .6
            }
        }

        .nb-date {
            font-size: .7rem;
            color: var(--text-muted, #9ca3af);
        }

        .nb-title {
            font-size: .9rem;
            font-weight: 800;
            color: var(--text-primary, #111827);
            line-height: 1.3;
        }

        .nb-preview {
            font-size: .8rem;
            color: var(--text-secondary, #4b5563);
            line-height: 1.5;
        }

        .nb-full {
            font-size: .8rem;
            color: var(--text-secondary, #4b5563);
            line-height: 1.6;
        }

        .nb-toggle {
            border: none;
            background: none;
            color: #4361ee;
            font-size: .75rem;
            font-weight: 700;
            cursor: pointer;
            padding: 0;
            margin-top: .2rem;
            text-align: left;
        }

        .nb-toggle:hover {
            text-decoration: underline;
        }

        /* ── Transaction Modal (Task F) ── */
        .trans-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, .45);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1100;
            display: none;
        }

        .trans-modal-overlay.open {
            display: flex;
        }

        .trans-modal {
            background: #fff;
            border-radius: 20px;
            width: min(680px, 95vw);
            max-height: 88vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: slideUp .28s ease;
        }

        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0
            }

            to {
                transform: none;
                opacity: 1
            }
        }

        .trans-modal-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1.5px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            color: #fff;
            border-radius: 20px 20px 0 0;
        }

        .trans-modal-header h3 {
            margin: 0;
            font-size: 1rem;
            font-weight: 800;
        }

        .trans-modal-body {
            overflow-y: auto;
            flex: 1;
            padding: .5rem 0;
        }

        .trans-modal-footer {
            padding: 1rem 1.5rem;
            border-top: 1.5px solid #e5e7eb;
            text-align: right;
        }

        .birthday-banner {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: linear-gradient(135deg, #fff7ed, #ffedd5);
            border: 1px solid #fed7aa;
            border-left: 4px solid #f97316;
            border-radius: var(--radius-lg);
            padding: 0.7rem 1.1rem;
            margin-bottom: 0.5rem;
            font-size: 0.84rem;
        }

        .birthday-banner strong {
            color: var(--text-primary);
            display: block;
            font-size: 0.88rem;
        }

        .birthday-banner span {
            color: var(--text-muted);
            font-size: 0.73rem;
        }

        /* Birthday Panel (new) */
        .atc-bday-panel {
            background: #fff;
            border: 1px solid var(--border-color, #e5e7eb);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 2px 12px rgba(0, 0, 0, .05);
            margin-bottom: 1.5rem;
        }

        .atc-bday-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: .875rem 1.25rem;
            border-bottom: 1px solid var(--border-color, #e5e7eb);
            background: #fffbeb;
        }

        .atc-bday-title {
            display: flex;
            align-items: center;
            gap: .5rem;
            font-weight: 700;
            font-size: .9rem;
            color: #92400e;
        }

        .atc-bday-count {
            font-size: .78rem;
            color: #d97706;
            font-weight: 600;
        }

        .atc-bday-body {
            padding: .25rem 0;
        }

        .atc-bday-row {
            display: flex;
            align-items: center;
            gap: .875rem;
            padding: .625rem 1.25rem;
            border-bottom: 1px solid #fef3c7;
            transition: background .15s;
        }

        .atc-bday-row:last-child {
            border-bottom: none;
        }

        .atc-bday-row:hover {
            background: #fffbeb;
        }

        .atc-bday-avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            flex-shrink: 0;
            background: linear-gradient(135deg, #f97316, #ef4444);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .9rem;
            font-weight: 800;
        }

        .atc-bday-name {
            font-weight: 700;
            font-size: .85rem;
            color: var(--text-primary, #111827);
        }

        .atc-bday-meta {
            font-size: .72rem;
            color: var(--text-muted, #9ca3af);
            margin-top: .1rem;
        }

        /* ── Extended stat-card colors ── */
        .stat-card.teal::before {
            background: linear-gradient(90deg, #14b8a6, #06b6d4);
        }

        .stat-card.teal::after {
            background: radial-gradient(circle, #14b8a6, transparent 60%);
        }

        .stat-card.teal .stat-icon {
            background: linear-gradient(135deg, #ccfbf1, rgba(6, 182, 212, 0.08));
            color: #0d9488;
        }

        .stat-card.red::before {
            background: linear-gradient(90deg, #ef4444, #f43f5e);
        }

        .stat-card.red::after {
            background: radial-gradient(circle, #ef4444, transparent 60%);
        }

        .stat-card.red .stat-icon {
            background: linear-gradient(135deg, #fee2e2, rgba(244, 63, 94, 0.06));
            color: #dc2626;
        }

        .stat-card.indigo::before {
            background: linear-gradient(90deg, #6366f1, #8b5cf6);
        }

        .stat-card.indigo::after {
            background: radial-gradient(circle, #6366f1, transparent 60%);
        }

        .stat-card.indigo .stat-icon {
            background: linear-gradient(135deg, #e0e7ff, rgba(139, 92, 246, 0.08));
            color: #4f46e5;
        }

        .stat-card.orange::before {
            background: linear-gradient(90deg, #f97316, #fb923c);
        }

        .stat-card.orange::after {
            background: radial-gradient(circle, #f97316, transparent 60%);
        }

        .stat-card.orange .stat-icon {
            background: linear-gradient(135deg, #ffedd5, rgba(249, 115, 22, 0.08));
            color: #ea580c;
        }

        /* ── Exam card clickable ── */
        .stat-card.clickable {
            cursor: pointer;
        }

        .stat-card.clickable:hover {
            transform: translateY(-4px) !important;
            box-shadow: 0 14px 36px rgba(0, 0, 0, .12) !important;
        }

        /* ── Exam Modal ── */
        #examModal .modal-card {
            max-width: 760px;
        }

        .modal-header-icon {
            width: 18px !important;
            height: 18px !important;
            flex-shrink: 0;
        }

        .modal-header h3 svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
            vertical-align: middle;
        }

        .exam-modal-students {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1rem;
            padding: 1.25rem 1.5rem;
            max-height: 60vh;
            overflow-y: auto;
        }

        /* HO modal uses table layout */
        .ho-modal-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .84rem;
        }

        .ho-modal-table thead th {
            background: #f8fafc;
            padding: .7rem 1rem;
            font-size: .72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .06em;
            color: #6b7280;
            border-bottom: 2px solid #e5e7eb;
            text-align: left;
        }

        .ho-modal-table tbody td {
            padding: .7rem 1rem;
            border-bottom: 1px solid #f3f4f6;
            color: #374151;
            vertical-align: middle;
        }

        .ho-modal-table tbody tr:last-child td {
            border-bottom: none;
        }

        .ho-modal-table tbody tr:hover td {
            background: #f9fafb;
        }

        .ho-status-badge {
            display: inline-block;
            font-size: .68rem;
            font-weight: 800;
            padding: .2rem .6rem;
            border-radius: 999px;
        }

        .ho-status-reported {
            color: #059669;
            background: #d1fae5;
        }

        .ho-status-pending {
            color: #d97706;
            background: #fef3c7;
        }

        .ho-modal-wrap {
            padding: 0 1.5rem 1.25rem;
            max-height: 60vh;
            overflow-y: auto;
        }

        .exam-student-photo {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--border-color);
        }

        .exam-student-avatar {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: linear-gradient(135deg, #4361ee, #8b5cf6);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            font-weight: 800;
            color: #fff;
        }

        .exam-student-name {
            font-size: .82rem;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1.3;
        }

        .exam-student-meta {
            font-size: .72rem;
            color: var(--text-muted);
        }

        .exam-student-card {
            background: var(--gray-50);
            border: 1.5px solid var(--border-color);
            border-radius: 14px;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: .6rem;
            text-align: center;
        }

        .exam-student-date {
            font-size: .72rem;
            font-weight: 700;
            color: #4361ee;
            background: #eef2ff;
            padding: .2rem .6rem;
            border-radius: 999px;
        }

        /* ── stat-sub (extra info below stat-value) ── */
        .stat-sub {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
            margin-top: 0.5rem;
        }

        .stat-sub-item {
            font-size: 0.72rem;
            font-weight: 600;
            color: var(--text-muted);
            display: flex;
            align-items: center;
            gap: 0.3rem;
        }

        .stat-sub-item .dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            flex-shrink: 0;
        }

        .dot-green {
            background: #10b981;
        }

        .dot-red {
            background: #ef4444;
        }

        .dot-blue {
            background: #3b82f6;
        }

        .dot-gray {
            background: #9ca3af;
        }

        /* ── Global layout for new sections ── */
        .stats-grid-6 {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1rem;
        }

        /* ── Two-col widget layout ── */
        .dash-cols {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.25rem;
            margin-top: 0;
        }

        @media(max-width:860px) {
            .dash-cols {
                grid-template-columns: 1fr;
            }
        }

        /* ── Widget card ── */
        .widget-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-xl);
            padding: 1.25rem 1.5rem;
            animation: cardSlideUp .4s var(--ease-spring) both;
        }

        .widget-title {
            font-size: 0.82rem;
            font-weight: 800;
            color: var(--text-primary);
            text-transform: uppercase;
            letter-spacing: 0.04em;
            margin-bottom: 1rem;
            padding-bottom: 0.7rem;
            border-bottom: 1px solid var(--border-color);
        }

        /* ── Mini table ── */
        .mini-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.81rem;
        }

        .mini-table th {
            text-align: left;
            padding: 0.35rem 0.5rem;
            font-size: 0.65rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: var(--text-muted);
            border-bottom: 1px solid var(--border-color);
        }

        .mini-table td {
            padding: 0.6rem 0.5rem;
            color: var(--text-secondary);
            border-bottom: 1px solid var(--gray-50);
        }

        .mini-table tr:last-child td {
            border-bottom: none;
        }

        .mini-table tr:hover td {
            background: var(--gray-50);
        }

        /* ── Mini Calendar ── */
        .cal-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 3px;
            margin-top: 0.5rem;
        }

        .cal-day-name {
            text-align: center;
            font-size: 0.62rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            padding: 0.15rem 0;
        }

        .cal-day {
            text-align: center;
            padding: 0.3rem 0;
            font-size: 0.77rem;
            border-radius: var(--radius-sm);
            color: var(--text-secondary);
            font-weight: 500;
        }

        .cal-day.today {
            background: var(--primary-500);
            color: #fff;
            font-weight: 800;
            border-radius: var(--radius-md);
        }

        .cal-day.empty {
            opacity: 0;
            pointer-events: none;
        }

        /* ── Fees Statistics (existing style preserved) ── */
        .fees-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.25rem;
        }

        .fees-card {
            background: var(--bg-surface);
            border-radius: var(--radius-xl);
            padding: 1.4rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            border: 1.5px solid var(--border-color);
            transition: all .3s ease;
            position: relative;
            overflow: hidden;
        }

        .fees-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--ca), transparent);
            opacity: 0;
            transition: opacity .3s;
        }

        .fees-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, .08);
            border-color: var(--ca);
        }

        .fees-card:hover::before {
            opacity: 1;
        }

        .fees-card.fc-total {
            --ca: #6366f1;
        }

        .fees-card.fc-col {
            --ca: #10b981;
        }

        .fees-card.fc-pend {
            --ca: #f59e0b;
        }

        .fees-card.fc-prog {
            --ca: #8b5cf6;
            grid-column: 1/-1;
        }

        .fees-icon {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .fees-card.fc-total .fees-icon {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
        }

        .fees-card.fc-col .fees-icon {
            background: linear-gradient(135deg, #10b981, #059669);
        }

        .fees-card.fc-pend .fees-icon {
            background: linear-gradient(135deg, #f59e0b, #d97706);
        }

        .fees-icon svg {
            width: 20px;
            height: 20px;
            stroke: white;
        }

        .fees-info {
            flex: 1;
        }

        .fees-label {
            font-size: .72rem;
            font-weight: 700;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: .3rem;
        }

        .fees-value {
            font-size: 1.6rem;
            font-weight: 800;
            color: var(--text-primary);
            line-height: 1.2;
        }

        .fees-subtitle {
            font-size: .78rem;
            color: var(--text-secondary);
            margin-top: .2rem;
        }

        .collection-badge {
            display: inline-block;
            padding: .2rem .6rem;
            background: linear-gradient(135deg, #10b981, #059669);
            color: #fff;
            border-radius: var(--radius-full);
            font-weight: 700;
            font-size: .7rem;
        }

        .progress-bar-container {
            width: 100%;
            height: 26px;
            background: var(--gray-200);
            border-radius: var(--radius-full);
            overflow: hidden;
            margin: 0.85rem 0 .4rem;
        }

        .progress-bar {
            height: 100%;
            background: linear-gradient(90deg, #8b5cf6, #7c3aed);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: .7rem;
            transition: width 1s ease;
            overflow: hidden;
            position: relative;
        }

        .progress-bar::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, .3), transparent);
            animation: shimmer 2s infinite;
        }

        @keyframes shimmer {
            0% {
                transform: translateX(-100%)
            }

            100% {
                transform: translateX(100%)
            }
        }

        .progress-text {
            color: #fff;
            font-weight: 700;
            font-size: .78rem;
            position: relative;
            z-index: 1;
        }

        .progress-details {
            font-size: .78rem;
            color: var(--text-secondary);
            font-weight: 600;
        }

        .no-data {
            text-align: center;
            color: var(--text-muted);
            font-size: .81rem;
            padding: 1.25rem 0;
        }

        /* ── Dashboard KPI Strip ── */
        .dash-kpi-strip {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
            margin-bottom: .5rem
        }

        .dash-kpi {
            background: #fff;
            border: 1.5px solid var(--border-color, #e6eaf3);
            border-radius: 18px;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 1px 4px rgba(0, 0, 0, .06), 0 2px 8px rgba(0, 0, 0, .04);
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            animation: kpiFadeIn .5s ease both;
            transition: transform .2s, box-shadow .2s
        }

        .dash-kpi:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, .09)
        }

        .dash-kpi-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center
        }

        .dash-kpi-icon svg {
            width: 22px;
            height: 22px
        }

        .dash-kpi-label {
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--text-muted, #6b7280);
            margin-bottom: .3rem
        }

        .dash-kpi-value {
            font-size: 1.5rem;
            font-weight: 800;
            color: var(--text-primary, #111827);
            line-height: 1;
            font-family: 'JetBrains Mono', monospace
        }

        .dash-kpi-sub {
            font-size: .73rem;
            color: var(--text-muted, #6b7280);
            margin-top: .3rem;
            font-weight: 500
        }

        @keyframes kpiFadeIn {
            from {
                opacity: 0;
                transform: translateY(16px)
            }

            to {
                opacity: 1;
                transform: translateY(0)
            }
        }

        @media(max-width:1100px) {
            .dash-kpi-strip {
                grid-template-columns: repeat(2, minmax(0, 1fr))
            }
        }

        @media(max-width:480px) {
            .dash-kpi-strip {
                grid-template-columns: 1fr
            }
        }

        /* ── Insights Widget Panels ── */
        .dash-widget-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.25rem
        }

        @media(max-width:1100px) {
            .dash-widget-grid {
                grid-template-columns: 1fr 1fr
            }
        }

        @media(max-width:680px) {
            .dash-widget-grid {
                grid-template-columns: 1fr
            }
        }

        .dash-widget {
            background: #fff;
            border: 1.5px solid var(--border-color, #e6eaf3);
            border-radius: 18px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0, 0, 0, .06);
            animation: kpiFadeIn .5s ease both
        }

        .dash-widget-head {
            padding: 1rem 1.25rem;
            border-bottom: 1.5px solid var(--border-color, #e6eaf3);
            display: flex;
            align-items: center;
            justify-content: space-between
        }

        .dash-widget-title {
            font-size: .82rem;
            font-weight: 800;
            color: var(--text-primary, #111827);
            display: flex;
            align-items: center;
            gap: .5rem
        }

        .dash-widget-title svg {
            width: 16px;
            height: 16px
        }

        .dash-widget-badge {
            font-size: .68rem;
            font-weight: 700;
            padding: .2rem .6rem;
            border-radius: 999px
        }

        .dash-widget-body {
            padding: 0;
            max-height: 320px;
            overflow-y: auto
        }

        .dash-widget-row {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .75rem 1.25rem;
            border-bottom: 1px solid #f3f4f6;
            transition: background .15s
        }

        .dash-widget-row:last-child {
            border-bottom: none
        }

        .dash-widget-row:hover {
            background: #f8faff
        }

        .dash-widget-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: .85rem;
            color: #fff;
            background: linear-gradient(135deg, #6366f1, #8b5cf6);
            overflow: hidden
        }

        .dash-widget-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover
        }

        .dash-widget-name {
            font-size: .84rem;
            font-weight: 700;
            color: var(--text-primary, #111827)
        }

        .dash-widget-meta {
            font-size: .72rem;
            color: var(--text-muted, #9ca3af);
            margin-top: .1rem
        }

        .dash-widget-right {
            margin-left: auto;
            text-align: right;
            flex-shrink: 0
        }

        .dash-widget-amount {
            font-family: 'JetBrains Mono', monospace;
            font-size: .82rem;
            font-weight: 700
        }

        .dash-widget-empty {
            text-align: center;
            color: var(--text-muted, #9ca3af);
            font-size: .82rem;
            padding: 2rem 1rem
        }

        .dash-widget-footer {
            padding: .75rem 1.25rem;
            border-top: 1.5px solid var(--border-color, #e6eaf3);
            text-align: center
        }

        .dash-widget-link {
            font-size: .78rem;
            font-weight: 700;
            color: #4361ee;
            text-decoration: none
        }

        .dash-widget-link:hover {
            text-decoration: underline
        }
    </style>
</head>

<body>
    <div class="dashboard-layout">
        <?php include __DIR__ . '/sidebar.php'; ?>

        <main class="main-content">
            <header class="top-header">
                <div class="header-left">
                    <button class="hamburger" id="hamburgerBtn">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                            stroke-width="2">
                            <line x1="3" y1="12" x2="21" y2="12" />
                            <line x1="3" y1="6" x2="21" y2="6" />
                            <line x1="3" y1="18" x2="21" y2="18" />
                        </svg>
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

                <?php include __DIR__ . '/_dash_cc_body.php'; ?>

            </div><!-- /page-content -->
        </main>
    </div>

    <!-- ═══ EXAM DETAIL MODAL (Task D) ═══ -->
    <div class="modal-overlay" id="examModal">
        <div class="modal-card" style="max-width:760px">
            <div class="modal-header gradient">
                <h3>
                    <svg class="modal-header-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                        stroke="currentColor" stroke-width="2">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                        <polyline points="14 2 14 8 20 8" />
                    </svg>
                    <span id="examModalTitle">Exam Students</span>
                    <span id="examModalCount"
                        style="font-size:.75rem;font-weight:700;background:rgba(255,255,255,.2);padding:.2rem .6rem;border-radius:999px;margin-left:.5rem"></span>
                </h3>
                <button type="button" class="modal-close" onclick="closeExamModal()" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18" />
                        <line x1="6" y1="6" x2="18" y2="18" />
                    </svg>
                </button>
            </div>
            <div id="examModalBody" class="exam-modal-students">
                <!-- populated by JS -->
            </div>
            <div class="modal-footer" style="text-align:right">
                <button type="button" class="btn-secondary" onclick="closeExamModal()">Close</button>
            </div>
        </div>
    </div>

    <!-- ═══ TRANSACTIONS MODAL (Task F) ═══ -->
    <div class="trans-modal-overlay" id="transModal" onclick="if(event.target===this)closeTransModal()">
        <div class="trans-modal">
            <div class="trans-modal-header">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2" width="18" height="18" style="margin-right:.5rem;vertical-align:middle">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2" />
                        <line x1="1" y1="10" x2="23" y2="10" />
                    </svg>
                    Transactions — <span id="transModalDate">Today</span>
                </h3>
                <button onclick="closeTransModal()"
                    style="border:none;background:rgba(255,255,255,.2);border-radius:8px;color:#fff;padding:.3rem .7rem;cursor:pointer;font-size:1rem;font-weight:700">✕</button>
            </div>
            <div class="trans-modal-body" id="transModalBody"></div>
            <div class="trans-modal-footer">
                <button onclick="closeTransModal()"
                    style="border:1.5px solid #e5e7eb;background:#fff;border-radius:10px;padding:.5rem 1.25rem;font-size:.85rem;font-weight:700;cursor:pointer;color:#374151">Close</button>
            </div>
        </div>
    </div>

    <script src="../assets/js/dashboard.js"></script>
    <script>
        function loadChartJs(cb) {
            if (window.Chart) { cb(); return; }
            var s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
            s.async = true;
            s.onload = cb;
            document.head.appendChild(s);
        }

        loadChartJs(function () {
        Chart.defaults.font.family = "'Sora', 'Sora', sans-serif";
        Chart.defaults.font.weight = 600;
        Chart.defaults.color = '#64748b';

        const palette = ['#4361ee', '#0d9488', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#84cc16', '#ec4899'];
        const CHART = {
            pieMode: <?= json_encode($pieMode ?? 'course') ?>,
            pieLabels: <?= json_encode(array_values($pieLabels ?? []), JSON_UNESCAPED_UNICODE) ?>,
            pieData: <?= json_encode(array_values($pieData ?? [])) ?>,
            barLabels: <?= json_encode(array_values($barLabels ?? []), JSON_UNESCAPED_UNICODE) ?>,
            barFullLabels: <?= json_encode(array_values($barFullLabels ?? []), JSON_UNESCAPED_UNICODE) ?>,
            barData: <?= json_encode(array_values($barData ?? [])) ?>,
            line6: {
                labels: <?= json_encode(array_values(array_slice($monthlyLabels12 ?? [], -6)), JSON_UNESCAPED_UNICODE) ?>,
                adm: <?= json_encode(array_values(array_slice($monthlyData12 ?? [], -6))) ?>,
                rev: <?= json_encode(array_values(array_slice($revenueData12 ?? [], -6))) ?>,
            },
            line12: {
                labels: <?= json_encode(array_values($monthlyLabels12 ?? []), JSON_UNESCAPED_UNICODE) ?>,
                adm: <?= json_encode(array_values($monthlyData12 ?? [])) ?>,
                rev: <?= json_encode(array_values($revenueData12 ?? [])) ?>,
            },
        };

        function studentsUrlFromPieLabel(label) {
            const params = new URLSearchParams();
            if (CHART.pieMode === 'fees') {
                const map = { Paid: 'paid', Partial: 'partial', Pending: 'pending' };
                params.set('fees', map[label] || 'all');
                params.set('status', 'Active');
            } else if (label && label !== 'Other') {
                params.set('course', String(label));
            } else {
                params.set('status', 'Active');
            }
            return 'students.php?' + params.toString();
        }

        const pieEl = document.getElementById('atcPieChart');
        if (pieEl && CHART.pieData.length) {
            new Chart(pieEl, {
                type: 'pie',
                data: {
                    labels: CHART.pieLabels,
                    datasets: [{
                        data: CHART.pieData,
                        backgroundColor: palette.slice(0, CHART.pieData.length),
                        borderWidth: 2,
                        borderColor: '#fff',
                        hoverOffset: 6,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onHover: (evt, els) => {
                        evt.native.target.style.cursor = els.length ? 'pointer' : 'default';
                    },
                    onClick: (evt, els) => {
                        if (!els.length) return;
                        const idx = els[0].index;
                        const label = CHART.pieLabels[idx];
                        if (!label) return;
                        window.location.href = studentsUrlFromPieLabel(label);
                    },
                    plugins: {
                        legend: {
                            position: 'bottom',
                            labels: { boxWidth: 12, padding: 12, font: { size: 11, weight: 700 } },
                            onClick: (e, item, legend) => {
                                // Override default hide/show — navigate instead
                                const label = legend.chart.data.labels[item.index];
                                if (label) window.location.href = studentsUrlFromPieLabel(label);
                            },
                        },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            cornerRadius: 10,
                            padding: 10,
                            callbacks: {
                                label: (c) => {
                                    const total = c.dataset.data.reduce((a, b) => a + b, 0) || 1;
                                    const pct = Math.round((c.parsed / total) * 100);
                                    return ` ${c.label}: ${c.parsed} (${pct}%) · click to open`;
                                },
                            },
                        },
                    },
                },
            });
        }

        const barEl = document.getElementById('atcBarChart');
        if (barEl && CHART.barData.length) {
            const ctx = barEl.getContext('2d');
            const grad = ctx.createLinearGradient(0, 0, 0, 260);
            grad.addColorStop(0, 'rgba(67, 97, 238, 0.95)');
            grad.addColorStop(1, 'rgba(56, 189, 248, 0.55)');
            new Chart(barEl, {
                type: 'bar',
                data: {
                    labels: CHART.barLabels,
                    datasets: [{
                        label: 'Fees collected',
                        data: CHART.barData,
                        backgroundColor: grad,
                        borderRadius: 8,
                        maxBarThickness: 42,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onHover: (evt, els) => {
                        evt.native.target.style.cursor = els.length ? 'pointer' : 'default';
                    },
                    onClick: (evt, els) => {
                        if (!els.length) return;
                        const idx = els[0].index;
                        const full = (CHART.barFullLabels[idx] || CHART.barLabels[idx] || '').trim();
                        if (!full) return;
                        const params = new URLSearchParams();
                        if (full === 'Other') {
                            params.set('status', 'Active');
                        } else {
                            params.set('course', full);
                        }
                        window.location.href = 'students.php?' + params.toString();
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            cornerRadius: 10,
                            padding: 10,
                            callbacks: {
                                label: (c) => ' ₹' + Number(c.parsed.y || 0).toLocaleString('en-IN') + ' · click to open',
                            },
                        },
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                callback: (v) => '₹' + Number(v).toLocaleString('en-IN'),
                                font: { size: 10 },
                            },
                            grid: { color: '#f1f5f9', drawBorder: false },
                        },
                        x: {
                            ticks: { font: { size: 10, weight: 700 }, maxRotation: 35, minRotation: 0 },
                            grid: { display: false },
                        },
                    },
                },
            });
        }

        const lineEl = document.getElementById('atcLineChart');
        let atcLineChart = null;
        if (lineEl && CHART.line6.labels.length) {
            atcLineChart = new Chart(lineEl, {
                type: 'line',
                data: {
                    labels: CHART.line6.labels,
                    datasets: [
                        {
                            label: 'Admissions',
                            data: CHART.line6.adm,
                            borderColor: '#4361ee',
                            backgroundColor: 'rgba(67, 97, 238, 0.12)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            yAxisID: 'y',
                        },
                        {
                            label: 'Fee revenue (₹)',
                            data: CHART.line6.rev,
                            borderColor: '#0d9488',
                            backgroundColor: 'rgba(13, 148, 136, 0.08)',
                            fill: true,
                            tension: 0.35,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                            yAxisID: 'y1',
                        },
                    ],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'top',
                            align: 'end',
                            labels: { boxWidth: 12, padding: 14, font: { size: 11, weight: 700 } },
                        },
                        tooltip: {
                            backgroundColor: '#0f172a',
                            cornerRadius: 10,
                            padding: 10,
                        },
                    },
                    scales: {
                        y: {
                            type: 'linear',
                            position: 'left',
                            beginAtZero: true,
                            title: { display: true, text: 'Admissions', font: { size: 11, weight: 700 } },
                            ticks: { stepSize: 1, font: { size: 10 } },
                            grid: { color: '#f1f5f9', drawBorder: false },
                        },
                        y1: {
                            type: 'linear',
                            position: 'right',
                            beginAtZero: true,
                            title: { display: true, text: 'Fees ₹', font: { size: 11, weight: 700 } },
                            ticks: {
                                callback: (v) => '₹' + Number(v).toLocaleString('en-IN'),
                                font: { size: 10 },
                            },
                            grid: { drawOnChartArea: false },
                        },
                        x: {
                            ticks: { font: { size: 11, weight: 700 } },
                            grid: { display: false },
                        },
                    },
                },
            });

            const rangeWrap = document.getElementById('atcChartRange');
            const lineSub = document.getElementById('atcLineSub');
            rangeWrap?.querySelectorAll('.cc-period-pill').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const months = btn.dataset.months === '12' ? 12 : 6;
                    rangeWrap.querySelectorAll('.cc-period-pill').forEach((b) => b.classList.remove('active'));
                    btn.classList.add('active');
                    const series = months === 12 ? CHART.line12 : CHART.line6;
                    atcLineChart.data.labels = series.labels;
                    atcLineChart.data.datasets[0].data = series.adm;
                    atcLineChart.data.datasets[1].data = series.rev;
                    atcLineChart.update();
                    if (lineSub) {
                        lineSub.textContent = months === 12
                            ? 'Admissions & fee revenue — last 12 months'
                            : 'Admissions & fee revenue — last 6 months';
                    }
                });
            });
        }
        });

        (function () {
            const cal = document.getElementById('miniCal');
            if (!cal) return;
            const now = new Date(), y = now.getFullYear(), m = now.getMonth(), t = now.getDate();
            const fd = new Date(y, m, 1).getDay(), dim = new Date(y, m + 1, 0).getDate();
            const days = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
            let h = '';
            days.forEach(d => h += `<div class="cal-day-name">${d}</div>`);
            for (let i = 0; i < fd; i++) h += '<div class="cal-day empty"></div>';
            for (let d = 1; d <= dim; d++) h += `<div class="cal-day${d === t ? ' today' : ''}">${d}</div>`;
            cal.innerHTML = h;
        })();

        // ── Exam Modal (Task D) ──────────────────────────────────────────────────────
        const EXAM_STUDENTS = {
            all: <?= json_encode($examStudentsAll, JSON_HEX_TAG) ?>,
            pending: <?= json_encode($examStudentsPending, JSON_HEX_TAG) ?>,
            conducted: <?= json_encode($examStudentsConducted, JSON_HEX_TAG) ?>,
        };
        const EXAM_TITLES = {
            all: 'All Exam Students',
            pending: 'Pending Exams — Upcoming',
            conducted: 'Conducted Exams — Completed',
        };

        // ── Task E: Head Office Reporting Modal ───────────────────────────────────────
        const HO_STUDENTS = {
            reported: <?= json_encode(array_values($reportedStudents), JSON_HEX_TAG) ?>,
            pending: <?= json_encode(array_values($pendingReportStudents), JSON_HEX_TAG) ?>,
        };

        function openHOModal(type) {
            const list = HO_STUDENTS[type] || [];
            const title = type === 'reported' ? 'Reported Students' : 'Pending Reports';
            document.getElementById('examModalTitle').textContent = title;
            document.getElementById('examModalCount').textContent = list.length + ' student(s)';
            const body = document.getElementById('examModalBody');
            // Switch body to table layout (remove grid class)
            body.className = 'ho-modal-wrap';

            if (!list.length) {
                body.innerHTML = `<p style="text-align:center;padding:2.5rem;color:#9ca3af;font-weight:600">${type === 'reported' ? 'No students reported yet.' : 'All students reported! Great job.'}</p>`;
                document.getElementById('examModal').classList.add('active');
                return;
            }

            const rows = list.map((s, i) => {
                const name = (s.name || [s.first_name, s.last_name].filter(Boolean).join(' ')).trim();
                const initials = name.split(' ').filter(Boolean).map(w => w[0]).slice(0, 2).join('').toUpperCase();
                const avatarHtml = s.photo
                    ? `<img src="../${s.photo}" style="width:30px;height:30px;border-radius:50%;object-fit:cover;border:1.5px solid #e5e7eb;flex-shrink:0" onerror="this.outerHTML='<span style=\\'display:inline-flex;width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#4361ee,#8b5cf6);color:#fff;font-size:.65rem;font-weight:800;align-items:center;justify-content:center;flex-shrink:0\\'>${initials}</span>'">`
                    : `<span style="display:inline-flex;width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#4361ee,#8b5cf6);color:#fff;font-size:.65rem;font-weight:800;align-items:center;justify-content:center;flex-shrink:0">${initials}</span>`;
                const badge = type === 'reported'
                    ? '<span class="ho-status-badge ho-status-reported">Share Paid</span>'
                    : '<span class="ho-status-badge ho-status-pending">Pending</span>';
                return `<tr>
            <td style="font-weight:700;color:#6b7280">${i + 1}</td>
            <td style="font-family:monospace;font-size:.78rem;font-weight:700">${s.roll_no || '—'}</td>
            <td><span style="display:inline-flex;align-items:center;gap:.5rem">${avatarHtml}<strong style="color:#111827">${name}</strong></span></td>
            <td>${s.course || '—'}</td>
            <td>${badge}</td>
        </tr>`;
            }).join('');

            body.innerHTML = `<table class="ho-modal-table">
        <thead><tr>
            <th>#</th><th>Roll No</th><th>Student Name</th><th>Course</th><th>Status</th>
        </tr></thead>
        <tbody>${rows}</tbody>
    </table>`;

            document.getElementById('examModal').classList.add('active');
        }

        function openExamModal(type) {
            const students = EXAM_STUDENTS[type] || [];
            document.getElementById('examModalTitle').textContent = EXAM_TITLES[type];
            document.getElementById('examModalCount').textContent = students.length + ' student(s)';

            const body = document.getElementById('examModalBody');
            body.className = 'exam-modal-students'; // reset from HO table layout
            if (!students.length) {
                body.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:3rem 1rem;color:#9ca3af;font-weight:600">No students found for this category.</div>';
            } else {
                body.innerHTML = students.map(s => {
                    const name = (s.student_name || '').trim().replace(/\s+/g, ' ');
                    const initials = name.split(' ').filter(Boolean).map(w => w[0]).slice(0, 2).join('').toUpperCase();
                    const photoEl = s.photo
                        ? `<img class="exam-student-photo" src="../${s.photo}" alt="${name}" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"><div class="exam-student-avatar" style="display:none">${initials}</div>`
                        : `<div class="exam-student-avatar">${initials}</div>`;
                    const examDate = s.exam_date ? new Date(s.exam_date + 'T00:00:00').toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
                    const examTime = s.exam_time ? ' · ' + s.exam_time.substring(0, 5) : '';
                    const hall = s.exam_hall ? `<div class="exam-student-meta">Hall: ${s.exam_hall}</div>` : '';
                    return `<div class="exam-student-card">
                ${photoEl}
                <div class="exam-student-name">${name}</div>
                <div class="exam-student-meta">${s.course || '—'}</div>
                <div class="exam-student-meta">Roll: ${s.roll_no || '—'}</div>
                <div class="exam-student-date">${examDate}${examTime}</div>
                ${hall}
            </div>`;
                }).join('');
            }
            document.getElementById('examModal').classList.add('active');
        }

        function closeExamModal() {
            document.getElementById('examModal').classList.remove('active');
        }

        // ── Task F: Total Collections Filter ──────────────────────────────────────────
        function applyCollFilter() {
            const mode = document.getElementById('collFilter').value;
            const range = document.getElementById('collCustomRange');
            const lbl = document.getElementById('collSubLabel');

            range.style.display = mode === 'custom' ? 'flex' : 'none';
            const from = document.getElementById('collFrom').value;
            const to = document.getElementById('collTo').value;

            if (mode === 'custom' && (!from || !to)) return; // wait for both dates

            let url = `index.php?ajax_collection=1&mode=${mode}`;
            if (mode === 'custom') url += `&from=${from}&to=${to}`;

            fetch(url).then(r => r.json()).then(data => {
                const fmt = new Intl.NumberFormat('en-IN');
                document.getElementById('totalCollValue').textContent = '₹' + fmt.format(Math.round(data.total));
                const labels = { all: 'All time via receipts', month: 'This month only', custom: `${from} → ${to}` };
                if (lbl) lbl.textContent = labels[mode];
            });
        }

        // ── Task F: Today's Date-Picker Filter ────────────────────────────────────────
        function applyTodayFilter(e) {
            e.stopPropagation(); // don't fire card click
            const date = e.target.value;
            fetch(`index.php?ajax_transactions=1&date=${date}`)
                .then(r => r.json()).then(data => {
                    const rows = data.rows || [];
                    const cash = rows.filter(r => r.payment_mode === 'Cash').reduce((s, r) => s + parseFloat(r.amount), 0);
                    const online = rows.filter(r => r.payment_mode !== 'Cash').reduce((s, r) => s + parseFloat(r.amount), 0);
                    const fmt = new Intl.NumberFormat('en-IN');
                    document.getElementById('todayCollValue').textContent = '₹' + fmt.format(Math.round(cash + online));
                    window._transRows = rows; // cache for modal
                    window._transDate = date;
                });
        }

        // ── Task F: Transaction Modal ─────────────────────────────────────────────────
        function openTransModal() {
            const date = document.getElementById('todayDatePicker')?.value || '';
            const modal = document.getElementById('transModal');
            const body = document.getElementById('transModalBody');
            const titleDate = document.getElementById('transModalDate');

            titleDate.textContent = date ? new Date(date + 'T00:00:00').toLocaleDateString('en-IN', { day: '2-digit', month: 'long', year: 'numeric' }) : 'Today';
            body.innerHTML = '<p style="text-align:center;padding:2rem;color:#9ca3af">Loading…</p>';
            modal.classList.add('open');

            if (window._transRows && window._transDate === date) {
                renderTransRows(window._transRows);
            } else {
                fetch(`index.php?ajax_transactions=1&date=${date || '<?= date('Y-m-d') ?>'}`)
                    .then(r => r.json()).then(data => {
                        window._transRows = data.rows;
                        window._transDate = date;
                        renderTransRows(data.rows);
                    });
            }
        }

        function renderTransRows(rows) {
            const body = document.getElementById('transModalBody');
            if (!rows.length) {
                body.innerHTML = '<p style="text-align:center;padding:2rem;color:#9ca3af;font-weight:600">No transactions found for this date.</p>';
                return;
            }
            const fmt = new Intl.NumberFormat('en-IN');
            const total = rows.reduce((s, r) => s + parseFloat(r.amount), 0);
            let html = `<div style="padding:.75rem 1.5rem 0;display:flex;justify-content:space-between;align-items:center;font-size:.82rem">
        <span style="color:#6b7280">${rows.length} transaction(s)</span>
        <strong style="color:#059669">Total: ₹${fmt.format(Math.round(total))}</strong>
    </div>
    <div style="overflow-x:auto">
    <table style="width:100%;border-collapse:collapse;font-size:.84rem">
    <thead><tr style="background:#f9fafb">
        <th style="padding:.6rem 1.25rem;text-align:left;font-size:.7rem;font-weight:800;text-transform:uppercase;color:#6b7280;border-bottom:2px solid #e5e7eb">Student</th>
        <th style="padding:.6rem 1rem;text-align:left;font-size:.7rem;font-weight:800;text-transform:uppercase;color:#6b7280;border-bottom:2px solid #e5e7eb">Course</th>
        <th style="padding:.6rem 1rem;text-align:right;font-size:.7rem;font-weight:800;text-transform:uppercase;color:#6b7280;border-bottom:2px solid #e5e7eb">Amount</th>
        <th style="padding:.6rem 1rem;text-align:center;font-size:.7rem;font-weight:800;text-transform:uppercase;color:#6b7280;border-bottom:2px solid #e5e7eb">Mode</th>
        <th style="padding:.6rem 1rem;text-align:right;font-size:.7rem;font-weight:800;text-transform:uppercase;color:#6b7280;border-bottom:2px solid #e5e7eb">Time</th>
    </tr></thead><tbody>`;
            rows.forEach((r, i) => {
                const bg = i % 2 === 0 ? '#fff' : '#f9fafb';
                const modeColor = r.payment_mode === 'Cash' ? '#059669' : '#2563eb';
                const time = r.payment_date ? new Date(r.payment_date).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' }) : '—';
                html += `<tr style="background:${bg};border-bottom:1px solid #f3f4f6">
            <td style="padding:.65rem 1.25rem;font-weight:700;color:#111827">${r.student_name}<br><small style="font-weight:500;color:#9ca3af;font-family:monospace">${r.roll_no || ''}</small></td>
            <td style="padding:.65rem 1rem;color:#4b5563">${r.course || '—'}</td>
            <td style="padding:.65rem 1rem;text-align:right;font-weight:800;color:#111827">₹${fmt.format(parseFloat(r.amount))}</td>
            <td style="padding:.65rem 1rem;text-align:center"><span style="background:${modeColor}22;color:${modeColor};font-size:.72rem;font-weight:700;padding:.2rem .6rem;border-radius:999px">${r.payment_mode}</span></td>
            <td style="padding:.65rem 1rem;text-align:right;color:#9ca3af">${time}</td>
        </tr>`;
            });
            html += '</tbody></table></div>';
            document.getElementById('transModalBody').innerHTML = html;
        }

        function closeTransModal() {
            document.getElementById('transModal').classList.remove('open');
        }

        // ── Task G: Notice toggle ─────────────────────────────────────────────────────
        function toggleNotice(i) {
            const prev = document.getElementById('nb-prev-' + i);
            const full = document.getElementById('nb-full-' + i);
            const btn = document.getElementById('nb-btn-' + i);
            const open = full.style.display === 'none';
            prev.style.display = open ? 'none' : 'block';
            full.style.display = open ? 'block' : 'none';
            btn.textContent = open ? 'Read less ▴' : 'Read more ▾';
        }

        // ── Birthday Wish ─────────────────────────────────────────────────────────────
        function sendAtcBdayWish(name, mobile) {
            const msg = encodeURIComponent('Dear ' + name + ',\n\nWarm Birthday Greetings from the entire Gyanam India family!\n\nOn this special occasion, we extend our heartfelt wishes to you. May this new year of your life bring you great health, abundant happiness, and continued success in everything you pursue.\n\nWe are grateful to have you as a valued part of the Gyanam India community. May your day be as wonderful as the joy you bring to everyone around you.\n\nWith warm regards,\nTeam Gyanam India');
            const num = mobile.replace(/\D/g, '');
            window.open('https://wa.me/91' + num + '?text=' + msg, '_blank');
        }
    </script>
</body>

</html>