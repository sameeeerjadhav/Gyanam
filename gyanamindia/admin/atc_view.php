<?php
/**
 * Gyanam Portal — Admin: View ATC Center (dedicated page)
 * URL: atc_view.php?id=123
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
ensureAtcFranchisePaymentSchema($pdo);

$atcId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($atcId <= 0) {
    header('Location: atc_centers.php?err=' . urlencode('Invalid ATC'));
    exit;
}

$stmt = $pdo->prepare("
    SELECT atc.*, dlc.name AS dlc_name, dlc.district AS dlc_district
    FROM atc_centers atc
    LEFT JOIN dlc_offices dlc ON atc.dlc_id = dlc.id
    WHERE atc.id = ?
    LIMIT 1
");
$stmt->execute([$atcId]);
$atc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$atc) {
    header('Location: atc_centers.php?err=' . urlencode('ATC not found'));
    exit;
}

$trainingUser = null;
try {
    $tStmt = $pdo->prepare("SELECT username, password FROM users WHERE role='Training' AND atc_id=? LIMIT 1");
    $tStmt->execute([$atcId]);
    $trainingUser = $tStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Exception $e) {}

// Inquiry stats
$inquiryStats = [
    'total_inquiries' => 0,
    'pending_inquiries' => 0,
    'contacted_inquiries' => 0,
    'converted_inquiries' => 0,
    'walkin_inquiries' => 0,
    'telephonic_inquiries' => 0,
];
$telephonicTotal = 0;
try {
    $checkColumn = $pdo->query("SHOW COLUMNS FROM inquiries LIKE 'inquiry_type'")->fetch();
    if ($checkColumn) {
        $st = $pdo->prepare("
            SELECT
                COUNT(*) AS total_inquiries,
                SUM(CASE WHEN status = 'New' THEN 1 ELSE 0 END) AS pending_inquiries,
                SUM(CASE WHEN status = 'Contacted' THEN 1 ELSE 0 END) AS contacted_inquiries,
                SUM(CASE WHEN status = 'Converted' THEN 1 ELSE 0 END) AS converted_inquiries,
                SUM(CASE WHEN inquiry_type = 'Walk-in' THEN 1 ELSE 0 END) AS walkin_inquiries,
                SUM(CASE WHEN inquiry_type = 'Telephonic' THEN 1 ELSE 0 END) AS telephonic_inquiries
            FROM inquiries WHERE atc_id = ?
        ");
        $st->execute([$atcId]);
        $inquiryStats = $st->fetch(PDO::FETCH_ASSOC) ?: $inquiryStats;
        $telephonicTotal = (int)($inquiryStats['telephonic_inquiries'] ?? 0);
    } else {
        $st = $pdo->prepare("
            SELECT
                COUNT(*) AS total_inquiries,
                SUM(CASE WHEN status = 'New' THEN 1 ELSE 0 END) AS pending_inquiries,
                SUM(CASE WHEN status = 'Contacted' THEN 1 ELSE 0 END) AS contacted_inquiries,
                SUM(CASE WHEN status = 'Converted' THEN 1 ELSE 0 END) AS converted_inquiries
            FROM inquiries WHERE atc_id = ?
        ");
        $st->execute([$atcId]);
        $inquiryStats = $st->fetch(PDO::FETCH_ASSOC) ?: $inquiryStats;
    }
} catch (Exception $e) {}

// Admission stats
$admissionStats = [
    'total_admissions' => 0,
    'active_students' => 0,
    'completed_students' => 0,
    'inactive_students' => 0,
    'total_fees' => 0,
    'total_collected' => 0,
    'total_pending' => 0,
];
try {
    $st = $pdo->prepare("
        SELECT
            COUNT(*) AS total_admissions,
            SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) AS active_students,
            SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) AS completed_students,
            SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END) AS inactive_students,
            COALESCE(SUM(course_fees - discount_amount), 0) AS total_fees,
            COALESCE(SUM(fees_paid), 0) AS total_collected,
            COALESCE(SUM(fees_pending), 0) AS total_pending
        FROM admissions WHERE atc_id = ?
    ");
    $st->execute([$atcId]);
    $admissionStats = $st->fetch(PDO::FETCH_ASSOC) ?: $admissionStats;
} catch (Exception $e) {}

$collectionRate = ((float)($admissionStats['total_fees'] ?? 0) > 0)
    ? round(((float)$admissionStats['total_collected'] / (float)$admissionStats['total_fees']) * 100, 1)
    : 0;

// Recent students + course breakdown
$recentStudents = [];
$courseBreakdown = [];
try {
    $checkColumns = $pdo->query('SHOW COLUMNS FROM admissions')->fetchAll(PDO::FETCH_COLUMN);
    $hasRegistrationId = in_array('registration_id', $checkColumns, true);
    $hasRollNo = in_array('roll_no', $checkColumns, true);
    $hasFirstName = in_array('first_name', $checkColumns, true);
    $hasCourse = in_array('course', $checkColumns, true);

    $studentIdCol = $hasRegistrationId ? 'registration_id' : ($hasRollNo ? 'roll_no' : 'id');
    $studentNameCol = $hasFirstName
        ? "CONCAT(first_name, ' ', COALESCE(middle_name, ''), ' ', last_name)"
        : "'Unknown'";
    $courseCol = $hasCourse ? 'course' : "'N/A'";

    $st = $pdo->prepare("
        SELECT {$studentIdCol} AS student_id, roll_no,
               {$studentNameCol} AS student_name,
               {$courseCol} AS course_name,
               admission_date, status, course_fees, fees_paid, fees_pending
        FROM admissions
        WHERE atc_id = ?
        ORDER BY admission_date DESC
        LIMIT 10
    ");
    $st->execute([$atcId]);
    $recentStudents = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("
        SELECT {$courseCol} AS course_name,
               COUNT(*) AS student_count,
               SUM(course_fees - discount_amount) AS total_fees,
               SUM(fees_paid) AS collected,
               SUM(fees_pending) AS pending
        FROM admissions
        WHERE atc_id = ? AND status = 'Active'
        GROUP BY {$courseCol}
        ORDER BY student_count DESC
    ");
    $st->execute([$atcId]);
    $courseBreakdown = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$money = static function ($n): string {
    return '₹' . number_format((float)$n, 0, '.', ',');
};
$locParts = array_filter([
    trim((string)($atc['city'] ?? '')),
    trim((string)($atc['district'] ?? '')),
    trim((string)($atc['state'] ?? '')),
], static fn($x) => $x !== '');
$location = implode(', ', $locParts) ?: '—';
$status = (string)($atc['status'] ?? 'Active');
$statusClass = strtolower($status) === 'active' ? 'active' : 'inactive';
$atcCode = trim((string)($atc['atc_code'] ?? '')) ?: trim((string)($atc['login_username'] ?? ''));
$totalInquiries = (int)($inquiryStats['total_inquiries'] ?? 0) + $telephonicTotal;

$pageTitle = $atc['name'] ?? 'ATC Details';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?> — Admin | Gyanam India</title>
<link rel="stylesheet" href="../assets/css/global.css">
<link rel="stylesheet" href="../assets/css/dashboard.css">
<link rel="stylesheet" href="../assets/css/management.css">
<?php if (file_exists(__DIR__.'/../assets/css/notifications.css')): ?>
<link rel="stylesheet" href="../assets/css/notifications.css">
<?php endif; ?>
<style>
:root { --font: 'Sora', system-ui, sans-serif; }
.page-wrap { width:100%; max-width:none; margin:0; }
.back-link {
    display:inline-flex; align-items:center; gap:.35rem; font-size:.82rem; font-weight:700;
    color:#4361ee; text-decoration:none; margin-bottom:.85rem;
}
.view-hero {
    background:#fff; border:1.5px solid var(--border-color,#e5e7eb); border-radius:16px;
    padding:1.25rem 1.5rem; margin-bottom:1.15rem;
    display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;
    box-shadow:0 4px 16px rgba(15,23,42,.04);
}
.view-hero h1 { margin:0; font-size:1.25rem; font-weight:800; color:#0f172a; letter-spacing:-.02em; }
.view-hero .sub { margin:.25rem 0 0; font-size:.82rem; color:#64748b; font-weight:500; }
.hero-actions { display:flex; gap:.55rem; flex-wrap:wrap; }
.btn-soft, .btn-main {
    height:38px; padding:0 1rem; border-radius:10px; font-size:.82rem; font-weight:700;
    display:inline-flex; align-items:center; gap:.35rem; text-decoration:none; font-family:inherit; cursor:pointer;
}
.btn-soft { border:1.5px solid #e5e7eb; background:#fff; color:#475569; }
.btn-main { border:none; background:linear-gradient(135deg,#4361ee,#3730a3); color:#fff; box-shadow:0 3px 12px rgba(67,97,238,.25); }
.status-pill {
    display:inline-flex; align-items:center; gap:.35rem; padding:.25rem .7rem; border-radius:999px;
    font-size:.72rem; font-weight:800; text-transform:uppercase; letter-spacing:.04em;
}
.status-pill.active { background:#ecfdf5; color:#047857; border:1px solid #a7f3d0; }
.status-pill.inactive { background:#f1f5f9; color:#64748b; border:1px solid #e2e8f0; }
.status-pill .dot { width:6px; height:6px; border-radius:50%; background:currentColor; }
.card {
    background:#fff; border:1.5px solid var(--border-color,#e5e7eb); border-radius:16px;
    padding:1.2rem 1.35rem 1.35rem; margin-bottom:1.1rem; box-shadow:0 4px 16px rgba(15,23,42,.03);
}
.card-title {
    display:flex; align-items:center; gap:.5rem; font-size:.78rem; font-weight:800;
    text-transform:uppercase; letter-spacing:.06em; color:#64748b; margin:0 0 1rem;
    padding-bottom:.7rem; border-bottom:1px solid #eef2f7;
}
.card-title svg { width:16px; height:16px; color:#4361ee; }
.details-grid {
    display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:.85rem 1.1rem;
}
.details-grid .full { grid-column:1 / -1; }
.detail-label { font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; margin-bottom:.2rem; }
.detail-value { font-size:.9rem; font-weight:650; color:#0f172a; word-break:break-word; }
.mono { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-weight:800; color:#4361ee; }
.stats-grid {
    display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.85rem;
}
.stat-card {
    border-radius:14px; padding:1rem 1.1rem; color:#fff; min-height:110px;
    display:flex; flex-direction:column; justify-content:space-between;
    box-shadow:0 8px 20px rgba(15,23,42,.12);
}
.stat-card .lbl { font-size:.75rem; font-weight:700; opacity:.9; }
.stat-card .val { font-size:1.45rem; font-weight:800; letter-spacing:-.02em; margin-top:.35rem; }
.stat-card .sub { font-size:.72rem; opacity:.85; margin-top:.25rem; }
.s1 { background:linear-gradient(135deg,#6366f1,#8b5cf6); }
.s2 { background:linear-gradient(135deg,#10b981,#059669); }
.s3 { background:linear-gradient(135deg,#f59e0b,#d97706); }
.s4 { background:linear-gradient(135deg,#3b82f6,#2563eb); }
.table-wrap { overflow:auto; border:1px solid #eef2f7; border-radius:12px; }
.data-table { width:100%; border-collapse:collapse; font-size:.84rem; }
.data-table th {
    text-align:left; padding:.7rem .85rem; background:#f8fafc; color:#64748b;
    font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; font-weight:800;
    border-bottom:1px solid #e5e7eb; white-space:nowrap;
}
.data-table td { padding:.7rem .85rem; border-bottom:1px solid #f1f5f9; color:#334155; vertical-align:middle; }
.data-table tr:last-child td { border-bottom:none; }
.fee-ok { color:#059669; font-weight:700; }
.fee-pending { color:#d97706; font-weight:700; }
.empty {
    text-align:center; padding:2rem 1rem; color:#94a3b8; font-size:.88rem;
}
@media (max-width:1100px) {
    .details-grid, .stats-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
}
@media (max-width:720px) {
    .details-grid, .stats-grid { grid-template-columns:1fr; }
}
</style>
</head>
<body>
<div class="dashboard-layout">
<?php include __DIR__ . '/sidebar.php'; ?>
<main class="main-content">
    <header class="top-header">
        <div class="header-left">
            <button class="hamburger" id="hamburgerBtn" type="button" aria-label="Toggle sidebar">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
            <div class="header-greeting">
                <h2>ATC Details</h2>
                <p>Full center profile, credentials &amp; performance</p>
            </div>
        </div>
        <div class="header-right">
            <?php if (file_exists(__DIR__.'/../includes/notification_bell.php')) include __DIR__.'/../includes/notification_bell.php'; ?>
            <?php include __DIR__.'/../includes/profile_dropdown.php'; ?>
        </div>
    </header>

    <div class="page-content">
        <div class="page-wrap">
            <a class="back-link" href="atc_centers.php">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"/></svg>
                Back to ATC Logins
            </a>

            <div class="view-hero">
                <div>
                    <h1><?= htmlspecialchars((string)$atc['name']) ?></h1>
                    <div class="sub">
                        <?= htmlspecialchars($location) ?>
                        <?php if (!empty($atc['center_type'])): ?>
                            · <?= htmlspecialchars((string)$atc['center_type']) ?>
                        <?php endif; ?>
                        <?php if ($atcCode !== ''): ?>
                            · Code <?= htmlspecialchars($atcCode) ?>
                        <?php endif; ?>
                    </div>
                    <div style="margin-top:.55rem">
                        <span class="status-pill <?= htmlspecialchars($statusClass) ?>"><span class="dot"></span><?= htmlspecialchars($status) ?></span>
                    </div>
                </div>
                <div class="hero-actions">
                    <a class="btn-soft" href="atc_form.php?id=<?= (int)$atcId ?>">Edit ATC</a>
                    <?php foreach (atcAuthCertificateVariants($atc['center_type'] ?? '') as $cv): ?>
                        <a class="btn-soft" target="_blank" href="generate_auth_certificate.php?atc_id=<?= (int)$atcId ?>&variant=<?= urlencode($cv['variant']) ?>">
                            <?= htmlspecialchars($cv['label']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                    Center Information
                </div>
                <div class="details-grid">
                    <div>
                        <div class="detail-label">ATC Name</div>
                        <div class="detail-value"><?= htmlspecialchars((string)$atc['name']) ?></div>
                    </div>
                    <div>
                        <div class="detail-label">DLC Office</div>
                        <div class="detail-value"><?= htmlspecialchars((string)($atc['dlc_name'] ?? '—')) ?></div>
                    </div>
                    <div>
                        <div class="detail-label">Location</div>
                        <div class="detail-value"><?= htmlspecialchars($location) ?></div>
                    </div>
                    <?php if ($atc['dlc_share_amount'] !== null && $atc['dlc_share_amount'] !== ''): ?>
                    <div>
                        <div class="detail-label">DLC Share Amount</div>
                        <div class="detail-value"><?= $money($atc['dlc_share_amount']) ?></div>
                    </div>
                    <?php endif; ?>
                    <div>
                        <div class="detail-label">Contact Person</div>
                        <div class="detail-value"><?= htmlspecialchars((string)($atc['contact_person'] ?: '—')) ?></div>
                    </div>
                    <div>
                        <div class="detail-label">Mobile</div>
                        <div class="detail-value"><?= htmlspecialchars((string)($atc['mobile'] ?: '—')) ?></div>
                    </div>
                    <div>
                        <div class="detail-label">Email</div>
                        <div class="detail-value"><?= htmlspecialchars((string)($atc['email'] ?: '—')) ?></div>
                    </div>
                    <?php if (!empty($atc['address'])): ?>
                    <div class="full">
                        <div class="detail-label">Address</div>
                        <div class="detail-value"><?= htmlspecialchars((string)$atc['address']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($atc['franchise_payment_mode'])): ?>
                    <div>
                        <div class="detail-label">Payment Done By</div>
                        <div class="detail-value"><?= htmlspecialchars((string)$atc['franchise_payment_mode']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($atc['franchise_fees'] !== null && $atc['franchise_fees'] !== ''): ?>
                    <div>
                        <div class="detail-label">Franchise Fees</div>
                        <div class="detail-value"><?= $money($atc['franchise_fees']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($atc['franchise_amount_received'] !== null && $atc['franchise_amount_received'] !== ''): ?>
                    <div>
                        <div class="detail-label">Amount Received</div>
                        <div class="detail-value"><?= $money($atc['franchise_amount_received']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($atc['franchise_paid_date'])): ?>
                    <div>
                        <div class="detail-label">Date of Amount Received</div>
                        <div class="detail-value"><?= htmlspecialchars((string)$atc['franchise_paid_date']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($atc['franchise_payment_ref'])): ?>
                    <div>
                        <div class="detail-label">Cheque / UPI / Reference No.</div>
                        <div class="detail-value"><?= htmlspecialchars((string)$atc['franchise_payment_ref']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    Login Credentials
                </div>
                <div class="details-grid">
                    <div>
                        <div class="detail-label">ATC Code / Username</div>
                        <div class="detail-value mono"><?= htmlspecialchars($atcCode !== '' ? $atcCode : '—') ?></div>
                    </div>
                    <div>
                        <div class="detail-label">Password</div>
                        <div class="detail-value mono" style="color:#0f172a"><?= htmlspecialchars((string)($atc['login_password'] ?: '—')) ?></div>
                    </div>
                    <?php if ($trainingUser): ?>
                    <div>
                        <div class="detail-label">Training Username</div>
                        <div class="detail-value mono"><?= htmlspecialchars((string)$trainingUser['username']) ?></div>
                    </div>
                    <div>
                        <div class="detail-label">Training Password</div>
                        <div class="detail-value mono" style="color:#0f172a"><?= htmlspecialchars((string)($trainingUser['password'] ?: '—')) ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="full">
                        <div class="detail-label">Login tip</div>
                        <div class="detail-value" style="font-size:.85rem;color:#64748b;font-weight:500">
                            Username is the ATC Code. On the portal login page, select <strong>ATC Login</strong>.
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    Statistics Overview
                </div>
                <div class="stats-grid">
                    <div class="stat-card s1">
                        <div class="lbl">Total Admissions</div>
                        <div class="val"><?= (int)($admissionStats['total_admissions'] ?? 0) ?></div>
                        <div class="sub">Active: <?= (int)($admissionStats['active_students'] ?? 0) ?></div>
                    </div>
                    <div class="stat-card s2">
                        <div class="lbl">Total Collected</div>
                        <div class="val"><?= $money($admissionStats['total_collected'] ?? 0) ?></div>
                        <div class="sub"><?= $collectionRate ?>% of total</div>
                    </div>
                    <div class="stat-card s3">
                        <div class="lbl">Pending Fees</div>
                        <div class="val"><?= $money($admissionStats['total_pending'] ?? 0) ?></div>
                        <div class="sub">Outstanding amount</div>
                    </div>
                    <div class="stat-card s4">
                        <div class="lbl">Total Inquiries</div>
                        <div class="val"><?= $totalInquiries ?></div>
                        <div class="sub">Walk-in + Telephonic</div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                    Course-wise Breakdown
                </div>
                <?php if (empty($courseBreakdown)): ?>
                    <div class="empty">No active course enrollments yet.</div>
                <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Course Name</th>
                                <th>Students</th>
                                <th>Total Fees</th>
                                <th>Collected</th>
                                <th>Pending</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($courseBreakdown as $course): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars((string)$course['course_name']) ?></strong></td>
                                <td><?= (int)$course['student_count'] ?></td>
                                <td><?= $money($course['total_fees'] ?? 0) ?></td>
                                <td class="fee-ok"><?= $money($course['collected'] ?? 0) ?></td>
                                <td class="fee-pending"><?= $money($course['pending'] ?? 0) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <div class="card">
                <div class="card-title">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Recent Students (Last 10)
                </div>
                <?php if (empty($recentStudents)): ?>
                    <div class="empty">No students admitted yet.</div>
                <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Course</th>
                                <th>Admission Date</th>
                                <th>Fees Paid</th>
                                <th>Pending</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentStudents as $student):
                            $stuStatus = (string)($student['status'] ?? '');
                            $stuClass = strtolower($stuStatus) === 'active' ? 'active' : 'inactive';
                            $admDate = !empty($student['admission_date']) ? date('d M Y', strtotime($student['admission_date'])) : '—';
                        ?>
                            <tr>
                                <td><?= htmlspecialchars((string)$student['student_id']) ?></td>
                                <td><strong><?= htmlspecialchars(trim(preg_replace('/\s+/', ' ', (string)$student['student_name']))) ?></strong></td>
                                <td><?= htmlspecialchars((string)$student['course_name']) ?></td>
                                <td><?= htmlspecialchars($admDate) ?></td>
                                <td class="fee-ok"><?= $money($student['fees_paid'] ?? 0) ?></td>
                                <td class="fee-pending"><?= $money($student['fees_pending'] ?? 0) ?></td>
                                <td><span class="status-pill <?= $stuClass ?>"><span class="dot"></span><?= htmlspecialchars($stuStatus) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
</div>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
