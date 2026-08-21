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
    $stmt = $pdo->prepare("SELECT status, COALESCE(fees_pending,0) as fp FROM admissions WHERE atc_id = ?");
    $stmt->execute([$atcId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $totalStudents++;
        if ($s['status'] === 'Active') {
            $activeStudents++;
            if ((float) $s['fp'] <= 0)
                $activePaid++;
            else
                $activeUnpaid++;
        }
    }
} catch (Exception $e) {
}

$recentInquiries = [];
try {
    $stmt = $pdo->prepare("SELECT name, course_interested, created_at FROM inquiries WHERE atc_id = ? ORDER BY created_at DESC LIMIT 5");
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
    $stmt = $pdo->query("SELECT * FROM announcements WHERE status = 'Active' AND target_audience IN ('All', 'ATC') ORDER BY created_at DESC");
    $activeBanners = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

/* ── Task E: Reported Students / Pending Reports (HO Share) ──────────────── */
$reportedStudents = [];
$pendingReportStudents = [];
$reportedCount = 0;
$pendingReportCount = 0;
try {
    // Build set of paid student IDs from share_payments
    $paidIds = [];
    $sp = $pdo->prepare("SELECT student_ids FROM share_payments WHERE atc_id = ? AND status = 'Completed'");
    $sp->execute([$atcId]);
    foreach ($sp->fetchAll(PDO::FETCH_COLUMN) as $json) {
        $ids = json_decode($json, true);
        if (is_array($ids))
            foreach ($ids as $id)
                $paidIds[intval($id)] = true;
    }

    // Also check ho_share_paid column if it exists
    $allStudents = $pdo->prepare("
        SELECT id, CONCAT(first_name,' ',COALESCE(middle_name,''),' ',last_name) AS name,
               course, roll_no, photo, COALESCE(ho_share_paid,0) AS ho_share_paid
        FROM admissions WHERE atc_id = ? AND status = 'Active'
        ORDER BY first_name ASC
    ");
    $allStudents->execute([$atcId]);
    foreach ($allStudents->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $isReported = isset($paidIds[$row['id']]) || $row['ho_share_paid'];
        if ($isReported) {
            $reportedStudents[] = $row;
        } else {
            $pendingReportStudents[] = $row;
        }
    }
    $reportedCount = count($reportedStudents);
    $pendingReportCount = count($pendingReportStudents);
} catch (Exception $e) {
    // Try without ho_share_paid column
    try {
        $paidIds = [];
        $sp = $pdo->prepare("SELECT student_ids FROM share_payments WHERE atc_id = ? AND status = 'Completed'");
        $sp->execute([$atcId]);
        foreach ($sp->fetchAll(PDO::FETCH_COLUMN) as $json) {
            $ids = json_decode($json, true);
            if (is_array($ids))
                foreach ($ids as $id)
                    $paidIds[intval($id)] = true;
        }
        $allStudents = $pdo->prepare("
            SELECT id, CONCAT(first_name,' ',COALESCE(middle_name,''),' ',last_name) AS name,
                   course, roll_no, photo
            FROM admissions WHERE atc_id = ? AND status = 'Active'
            ORDER BY first_name ASC
        ");
        $allStudents->execute([$atcId]);
        foreach ($allStudents->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($paidIds[$row['id']])) {
                $reportedStudents[] = $row;
            } else {
                $pendingReportStudents[] = $row;
            }
        }
        $reportedCount = count($reportedStudents);
        $pendingReportCount = count($pendingReportStudents);
    } catch (Exception $e2) {
    }
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

$conversionRate = $totalInquiries > 0 ? round(($convertedInquiries / $totalInquiries) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — ATC Center | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap"
        rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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

            <div class="page-content">

                <!-- ═══ DASHBOARD BANNERS — Auto-Sliding Carousel ═══ -->
                <?php $imgPrefix = '../uploads/announcements/';
                include __DIR__ . '/../includes/banner_carousel.php'; ?>

                <!-- ═══ DASHBOARD KPI STRIP ═══ -->
                <!--<div class="dash-kpi-strip" style="margin-bottom:1.25rem">-->
                <!--    <div class="dash-kpi" style="animation-delay:.05s">-->
                <!--        <div class="dash-kpi-icon" style="background:#eef2ff">-->
                <!--            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#4361ee" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>-->
                <!--        </div>-->
                <!--        <div>-->
                <!--            <div class="dash-kpi-label">Total Students</div>-->
                <!--            <div class="dash-kpi-value"><?= $totalStudents ?></div>-->
                <!--            <div class="dash-kpi-sub"><?= $activeStudents ?> active · <?= $totalStudents - $activeStudents ?> inactive</div>-->
                <!--        </div>-->
                <!--    </div>-->
                <!--    <div class="dash-kpi" style="animation-delay:.1s">-->
                <!--        <div class="dash-kpi-icon" style="background:#ecfdf5">-->
                <!--            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#059669" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>-->
                <!--        </div>-->
                <!--        <div>-->
                <!--            <div class="dash-kpi-label">Total Collected</div>-->
                <!--            <div class="dash-kpi-value" style="color:#047857">&#8377;<?= number_format($grandTotalCollected, 0) ?></div>-->
                <!--            <div class="dash-kpi-sub"><?= $collectionPercentage ?>% of total fees</div>-->
                <!--        </div>-->
                <!--    </div>-->
                <!--    <div class="dash-kpi" style="animation-delay:.15s">-->
                <!--        <div class="dash-kpi-icon" style="background:#fef2f2">-->
                <!--            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>-->
                <!--        </div>-->
                <!--        <div>-->
                <!--            <div class="dash-kpi-label">Pending Fees</div>-->
                <!--            <div class="dash-kpi-value" style="color:#be123c">&#8377;<?= number_format($pendingFees, 0) ?></div>-->
                <!--            <div class="dash-kpi-sub"><?= $activeUnpaid ?> students with balance due</div>-->
                <!--        </div>-->
                <!--    </div>-->
                <!--    <div class="dash-kpi" style="animation-delay:.2s">-->
                <!--        <div class="dash-kpi-icon" style="background:#fefce8">-->
                <!--            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#ca8a04" stroke-width="2"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>-->
                <!--        </div>-->
                <!--        <div>-->
                <!--            <div class="dash-kpi-label">Conversion Rate</div>-->
                <!--            <div class="dash-kpi-value" style="color:#854d0e"><?= $conversionRate ?>%</div>-->
                <!--            <div class="dash-kpi-sub"><?= $convertedInquiries ?> of <?= $totalInquiries ?> inquiries</div>-->
                <!--        </div>-->
                <!--    </div>-->
                <!--</div>-->

                <!-- ═══ OVERVIEW (existing) ═══ -->
                <div class="dash-section-label">Overview</div>
                <div class="stats-grid">
                    <div class="stat-card amber">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <path
                                    d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" />
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-label">Total Inquiries</div>
                            <div class="stat-value" data-count="<?= $totalInquiries ?>">0</div>
                        </div>
                    </div>
                    <div class="stat-card blue">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <path
                                    d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.19 11.9 19.79 19.79 0 0 1 1.12 3.2 2 2 0 0 1 3.11 1h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z" />
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-label">Telephonic Inquiries</div>
                            <div class="stat-value" data-count="<?= $totalTelephonic ?>">0</div>
                        </div>
                    </div>
                    <div class="stat-card purple">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                                <circle cx="9" cy="7" r="4" />
                                <path d="M22 21v-2a4 4 0 0 0-3-3.87" />
                                <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-label">Admissions</div>
                            <div class="stat-value" data-count="<?= $totalAdmissions ?>">0</div>
                        </div>
                    </div>
                    <div class="stat-card green">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <polyline points="20 6 9 17 4 12" />
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-label">Converted</div>
                            <div class="stat-value" data-count="<?= $convertedInquiries ?>">0</div>
                        </div>
                    </div>
                </div>

                <!-- ═══ NOTICE BOARD (Task G — card-style redesign) ═══ -->
                <?php if (!empty($tickerNotifs)): ?>
                    <div class="dash-section-label">Notice Board</div>
                    <div class="nb-grid">
                        <?php foreach ($tickerNotifs as $i => $tn):
                            $isUrgent = stripos($tn['title'], 'urgent') !== false
                                || stripos($tn['message'], 'urgent') !== false
                                || ($tn['priority'] ?? '') === 'High';
                            $preview = htmlspecialchars(mb_strimwidth($tn['message'], 0, 120, '…'));
                            $full = htmlspecialchars($tn['message']);
                            $date = date('d M Y', strtotime($tn['created_at']));
                            ?>
                            <div class="nb-card <?= $isUrgent ? 'nb-urgent' : '' ?>" id="nb2-<?= $i ?>">
                                <div class="nb-card-top">
                                    <div class="nb-badge <?= $isUrgent ? 'nb-badge-urgent' : 'nb-badge-regular' ?>">
                                        <?= $isUrgent ? '🔴 Urgent' : '📢 Notice' ?>
                                    </div>
                                    <span class="nb-date"><?= $date ?></span>
                                </div>
                                <div class="nb-title"><?= htmlspecialchars($tn['title']) ?></div>
                                <div class="nb-preview" id="nb-prev-<?= $i ?>"><?= $preview ?></div>
                                <div class="nb-full" id="nb-full-<?= $i ?>" style="display:none"><?= nl2br($full) ?></div>
                                <?php if (strlen($tn['message']) > 120): ?>
                                    <button class="nb-toggle" onclick="toggleNotice(<?= $i ?>)" id="nb-btn-<?= $i ?>">Read more
                                        ▾</button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- ═══ FEES STATISTICS (existing) ═══ -->
                <div class="dash-section-label">Fees Statistics</div>
                <div class="fees-stats-grid">
                    <div class="fees-card fc-total">
                        <div class="fees-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="1" x2="12" y2="23" />
                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                            </svg></div>
                        <div class="fees-info">
                            <div class="fees-label">Total Fees</div>
                            <div class="fees-value">₹ <?= number_format($totalFees, 2) ?></div>
                            <div class="fees-subtitle">Expected revenue</div>
                        </div>
                    </div>
                    <div class="fees-card fc-col">
                        <div class="fees-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <polyline points="20 6 9 17 4 12" />
                                <line x1="12" y1="1" x2="12" y2="23" />
                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                            </svg></div>
                        <div class="fees-info">
                            <div class="fees-label">Fees Collected</div>
                            <div class="fees-value">₹ <?= number_format($paidFees, 2) ?></div>
                            <div class="fees-subtitle"><span class="collection-badge"><?= $collectionPercentage ?>%
                                    collected</span></div>
                        </div>
                    </div>
                    <div class="fees-card fc-pend">
                        <div class="fees-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10" />
                                <polyline points="12 6 12 12 16 14" />
                            </svg></div>
                        <div class="fees-info">
                            <div class="fees-label">Pending Fees</div>
                            <div class="fees-value">₹ <?= number_format($pendingFees, 2) ?></div>
                            <div class="fees-subtitle">Outstanding amount</div>
                        </div>
                    </div>
                    <div class="fees-card fc-prog">
                        <div class="fees-info" style="width:100%">
                            <div class="fees-label">Collection Progress</div>
                            <div class="progress-bar-container">
                                <div class="progress-bar" style="width:<?= $collectionPercentage ?>%">
                                    <span class="progress-text"><?= $collectionPercentage ?>%</span>
                                </div>
                            </div>
                            <div class="progress-details">₹<?= number_format($paidFees, 0) ?> of
                                ₹<?= number_format($totalFees, 0) ?></div>
                        </div>
                    </div>
                </div>

                <!-- ═══ COLLECTIONS (Task F — filter + clickable) ═══ -->
                <div class="dash-section-label">Collections</div>
                <div class="stats-grid">
                    <!-- Total Collections with filter -->
                    <div class="stat-card green" id="totalCollCard">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="1" x2="12" y2="23" />
                                <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                            </svg>
                        </div>
                        <div class="stat-info" style="width:100%">
                            <div
                                style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.3rem">
                                <div class="stat-label">Total Collections</div>
                                <select id="collFilter" onchange="applyCollFilter()"
                                    style="font-size:.68rem;font-weight:700;border:1px solid rgba(255,255,255,.3);border-radius:6px;background:rgba(255,255,255,.15);color:inherit;padding:.15rem .35rem;cursor:pointer;outline:none">
                                    <option value="all">All Time</option>
                                    <option value="month">This Month</option>
                                    <option value="custom">Custom</option>
                                </select>
                            </div>
                            <div class="stat-value" id="totalCollValue">₹<?= number_format($grandTotalCollected, 0) ?>
                            </div>
                            <div id="collCustomRange"
                                style="display:none;margin-top:.4rem;display:none;gap:.3rem;flex-wrap:wrap">
                                <input type="date" id="collFrom"
                                    style="font-size:.68rem;border:1px solid rgba(255,255,255,.3);border-radius:6px;background:rgba(255,255,255,.15);color:inherit;padding:.15rem .35rem;outline:none"
                                    onchange="applyCollFilter()">
                                <span style="font-size:.68rem;opacity:.8">to</span>
                                <input type="date" id="collTo"
                                    style="font-size:.68rem;border:1px solid rgba(255,255,255,.3);border-radius:6px;background:rgba(255,255,255,.15);color:inherit;padding:.15rem .35rem;outline:none"
                                    onchange="applyCollFilter()">
                            </div>
                            <div class="stat-sub" style="margin-top:.4rem">
                                <div class="stat-sub-item" id="collSubLabel"><span class="dot dot-gray"></span>All time
                                    via receipts</div>
                            </div>
                        </div>
                    </div>
                    <!-- Today's Collections — clickable + date picker -->
                    <div class="stat-card blue clickable" id="todayCollCard" onclick="openTransModal()"
                        title="Click to view transactions">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <rect x="1" y="4" width="22" height="16" rx="2" ry="2" />
                                <line x1="1" y1="10" x2="23" y2="10" />
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div
                                style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.3rem">
                                <div class="stat-label">Today's Collections</div>
                                <input type="date" id="todayDatePicker" value="<?= date('Y-m-d') ?>"
                                    style="font-size:.68rem;font-weight:700;border:1px solid rgba(255,255,255,.3);border-radius:6px;background:rgba(255,255,255,.15);color:inherit;padding:.15rem .35rem;outline:none"
                                    onchange="applyTodayFilter(event)">
                            </div>
                            <div class="stat-value" id="todayCollValue">₹<?= number_format($todayCash + $todayOnline, 0) ?>
                            </div>
                            <div class="stat-sub">
                                <div class="stat-sub-item"><span class="dot dot-green"></span>Cash
                                    ₹<?= number_format($todayCash, 0) ?></div>
                                <div class="stat-sub-item"><span class="dot dot-blue"></span>Online
                                    ₹<?= number_format($todayOnline, 0) ?></div>
                            </div>
                            <div style="font-size:.7rem;margin-top:.4rem;opacity:.8">👆 Click card to view transactions
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ ACADEMIC OVERVIEW (new) ═══ -->
                <div class="dash-section-label">Academic Overview</div>
                <div class="stats-grid-6">
                    <div class="stat-card blue">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z" />
                                <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z" />
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-label">Courses</div>
                            <div class="stat-value"><?= $totalCourses ?></div>
                            <div class="stat-sub">
                                <div class="stat-sub-item"><span class="dot dot-green"></span><?= $activeCourses ?>
                                    Active</div>
                                <div class="stat-sub-item"><span
                                        class="dot dot-gray"></span><?= $totalCourses - $activeCourses ?> Inactive</div>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card indigo">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="8" r="6" />
                                <path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11" />
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-label">Certificates</div>
                            <div class="stat-value"><?= $certsDistributed + $certsPending ?></div>
                            <div class="stat-sub">
                                <div class="stat-sub-item"><span class="dot dot-green"></span><?= $certsDistributed ?>
                                    Issued</div>
                                <div class="stat-sub-item"><span class="dot dot-gray"></span><?= $certsPending ?>
                                    Pending</div>
                            </div>
                        </div>
                    </div>
                    <!-- Exam Cards (D) -->
                    <div class="stat-card amber clickable" onclick="openExamModal('all')"
                        title="Click to view all exam students">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                                <polyline points="14 2 14 8 20 8" />
                                <line x1="16" y1="13" x2="8" y2="13" />
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-label">Total Exams</div>
                            <div class="stat-value" data-count="<?= $totalExams ?>"><?= $totalExams ?></div>
                            <div class="stat-sub">
                                <div class="stat-sub-item"><span class="dot dot-gray"></span>All scheduled</div>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card orange clickable" onclick="openExamModal('pending')"
                        title="Click to view students with pending exams">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10" />
                                <polyline points="12 6 12 12 16 14" />
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-label">Pending Exams</div>
                            <div class="stat-value"><?= $pendingExams ?></div>
                            <div class="stat-sub">
                                <div class="stat-sub-item"><span class="dot dot-red"></span>Upcoming / today</div>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card green clickable" onclick="openExamModal('conducted')"
                        title="Click to view students with conducted exams">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <polyline points="20 6 9 17 4 12" />
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-label">Conducted Exams</div>
                            <div class="stat-value"><?= $conductedExams ?></div>
                            <div class="stat-sub">
                                <div class="stat-sub-item"><span class="dot dot-green"></span>Completed</div>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card teal">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                                <circle cx="9" cy="7" r="4" />
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
                                <path d="M16 3.13a4 4 0 0 1 0 7.75" />
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-label">Total Students</div>
                            <div class="stat-value"><?= $totalStudents ?></div>
                            <div class="stat-sub">
                                <div class="stat-sub-item"><span class="dot dot-green"></span><?= $activeStudents ?>
                                    Active</div>
                                <div class="stat-sub-item"><span
                                        class="dot dot-gray"></span><?= $totalStudents - $activeStudents ?> Inactive
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card green">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <polyline points="20 6 9 17 4 12" />
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-label">Active — Fees Clear</div>
                            <div class="stat-value"><?= $activePaid ?></div>
                            <div class="stat-sub">
                                <div class="stat-sub-item"><span class="dot dot-green"></span>No pending balance</div>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card red">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10" />
                                <line x1="12" y1="8" x2="12" y2="12" />
                                <line x1="12" y1="16" x2="12.01" y2="16" />
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-label">Active — Fees Due</div>
                            <div class="stat-value"><?= $activeUnpaid ?></div>
                            <div class="stat-sub">
                                <div class="stat-sub-item"><span class="dot dot-red"></span>Balance pending</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══ GYANAM HEAD OFFICE (Task E) ═══ -->
                <div class="dash-section-label" style="display:flex;align-items:center;gap:.5rem">
                    <span
                        style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,#4361ee,#8b5cf6);color:#fff;font-size:.7rem;font-weight:900;flex-shrink:0">HO</span>
                    Gyanam Head Office
                </div>
                <div class="stats-grid">
                    <div class="stat-card green clickable" onclick="openHOModal('reported')"
                        title="View reported students">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <polyline points="20 6 9 17 4 12" />
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-label">Reported Students</div>
                            <div class="stat-value"><?= $reportedCount ?></div>
                            <div class="stat-sub">
                                <div class="stat-sub-item"><span class="dot dot-green"></span>Share paid to HO</div>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card amber clickable" onclick="openHOModal('pending')"
                        title="View pending report students">
                        <div class="stat-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10" />
                                <polyline points="12 6 12 12 16 14" />
                            </svg>
                        </div>
                        <div class="stat-info">
                            <div class="stat-label">Pending Reports</div>
                            <div class="stat-value"><?= $pendingReportCount ?></div>
                            <div class="stat-sub">
                                <div class="stat-sub-item"><span class="dot dot-red"></span>Share not yet reported</div>
                            </div>
                        </div>
                    </div>
                </div>



                <!-- ═══ BIRTHDAYS ═══ -->
                <?php if (!empty($birthdays)): ?>
                    <div class="dash-section-label">🎂 Student Birthdays Today</div>
                    <div class="atc-bday-panel">
                        <div class="atc-bday-header">
                            <div class="atc-bday-title">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                    stroke="currentColor" stroke-width="2" style="width:18px;height:18px;color:#f97316">
                                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2" />
                                    <line x1="16" y1="2" x2="16" y2="6" />
                                    <line x1="8" y1="2" x2="8" y2="6" />
                                    <line x1="3" y1="10" x2="21" y2="10" />
                                </svg>
                                🎂 Today's Student Birthdays
                            </div>
                            <span class="atc-bday-count"><?= count($birthdays) ?>
                                birthday<?= count($birthdays) !== 1 ? 's' : '' ?> today</span>
                        </div>
                        <div class="atc-bday-body">
                            <?php foreach ($birthdays as $b): ?>
                                <div class="atc-bday-row">
                                    <div class="atc-bday-avatar"><?= mb_strtoupper(mb_substr(trim($b['name']), 0, 1)) ?></div>
                                    <div style="flex:1;min-width:0">
                                        <div class="atc-bday-name"><?= htmlspecialchars(trim($b['name'])) ?></div>
                                        <div class="atc-bday-meta">📚 <?= htmlspecialchars($b['course'] ?? '') ?> &middot;
                                            Birthday Today 🎉</div>
                                    </div>
                                    <?php if (!empty($b['mobile'])): ?>
                                        <button
                                            onclick="sendAtcBdayWish('<?= addslashes(htmlspecialchars(trim($b['name']))) ?>', '<?= htmlspecialchars($b['mobile']) ?>')"
                                            style="display:inline-flex;align-items:center;gap:.35rem;padding:.4rem .85rem;border-radius:999px;border:none;background:#25d366;color:#fff;font-size:.75rem;font-weight:700;cursor:pointer;white-space:nowrap;font-family:inherit;flex-shrink:0;transition:opacity .15s"
                                            onmouseover="this.style.opacity='.85'" onmouseout="this.style.opacity='1'">
                                            <svg viewBox="0 0 24 24" fill="white" style="width:13px;height:13px">
                                                <path
                                                    d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.67-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.076 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421-7.403h-.004c-1.425 0-2.835.356-4.06 1.031l-.291.169-3.015-.787.804 2.93-.189.301A7.002 7.002 0 003.02 9.414c0 3.866 3.113 7.012 6.938 7.012 1.893 0 3.672-.652 5.093-1.849 1.42-1.198 2.33-2.926 2.33-4.856 0-3.866-3.113-7.012-6.938-7.012m6.938 13.6H4.059A8.968 8.968 0 000 11.5C0 5.477 5.507 0.5 12 0.5s12 4.977 12 11-5.507 11-12 11z" />
                                            </svg>
                                            Send Wish
                                        </button>
                                    <?php else: ?>
                                        <span style="font-size:1.25rem;flex-shrink:0">🎉</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- ═══ ACTIVITY WIDGETS ═══ -->
                <div class="dash-section-label">Activity</div>
                <div class="dash-cols">
                    <div class="widget-card">
                        <div class="widget-title">Recent Inquiries</div>
                        <?php if (empty($recentInquiries)): ?>
                            <p class="no-data">No recent inquiries.</p>
                        <?php else: ?>
                            <table class="mini-table">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Course</th>
                                        <th>Date</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recentInquiries as $inq): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($inq['name']) ?></td>
                                            <td><?= htmlspecialchars($inq['course_interested'] ?? '—') ?></td>
                                            <td><?= date('d M', strtotime($inq['created_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                    <div class="widget-card">
                        <div class="widget-title">Popular Courses</div>
                        <?php if (empty($popularCourses)): ?>
                            <p class="no-data">No admissions yet.</p>
                        <?php else: ?>
                            <table class="mini-table">
                                <thead>
                                    <tr>
                                        <th>Course</th>
                                        <th>Students</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($popularCourses as $pc): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($pc['cname']) ?></td>
                                            <td><strong><?= (int) $pc['cnt'] ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ═══ ANALYTICS (charts) ═══ -->
                <div class="dash-section-label">Analytics</div>
                <div class="dash-cols">
                    <div class="widget-card">
                        <div class="widget-title">Monthly Admissions (Last 6 Months)</div>
                        <canvas id="admissionsChart" height="240"></canvas>
                    </div>
                    <div class="widget-card">
                        <div class="widget-title">Revenue (Last 6 Months)</div>
                        <canvas id="revenueChart" height="240"></canvas>
                    </div>
                </div>

                <!-- ═══ INSIGHTS WIDGETS ═══ -->
                <div class="dash-section-label">Insights</div>
                <div class="dash-widget-grid">
                    <!-- Recent Enrollments -->
                    <div class="dash-widget" style="animation-delay:.1s">
                        <div class="dash-widget-head">
                            <div class="dash-widget-title">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#4361ee"
                                    stroke-width="2">
                                    <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
                                    <circle cx="9" cy="7" r="4" />
                                    <path d="M22 11v2M19 8v8" />
                                </svg>
                                Recent Enrollments
                            </div>
                            <span class="dash-widget-badge"
                                style="background:#eef2ff;color:#4361ee"><?= count($recentEnrollments) ?></span>
                        </div>
                        <div class="dash-widget-body">
                            <?php if (empty($recentEnrollments)): ?>
                                <div class="dash-widget-empty">No enrollments yet</div>
                            <?php else: ?>
                                <?php foreach ($recentEnrollments as $re):
                                    $reInit = strtoupper(substr(trim($re['name']), 0, 1));
                                    ?>
                                    <div class="dash-widget-row">
                                        <div class="dash-widget-avatar">
                                            <?php if (!empty($re['photo'])): ?>
                                                <img src="../<?= htmlspecialchars($re['photo']) ?>"
                                                    alt="<?= htmlspecialchars($reInit) ?>"
                                                    onerror="this.style.display='none';this.parentElement.textContent='<?= $reInit ?>'">
                                            <?php else: ?>
                                                <?= $reInit ?>
                                            <?php endif; ?>
                                        </div>
                                        <div style="min-width:0;flex:1">
                                            <div class="dash-widget-name"><?= htmlspecialchars(trim($re['name'])) ?></div>
                                            <div class="dash-widget-meta"><?= htmlspecialchars($re['course']) ?> ·
                                                <?= htmlspecialchars($re['roll_no']) ?></div>
                                        </div>
                                        <div class="dash-widget-right">
                                            <div class="dash-widget-meta"><?= date('d M', strtotime($re['admission_date'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="dash-widget-footer">
                            <a href="students.php" class="dash-widget-link">View All Students →</a>
                        </div>
                    </div>

                    <!-- Pending Approvals -->
                    <div class="dash-widget" style="animation-delay:.2s">
                        <div class="dash-widget-head">
                            <div class="dash-widget-title">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#f59e0b"
                                    stroke-width="2">
                                    <circle cx="12" cy="12" r="10" />
                                    <polyline points="12 6 12 12 16 14" />
                                </svg>
                                Pending Approvals
                            </div>
                            <span class="dash-widget-badge"
                                style="background:#fef3c7;color:#92400e"><?= $pendingApprovalCount ?></span>
                        </div>
                        <div class="dash-widget-body">
                            <?php if (empty($pendingApprovals)): ?>
                                <div class="dash-widget-empty">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#d1d5db"
                                        stroke-width="1.5" style="width:32px;height:32px;margin-bottom:.5rem">
                                        <polyline points="20 6 9 17 4 12" />
                                    </svg><br>
                                    All caught up!
                                </div>
                            <?php else: ?>
                                <?php foreach ($pendingApprovals as $pa): ?>
                                    <div class="dash-widget-row">
                                        <div class="dash-widget-avatar"
                                            style="background:linear-gradient(135deg,#f59e0b,#d97706);border-radius:50%;width:36px;height:36px;font-size:.75rem">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                                stroke="#fff" stroke-width="2" style="width:16px;height:16px">
                                                <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7" />
                                                <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z" />
                                            </svg>
                                        </div>
                                        <div style="min-width:0;flex:1">
                                            <div class="dash-widget-name"><?= htmlspecialchars($pa['student_name']) ?></div>
                                            <div class="dash-widget-meta">Change: <?= htmlspecialchars($pa['field_label']) ?> →
                                                <?= htmlspecialchars(mb_strimwidth($pa['new_value'], 0, 20, '…')) ?></div>
                                        </div>
                                        <div class="dash-widget-right">
                                            <div class="dash-widget-meta"><?= date('d M', strtotime($pa['requested_at'])) ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="dash-widget-footer">
                            <a href="students.php" class="dash-widget-link">Manage Requests →</a>
                        </div>
                    </div>

                    <!-- Upcoming Due Fees -->
                    <div class="dash-widget" style="animation-delay:.3s">
                        <div class="dash-widget-head">
                            <div class="dash-widget-title">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#ef4444"
                                    stroke-width="2">
                                    <line x1="12" y1="1" x2="12" y2="23" />
                                    <path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6" />
                                </svg>
                                Upcoming Due Fees
                            </div>
                            <span class="dash-widget-badge"
                                style="background:#fee2e2;color:#991b1b"><?= count($upcomingDueFees) ?></span>
                        </div>
                        <div class="dash-widget-body">
                            <?php if (empty($upcomingDueFees)): ?>
                                <div class="dash-widget-empty">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#d1d5db"
                                        stroke-width="1.5" style="width:32px;height:32px;margin-bottom:.5rem">
                                        <polyline points="20 6 9 17 4 12" />
                                    </svg><br>
                                    All fees cleared!
                                </div>
                            <?php else: ?>
                                <?php foreach ($upcomingDueFees as $uf):
                                    $ufInit = strtoupper(substr(trim($uf['name']), 0, 1));
                                    $ufPct = floatval($uf['net_payable']) > 0 ? min(100, round((floatval($uf['fees_paid']) / floatval($uf['net_payable'])) * 100)) : 0;
                                    ?>
                                    <div class="dash-widget-row"
                                        onclick="window.location='collect_fees.php?id=<?= (int) $uf['id'] ?>'"
                                        style="cursor:pointer">
                                        <div class="dash-widget-avatar"
                                            style="background:linear-gradient(135deg,#ef4444,#dc2626)">
                                            <?php if (!empty($uf['photo'])): ?>
                                                <img src="../<?= htmlspecialchars($uf['photo']) ?>"
                                                    alt="<?= htmlspecialchars($ufInit) ?>"
                                                    onerror="this.style.display='none';this.parentElement.textContent='<?= $ufInit ?>'">
                                            <?php else: ?>
                                                <?= $ufInit ?>
                                            <?php endif; ?>
                                        </div>
                                        <div style="min-width:0;flex:1">
                                            <div class="dash-widget-name"><?= htmlspecialchars(trim($uf['name'])) ?></div>
                                            <div class="dash-widget-meta"><?= htmlspecialchars($uf['course']) ?></div>
                                            <div
                                                style="margin-top:.35rem;height:4px;background:#e5e7eb;border-radius:999px;overflow:hidden;width:100%;max-width:120px">
                                                <div
                                                    style="height:100%;border-radius:999px;background:<?= $ufPct > 50 ? 'linear-gradient(90deg,#f59e0b,#d97706)' : 'linear-gradient(90deg,#ef4444,#dc2626)' ?>;width:<?= $ufPct ?>%">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="dash-widget-right">
                                            <div class="dash-widget-amount" style="color:#be123c">
                                                ₹<?= number_format(floatval($uf['fees_pending']), 0) ?></div>
                                            <div class="dash-widget-meta">pending</div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="dash-widget-footer">
                            <a href="fees.php" class="dash-widget-link">View All Fees →</a>
                        </div>
                    </div>
                </div>

                <!-- ═══ CALENDAR + QUICK ACTIONS ═══ -->
                <div class="dash-section-label">Calendar &amp; Quick Actions</div>
                <div class="dash-cols">
                    <div class="widget-card">
                        <div class="widget-title"><?= date('F Y') ?></div>
                        <div class="cal-grid" id="miniCal"></div>
                    </div>
                    <div class="widget-card">
                        <div class="widget-title">Quick Actions</div>
                        <div class="actions-grid" style="grid-template-columns: repeat(3, 1fr); gap: .75rem;">
                            <?php
                            $qa = [
                                ['inquiries.php', 'M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z', 'New Inquiry'],
                                ['new_admission.php', 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 7a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM19 8v6M22 11h-6', 'New Admission'],
                                ['fees.php', 'M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6', 'Collect Fees'],
                                ['students.php', 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 7a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75', 'Students'],
                                ['notifications.php', 'M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0', 'Notifications'],
                                ['pay_share.php', 'M1 4h22v16H1zM1 10h22', 'Pay Share'],
                            ];
                            foreach ($qa as [$href, $path, $label]):
                                ?>
                                <a href="<?= $href ?>" class="action-card" style="padding:1.1rem .75rem;gap:.5rem">
                                    <div class="action-icon" style="width:42px;height:42px">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                                            stroke="currentColor" stroke-width="2">
                                            <path d="<?= $path ?>" />
                                        </svg>
                                    </div>
                                    <h4 style="font-size:.78rem"><?= $label ?></h4>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

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
        Chart.defaults.font.family = "'Sora', 'Inter', sans-serif";
        Chart.defaults.font.weight = 600;

        // ── Enhanced Admissions Chart ──
        (function () {
            const ctx = document.getElementById('admissionsChart').getContext('2d');
            const grad = ctx.createLinearGradient(0, 0, 0, 280);
            grad.addColorStop(0, 'rgba(99, 102, 241, 0.85)');
            grad.addColorStop(1, 'rgba(139, 92, 246, 0.55)');
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?= json_encode($monthlyLabels ?: ['No data']) ?>,
                    datasets: [{
                        label: 'Admissions', data: <?= json_encode($monthlyData ?: [0]) ?>,
                        backgroundColor: grad, hoverBackgroundColor: 'rgba(99,102,241,1)',
                        borderRadius: 10, borderSkipped: false, maxBarThickness: 48
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: true,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#1e293b', titleFont: { weight: 800, size: 13 }, bodyFont: { size: 12 },
                            padding: { x: 14, y: 10 }, cornerRadius: 10, displayColors: false,
                            callbacks: { label: c => c.parsed.y + ' admission' + (c.parsed.y !== 1 ? 's' : '') }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1, color: '#9ca3af', font: { size: 11 } }, grid: { color: '#f1f5f9', drawBorder: false } },
                        x: { ticks: { color: '#6b7280', font: { size: 11, weight: 700 } }, grid: { display: false } }
                    }
                }
            });
        })();

        // ── Enhanced Revenue Chart ──
        (function () {
            const ctx = document.getElementById('revenueChart').getContext('2d');
            const grad = ctx.createLinearGradient(0, 0, 0, 280);
            grad.addColorStop(0, 'rgba(16, 185, 129, 0.25)');
            grad.addColorStop(1, 'rgba(16, 185, 129, 0.02)');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?= json_encode($revenueLabels ?: ['No data']) ?>,
                    datasets: [{
                        label: 'Revenue (₹)', data: <?= json_encode($revenueData ?: [0]) ?>,
                        borderColor: '#10b981', backgroundColor: grad, borderWidth: 3,
                        pointBackgroundColor: '#fff', pointBorderColor: '#10b981', pointBorderWidth: 2.5,
                        pointRadius: 5, pointHoverRadius: 8, fill: true, tension: 0.4
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: true,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#1e293b', titleFont: { weight: 800, size: 13 }, bodyFont: { size: 12 },
                            padding: { x: 14, y: 10 }, cornerRadius: 10, displayColors: false,
                            callbacks: { label: c => '₹' + c.parsed.y.toLocaleString('en-IN') }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { color: '#9ca3af', font: { size: 11 }, callback: v => '₹' + v.toLocaleString('en-IN') }, grid: { color: '#f1f5f9', drawBorder: false } },
                        x: { ticks: { color: '#6b7280', font: { size: 11, weight: 700 } }, grid: { display: false } }
                    }
                }
            });
        })();

        (function () {
            const now = new Date(), y = now.getFullYear(), m = now.getMonth(), t = now.getDate();
            const fd = new Date(y, m, 1).getDay(), dim = new Date(y, m + 1, 0).getDate();
            const days = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
            let h = '';
            days.forEach(d => h += `<div class="cal-day-name">${d}</div>`);
            for (let i = 0; i < fd; i++) h += '<div class="cal-day empty"></div>';
            for (let d = 1; d <= dim; d++) h += `<div class="cal-day${d === t ? ' today' : ''}">${d}</div>`;
            document.getElementById('miniCal').innerHTML = h;
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
                lbl.innerHTML = `<span class="dot dot-gray"></span>${labels[mode]}`;
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