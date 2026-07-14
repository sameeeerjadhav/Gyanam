<?php
/**
 * Admin Sidebar Partial
 */
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">
            <img src="../assets/logo.png" alt="Gyanam India">
        </div>
        <div class="brand-info">
            <h2>Gyanam India</h2>
            <span>Head Office Panel</span>
        </div>
    </div>

    <nav class="sidebar-nav">

        <!-- ═══════════════ MAIN ═══════════════ -->
        <div class="nav-section">
            <div class="nav-section-title">Main</div>
            <a href="index.php" class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>" data-tooltip="Dashboard">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                <span>Dashboard</span>
            </a>
        </div>

        <!-- ═══════════════ ADMINISTRATION ═══════════════ -->
        <div class="nav-section">
            <div class="nav-section-title">Administration</div>
            <a href="users.php" class="nav-link <?= $currentPage === 'users.php' ? 'active' : '' ?>" data-tooltip="Users">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <span>Users</span>
            </a>
            <a href="dlc_offices.php" class="nav-link <?= $currentPage === 'dlc_offices.php' ? 'active' : '' ?>" data-tooltip="DLC Logins">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"/><path d="M5 21V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v16"/><path d="M9 8h1"/><path d="M9 12h1"/><path d="M14 8h1"/><path d="M14 12h1"/></svg>
                <span>DLC Logins</span>
            </a>
            <a href="atc_centers.php" class="nav-link <?= $currentPage === 'atc_centers.php' ? 'active' : '' ?>" data-tooltip="ATC Logins">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                <span>ATC Logins</span>
            </a>
            <a href="courses.php" class="nav-link <?= $currentPage === 'courses.php' ? 'active' : '' ?>" data-tooltip="Master Courses">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                <span>Courses</span>
            </a>
            <a href="exam_credentials.php" class="nav-link <?= $currentPage === 'exam_credentials.php' ? 'active' : '' ?>" data-tooltip="Exam Portal Credentials">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"/></svg>
                <span>Exam Credentials</span>
            </a>
            <a href="schemes.php" class="nav-link <?= in_array($currentPage, ['schemes.php','scheme_report.php']) ? 'active' : '' ?>" data-tooltip="Schemes">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12V22H4V12"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
                <span>Schemes</span>
            </a>
        </div>

        <!-- ═══════════════ STUDENTS ═══════════════ -->
        <div class="nav-section">
            <div class="nav-section-title">Students</div>
            <a href="students.php" class="nav-link <?= $currentPage === 'students.php' ? 'active' : '' ?>" data-tooltip="Students">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                <span>Students</span>
            </a>
            <a href="exam_results.php" class="nav-link <?= $currentPage === 'exam_results.php' ? 'active' : '' ?>" data-tooltip="Exam Results">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                <span>Exam Results</span>
            </a>
        </div>

        <!-- ═══════════════ CERTIFICATES ═══════════════ -->
        <div class="nav-section">
            <div class="nav-section-title">Certificates</div>
            <a href="authorization_certificates.php" class="nav-link <?= $currentPage === 'authorization_certificates.php' ? 'active' : '' ?>" data-tooltip="Auth Certificates">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
                <span>Auth Certificates</span>
            </a>
            <a href="course_certificates.php" class="nav-link <?= $currentPage === 'course_certificates.php' ? 'active' : '' ?>" data-tooltip="Course Certificates">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/><circle cx="12" cy="5" r="1.5"/></svg>
                <span>Course Certificates</span>
            </a>
            <?php
            // Pending duplicate cert requests badge
            try {
                $pdo = isset($pdo) ? $pdo : getDBConnection();
                $dcPending = $pdo->query("SELECT COUNT(*) FROM duplicate_cert_requests WHERE status = 'Pending'")->fetchColumn();
            } catch(Exception $e) { $dcPending = 0; }
            ?>
            <a href="duplicate_certificates.php" class="nav-link <?= $currentPage === 'duplicate_certificates.php' ? 'active' : '' ?>" data-tooltip="Duplicate Certs">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                <span>Duplicate Certs</span>
                <?php if ($dcPending > 0): ?>
                <span style="margin-left:auto;background:#f59e0b;color:#fff;border-radius:99px;font-size:.68rem;font-weight:800;padding:.1rem .45rem;min-width:18px;text-align:center"><?= $dcPending ?></span>
                <?php endif; ?>
            </a>
            <a href="print_certificates.php" class="nav-link <?= $currentPage === 'print_certificates.php' ? 'active' : '' ?>" data-tooltip="Print Certificates">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/><circle cx="12" cy="5" r="1.5"/></svg>
                <span>Print Certificates</span>
            </a>
        </div>

        <!-- ═══════════════ OPERATIONS ═══════════════ -->
        <div class="nav-section">
            <div class="nav-section-title">Operations</div>
            <a href="documents.php" class="nav-link <?= $currentPage === 'documents.php' ? 'active' : '' ?>" data-tooltip="Documents">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span>Documents</span>
            </a>
            <a href="dispatches.php" class="nav-link <?= $currentPage === 'dispatches.php' ? 'active' : '' ?>" data-tooltip="Dispatches">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3"/><path d="m15 9 6-6"/><path d="M3 21h18"/></svg>
                <span>Dispatches</span>
            </a>
            <a href="material_requirements.php" class="nav-link <?= $currentPage === 'material_requirements.php' ? 'active' : '' ?>" data-tooltip="Material Requirements">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                <span>Material Needs</span>
            </a>
            <a href="inventory.php" class="nav-link <?= $currentPage === 'inventory.php' ? 'active' : '' ?>" data-tooltip="Inventory">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                <span>Inventory</span>
            </a>
            <?php
            // Pending change requests badge
            try {
                $pdo = isset($pdo) ? $pdo : getDBConnection();
                $crStmt = $pdo->query("SELECT COUNT(*) FROM change_requests WHERE status = 'Pending'");
                $crPending = $crStmt->fetchColumn();
            } catch(Exception $e) { $crPending = 0; }
            ?>
            <a href="change_requests.php" class="nav-link <?= $currentPage === 'change_requests.php' ? 'active' : '' ?>" data-tooltip="Approve Edits">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                <span>Approve Edits</span>
                <?php if ($crPending > 0): ?>
                <span style="margin-left:auto;background:#ef4444;color:#fff;border-radius:99px;font-size:.68rem;font-weight:800;padding:.1rem .45rem;min-width:18px;text-align:center"><?= $crPending ?></span>
                <?php endif; ?>
            </a>
        </div>

        <!-- ═══════════════ FINANCIAL ═══════════════ -->
        <div class="nav-section">
            <div class="nav-section-title">Financial</div>
            <a href="share_payments.php" class="nav-link <?= $currentPage === 'share_payments.php' ? 'active' : '' ?>" data-tooltip="Share Payments">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                <span>Share Payments</span>
            </a>
            <a href="share_receipts.php" class="nav-link <?= $currentPage === 'share_receipts.php' ? 'active' : '' ?>" data-tooltip="Share Receipts">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <span>Share Receipts</span>
            </a>
            <a href="dlc_share_payments.php" class="nav-link <?= $currentPage === 'dlc_share_payments.php' ? 'active' : '' ?>" data-tooltip="DLC Shares">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <span>DLC Shares</span>
            </a>
        </div>

        <!-- ═══════════════ REPORTS ═══════════════ -->
        <div class="nav-section">
            <div class="nav-section-title">Reports</div>
            <a href="reports.php" class="nav-link <?= $currentPage === 'reports.php' ? 'active' : '' ?>" data-tooltip="Reports & Analytics">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                <span>Reports & Analytics</span>
            </a>
            <a href="scheme_report.php" class="nav-link <?= $currentPage === 'scheme_report.php' ? 'active' : '' ?>" data-tooltip="Scheme Report">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                <span>Scheme Report</span>
            </a>
        </div>

        <!-- ═══════════════ COMMUNICATION ═══════════════ -->
        <div class="nav-section">
            <div class="nav-section-title">Communication</div>
            <a href="notifications.php" class="nav-link <?= $currentPage === 'notifications.php' ? 'active' : '' ?>" data-tooltip="Notifications">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <span>Notifications</span>
            </a>
            <a href="announcements.php" class="nav-link <?= $currentPage === 'announcements.php' ? 'active' : '' ?>" data-tooltip="Dashboard Banners">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                <span>Dashboard Banners</span>
            </a>
            <a href="training_videos.php" class="nav-link <?= $currentPage === 'training_videos.php' ? 'active' : '' ?>" data-tooltip="Training Videos">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                <span>Training Videos</span>
            </a>
            <a href="birthdays.php" class="nav-link <?= $currentPage === 'birthdays.php' ? 'active' : '' ?>" data-tooltip="Birthday Management">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                <span>Birthdays</span>
            </a>
        </div>

    </nav>

    <div class="sidebar-footer">
        <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Collapse sidebar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg>
            <span class="collapse-text">Collapse</span>
        </button>
        <span class="sidebar-version">Developed by Sameer & Yuvraj</span>
    </div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<script>
// Scroll active sidebar link into view on page load (no full page scroll)
document.addEventListener('DOMContentLoaded', function() {
    var activeLink = document.querySelector('.sidebar-nav .nav-link.active');
    if (activeLink) {
        activeLink.scrollIntoView({ block: 'center', behavior: 'instant' });
    }
    // Sidebar collapse toggle
    var collapseBtn = document.getElementById('sidebarCollapseBtn');
    var sidebar = document.getElementById('sidebar');
    if (collapseBtn && sidebar) {
        var collapsed = localStorage.getItem('sidebar_collapsed') === '1';
        if (collapsed) sidebar.classList.add('collapsed');
        collapseBtn.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
        });
    }
    // Mobile hamburger
    var hamburger = document.getElementById('hamburgerBtn');
    var overlay = document.getElementById('sidebarOverlay');
    if (hamburger && sidebar) {
        hamburger.addEventListener('click', function() {
            sidebar.classList.toggle('open');
            if (overlay) overlay.classList.toggle('active');
        });
    }
    if (overlay && sidebar) {
        overlay.addEventListener('click', function() {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
        });
    }
});
</script>