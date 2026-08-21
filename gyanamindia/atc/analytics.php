<?php
/**
 * Gyanam Portal — ATC: Analytics Dashboard
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$userName = sanitize(getUserName());
$atcId = $_SESSION['atc_id'] ?? null;

// Date range filter
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

// Get overall statistics
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total_students,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_students,
        SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END) as inactive_students,
        SUM(CASE WHEN gender = 'Male' THEN 1 ELSE 0 END) as male_students,
        SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END) as female_students,
        SUM(CASE WHEN photo IS NOT NULL AND photo != '' THEN 1 ELSE 0 END) as students_with_photo
    FROM admissions 
    WHERE atc_id = ?
");
$stmt->execute([$atcId]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get fees statistics
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(course_fees), 0) as total_fees,
        COALESCE(SUM(fees_paid), 0) as total_collected,
        COALESCE(SUM(fees_pending), 0) as total_pending,
        COUNT(CASE WHEN fees_pending <= 0 THEN 1 END) as fully_paid_count,
        COUNT(CASE WHEN fees_paid > 0 AND fees_pending > 0 THEN 1 END) as partial_paid_count,
        COUNT(CASE WHEN fees_paid = 0 THEN 1 END) as not_paid_count
    FROM admissions 
    WHERE atc_id = ? AND status = 'Active'
");
$stmt->execute([$atcId]);
$feesStats = $stmt->fetch(PDO::FETCH_ASSOC);

// Get course-wise distribution
$stmt = $pdo->prepare("
    SELECT 
        course,
        COUNT(*) as student_count,
        SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active_count,
        COALESCE(SUM(course_fees), 0) as total_fees,
        COALESCE(SUM(fees_paid), 0) as collected_fees
    FROM admissions 
    WHERE atc_id = ?
    GROUP BY course
    ORDER BY student_count DESC
");
$stmt->execute([$atcId]);
$courseDistribution = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly admissions trend (last 6 months)
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(admission_date, '%Y-%m') as month,
        COUNT(*) as admission_count
    FROM admissions 
    WHERE atc_id = ? AND admission_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(admission_date, '%Y-%m')
    ORDER BY month ASC
");
$stmt->execute([$atcId]);
$monthlyAdmissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly fees collection trend (last 6 months)
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(fp.payment_date, '%Y-%m') as month,
        COUNT(*) as payment_count,
        COALESCE(SUM(fp.amount), 0) as total_collected
    FROM fee_payments fp
    JOIN admissions a ON fp.admission_id = a.id
    WHERE a.atc_id = ? AND fp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(fp.payment_date, '%Y-%m')
    ORDER BY month ASC
");
$stmt->execute([$atcId]);
$monthlyFees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent admissions
$stmt = $pdo->prepare("
    SELECT * FROM admissions 
    WHERE atc_id = ? 
    ORDER BY admission_date DESC 
    LIMIT 5
");
$stmt->execute([$atcId]);
$recentAdmissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get top performing courses by revenue
$stmt = $pdo->prepare("
    SELECT 
        course,
        COUNT(*) as enrollments,
        COALESCE(SUM(fees_paid), 0) as revenue
    FROM admissions 
    WHERE atc_id = ? AND status = 'Active'
    GROUP BY course
    ORDER BY revenue DESC
    LIMIT 5
");
$stmt->execute([$atcId]);
$topCourses = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics — ATC Center | Gyanam India</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="stylesheet" href="../assets/css/analytics.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📊</text></svg>">
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
                    <h2>Analytics Dashboard</h2>
                    <p>Insights and performance metrics</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="analytics-container">
            
            <!-- Overview KPI Cards -->
            <div class="analytics-kpi-grid">
                <div class="analytics-kpi-card kpi-blue">
                    <div class="kpi-icon-wrap">
                        <div class="kpi-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><polyline points="17 11 19 13 23 9"/></svg>
                        </div>
                    </div>
                    <div class="kpi-content">
                        <div class="kpi-value"><?= $stats['total_students'] ?></div>
                        <div class="kpi-label">Total Students</div>
                        <div class="kpi-sub"><?= $stats['active_students'] ?> Active • <?= $stats['inactive_students'] ?> Inactive</div>
                    </div>
                </div>

                <div class="analytics-kpi-card kpi-green">
                    <div class="kpi-icon-wrap">
                        <div class="kpi-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        </div>
                    </div>
                    <div class="kpi-content">
                        <div class="kpi-value">₹<?= number_format($feesStats['total_collected'] / 1000, 1) ?>K</div>
                        <div class="kpi-label">Fees Collected</div>
                        <div class="kpi-sub">₹<?= number_format($feesStats['total_pending'] / 1000, 1) ?>K Pending</div>
                    </div>
                </div>

                <div class="analytics-kpi-card kpi-purple">
                    <div class="kpi-icon-wrap">
                        <div class="kpi-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                        </div>
                    </div>
                    <div class="kpi-content">
                        <div class="kpi-value"><?= count($courseDistribution) ?></div>
                        <div class="kpi-label">Active Courses</div>
                        <div class="kpi-sub"><?= $stats['total_students'] ?> Enrollments</div>
                    </div>
                </div>

                <div class="analytics-kpi-card kpi-amber">
                    <div class="kpi-icon-wrap">
                        <div class="kpi-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                        </div>
                    </div>
                    <div class="kpi-content">
                        <div class="kpi-value"><?= $feesStats['total_fees'] > 0 ? number_format(($feesStats['total_collected'] / $feesStats['total_fees']) * 100, 1) : 0 ?>%</div>
                        <div class="kpi-label">Collection Rate</div>
                        <div class="kpi-sub"><?= $feesStats['fully_paid_count'] ?> Fully Paid</div>
                    </div>
                </div>
            </div>

            <!-- Charts Row -->
            <div class="analytics-charts-row">
                <!-- Monthly Admissions Chart -->
                <div class="analytics-chart-card">
                    <div class="chart-header">
                        <div class="chart-title">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/></svg>
                            <span>Monthly Admissions</span>
                        </div>
                        <span class="chart-badge">Last 6 Months</span>
                    </div>
                    <div class="chart-body">
                        <?php if (empty($monthlyAdmissions)): ?>
                            <div class="chart-empty">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                <p>No data available</p>
                            </div>
                        <?php else: ?>
                            <div class="bar-chart">
                                <?php 
                                $maxAdmissions = max(array_column($monthlyAdmissions, 'admission_count'));
                                foreach ($monthlyAdmissions as $month): 
                                    $percentage = $maxAdmissions > 0 ? ($month['admission_count'] / $maxAdmissions) * 100 : 0;
                                    $monthName = date('M', strtotime($month['month'] . '-01'));
                                ?>
                                    <div class="bar-item">
                                        <div class="bar-wrapper">
                                            <div class="bar-fill" style="height: <?= $percentage ?>%;" data-value="<?= $month['admission_count'] ?>"></div>
                                        </div>
                                        <div class="bar-label"><?= $monthName ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Monthly Fees Collection Chart -->
                <div class="analytics-chart-card">
                    <div class="chart-header">
                        <div class="chart-title">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                            <span>Fees Collection</span>
                        </div>
                        <span class="chart-badge">Last 6 Months</span>
                    </div>
                    <div class="chart-body">
                        <?php if (empty($monthlyFees)): ?>
                            <div class="chart-empty">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                <p>No data available</p>
                            </div>
                        <?php else: ?>
                            <div class="bar-chart">
                                <?php 
                                $maxCollection = max(array_column($monthlyFees, 'total_collected'));
                                foreach ($monthlyFees as $month): 
                                    $percentage = $maxCollection > 0 ? ($month['total_collected'] / $maxCollection) * 100 : 0;
                                    $monthName = date('M', strtotime($month['month'] . '-01'));
                                ?>
                                    <div class="bar-item">
                                        <div class="bar-wrapper">
                                            <div class="bar-fill bar-green" style="height: <?= $percentage ?>%;" data-value="₹<?= number_format($month['total_collected'] / 1000, 1) ?>K"></div>
                                        </div>
                                        <div class="bar-label"><?= $monthName ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Course Distribution & Top Courses -->
            <div class="analytics-grid-2">
                <!-- Course Distribution -->
                <div class="analytics-card">
                    <div class="card-header">
                        <div class="card-title">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="2" x2="12" y2="12"/><line x1="12" y1="12" x2="16" y2="16"/></svg>
                            <span>Course Distribution</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($courseDistribution)): ?>
                            <div class="empty-state">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                <p>No courses found</p>
                            </div>
                        <?php else: ?>
                            <div class="course-list">
                                <?php foreach ($courseDistribution as $course): 
                                    $percentage = $stats['total_students'] > 0 ? ($course['student_count'] / $stats['total_students']) * 100 : 0;
                                ?>
                                    <div class="course-item">
                                        <div class="course-info">
                                            <div class="course-name"><?= htmlspecialchars($course['course']) ?></div>
                                            <div class="course-stats"><?= $course['student_count'] ?> students • ₹<?= number_format($course['collected_fees'] / 1000, 1) ?>K</div>
                                        </div>
                                        <div class="course-progress">
                                            <div class="progress-bar">
                                                <div class="progress-fill" style="width: <?= $percentage ?>%;"></div>
                                            </div>
                                            <span class="progress-percent"><?= number_format($percentage, 0) ?>%</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Top Performing Courses -->
                <div class="analytics-card">
                    <div class="card-header">
                        <div class="card-title">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            <span>Top Performing Courses</span>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($topCourses)): ?>
                            <div class="empty-state">
                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                <p>No data available</p>
                            </div>
                        <?php else: ?>
                            <div class="top-courses-list">
                                <?php foreach ($topCourses as $index => $course): ?>
                                    <div class="top-course-item">
                                        <div class="rank-badge rank-<?= $index + 1 ?>"><?= $index + 1 ?></div>
                                        <div class="top-course-info">
                                            <div class="top-course-name"><?= htmlspecialchars($course['course']) ?></div>
                                            <div class="top-course-meta">
                                                <span><?= $course['enrollments'] ?> enrollments</span>
                                                <span class="revenue">₹<?= number_format($course['revenue']) ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Gender & Payment Status Distribution -->
            <div class="analytics-grid-3">
                <!-- Gender Distribution -->
                <div class="analytics-stat-card">
                    <div class="stat-header">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span>Gender Distribution</span>
                    </div>
                    <div class="stat-body">
                        <div class="stat-row">
                            <span class="stat-label">Male</span>
                            <span class="stat-value"><?= $stats['male_students'] ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Female</span>
                            <span class="stat-value"><?= $stats['female_students'] ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Other</span>
                            <span class="stat-value"><?= $stats['total_students'] - $stats['male_students'] - $stats['female_students'] ?></span>
                        </div>
                    </div>
                </div>

                <!-- Payment Status -->
                <div class="analytics-stat-card">
                    <div class="stat-header">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                        <span>Payment Status</span>
                    </div>
                    <div class="stat-body">
                        <div class="stat-row">
                            <span class="stat-label">Fully Paid</span>
                            <span class="stat-value stat-success"><?= $feesStats['fully_paid_count'] ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Partial</span>
                            <span class="stat-value stat-warning"><?= $feesStats['partial_paid_count'] ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Not Paid</span>
                            <span class="stat-value stat-danger"><?= $feesStats['not_paid_count'] ?></span>
                        </div>
                    </div>
                </div>

                <!-- Photo Status -->
                <div class="analytics-stat-card">
                    <div class="stat-header">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                        <span>Photo Status</span>
                    </div>
                    <div class="stat-body">
                        <div class="stat-row">
                            <span class="stat-label">With Photo</span>
                            <span class="stat-value stat-success"><?= $stats['students_with_photo'] ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Without Photo</span>
                            <span class="stat-value stat-warning"><?= $stats['total_students'] - $stats['students_with_photo'] ?></span>
                        </div>
                        <div class="stat-row">
                            <span class="stat-label">Completion</span>
                            <span class="stat-value"><?= $stats['total_students'] > 0 ? number_format(($stats['students_with_photo'] / $stats['total_students']) * 100, 0) : 0 ?>%</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Admissions -->
            <div class="analytics-card">
                <div class="card-header">
                    <div class="card-title">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span>Recent Admissions</span>
                    </div>
                    <a href="students.php" class="card-link">View All →</a>
                </div>
                <div class="card-body">
                    <?php if (empty($recentAdmissions)): ?>
                        <div class="empty-state">
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            <p>No recent admissions</p>
                        </div>
                    <?php else: ?>
                        <div class="recent-list">
                            <?php foreach ($recentAdmissions as $admission): 
                                $fullName = trim($admission['first_name'] . ' ' . ($admission['middle_name'] ?? '') . ' ' . $admission['last_name']);
                                $firstInitial = strtoupper(substr($admission['first_name'], 0, 1));
                            ?>
                                <div class="recent-item">
                                    <div class="recent-avatar">
                                        <?php if (!empty($admission['photo'])): ?>
                                            <img src="../<?= htmlspecialchars($admission['photo']) ?>" alt="<?= htmlspecialchars($fullName) ?>">
                                        <?php else: ?>
                                            <div class="avatar-placeholder"><?= $firstInitial ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="recent-info">
                                        <div class="recent-name"><?= htmlspecialchars($fullName) ?></div>
                                        <div class="recent-meta">
                                            <span><?= htmlspecialchars($admission['course']) ?></span>
                                            <span>•</span>
                                            <span><?= date('d M Y', strtotime($admission['admission_date'])) ?></span>
                                        </div>
                                    </div>
                                    <div class="recent-badge">
                                        <span class="badge-<?= strtolower($admission['status']) ?>"><?= $admission['status'] ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
// Add hover effect to show values on bar charts
document.querySelectorAll('.bar-fill').forEach(bar => {
    bar.addEventListener('mouseenter', function() {
        const value = this.dataset.value;
        const tooltip = document.createElement('div');
        tooltip.className = 'bar-tooltip';
        tooltip.textContent = value;
        this.appendChild(tooltip);
    });
    
    bar.addEventListener('mouseleave', function() {
        const tooltip = this.querySelector('.bar-tooltip');
        if (tooltip) tooltip.remove();
    });
});
</script>
