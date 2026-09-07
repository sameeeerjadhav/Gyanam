<?php
/**
 * ATC Center Sidebar Partial
 * Brand = this ATC's logo + center name (not Gyanam HO branding)
 */
$currentPage = basename($_SERVER['PHP_SELF']);

$_sidebarAtcId   = $_SESSION['atc_id'] ?? null;
$_sidebarAtcCode = (string)($_SESSION['atc_code'] ?? '');
$_sidebarAtcName = (string)($_SESSION['atc_sidebar_name'] ?? '');
$_sidebarAtcLogo = (string)($_SESSION['atc_sidebar_logo'] ?? '');
$_sidebarBrandAt = (int)($_SESSION['atc_sidebar_brand_at'] ?? 0);
$_needBrandRefresh = ($_sidebarAtcName === '' || $_sidebarBrandAt < (time() - 300));

try {
    if ($_sidebarAtcId && ($_needBrandRefresh || $_sidebarAtcCode === '')) {
        $_pdo = isset($pdo) ? $pdo : getDBConnection();
        $_st = $_pdo->prepare("SELECT name, atc_code, logo FROM atc_centers WHERE id = ? LIMIT 1");
        $_st->execute([$_sidebarAtcId]);
        $_row = $_st->fetch(PDO::FETCH_ASSOC) ?: [];

        $_sidebarAtcName = trim((string)($_row['name'] ?? ''));
        if ($_sidebarAtcName === '') {
            $_sidebarAtcName = (string)($_SESSION['user_name'] ?? 'ATC Center');
        }

        $_sidebarAtcCode = trim((string)($_row['atc_code'] ?? ''));
        if ($_sidebarAtcCode === '') {
            $_sidebarAtcCode = date('Y') . str_pad((string)$_sidebarAtcId, 5, '0', STR_PAD_LEFT);
        }

        $_sidebarAtcLogo = trim((string)($_row['logo'] ?? ''));

        $_SESSION['atc_sidebar_name'] = $_sidebarAtcName;
        $_SESSION['atc_sidebar_logo'] = $_sidebarAtcLogo;
        $_SESSION['atc_code'] = $_sidebarAtcCode;
        $_SESSION['atc_sidebar_brand_at'] = time();
    }
} catch (Exception $_e) {
    if ($_sidebarAtcName === '') {
        $_sidebarAtcName = (string)($_SESSION['user_name'] ?? 'ATC Center');
    }
    if ($_sidebarAtcId && $_sidebarAtcCode === '') {
        $_sidebarAtcCode = date('Y') . str_pad((string)$_sidebarAtcId, 5, '0', STR_PAD_LEFT);
    }
}

// Resolve logo web path (relative to /atc/*.php)
$_sidebarLogoUrl = '';
if ($_sidebarAtcLogo !== '') {
    if (preg_match('#^https?://#i', $_sidebarAtcLogo) || str_starts_with($_sidebarAtcLogo, '/')) {
        $_sidebarLogoUrl = $_sidebarAtcLogo;
    } else {
        $_sidebarLogoUrl = '../' . ltrim(str_replace('\\', '/', $_sidebarAtcLogo), '/');
    }
    // Cache-bust when session brand was refreshed
    $_sidebarLogoUrl .= (str_contains($_sidebarLogoUrl, '?') ? '&' : '?') . 'v=' . $_sidebarBrandAt;
}

$_sidebarInitials = '';
foreach (preg_split('/\s+/', $_sidebarAtcName) ?: [] as $_w) {
    if ($_w === '') {
        continue;
    }
    $_sidebarInitials .= strtoupper(substr($_w, 0, 1));
    if (strlen($_sidebarInitials) >= 2) {
        break;
    }
}
if ($_sidebarInitials === '') {
    $_sidebarInitials = 'ATC';
}
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand atc-sidebar-brand">
        <div class="brand-logo atc-brand-logo<?= $_sidebarLogoUrl === '' ? ' is-fallback' : '' ?>">
            <?php if ($_sidebarLogoUrl !== ''): ?>
                <img src="<?= htmlspecialchars($_sidebarLogoUrl) ?>"
                     alt="<?= htmlspecialchars($_sidebarAtcName) ?>"
                     onerror="this.style.display='none';this.parentElement.classList.add('is-fallback');this.parentElement.querySelector('.atc-logo-fallback').hidden=false;">
                <span class="atc-logo-fallback" hidden><?= htmlspecialchars($_sidebarInitials) ?></span>
            <?php else: ?>
                <span class="atc-logo-fallback"><?= htmlspecialchars($_sidebarInitials) ?></span>
            <?php endif; ?>
        </div>
        <div class="brand-info">
            <h2 title="<?= htmlspecialchars($_sidebarAtcName) ?>"><?= htmlspecialchars($_sidebarAtcName) ?></h2>
            <div class="atc-brand-meta">
                <span class="atc-brand-role">ATC</span>
                <?php if ($_sidebarAtcCode): ?>
                <span class="atc-code-pill"><?= htmlspecialchars($_sidebarAtcCode) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-section-title">Main</div>
            <a href="index.php" class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>" data-tooltip="Dashboard">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                <span>Dashboard</span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-title">Operations</div>
            <a href="inquiries.php" class="nav-link <?= $currentPage === 'inquiries.php' ? 'active' : '' ?>" data-tooltip="Inquiries">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                <span>Inquiries</span>
            </a>
            <a href="telephonic_inquiry.php" class="nav-link <?= $currentPage === 'telephonic_inquiry.php' ? 'active' : '' ?>" data-tooltip="Telephonic Inquiry">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                <span>Telephonic Inquiry</span>
            </a>
            <a href="new_admission.php" class="nav-link <?= $currentPage === 'new_admission.php' ? 'active' : '' ?>" data-tooltip="New Admission">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" y1="8" x2="19" y2="14"/><line x1="22" y1="11" x2="16" y2="11"/></svg>
                <span>New Admission</span>
            </a>
            <a href="re_admission.php" class="nav-link <?= $currentPage === 're_admission.php' ? 'active' : '' ?>" data-tooltip="Re-Admission">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><path d="M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                <span>Re-Admission</span>
            </a>
            <a href="students.php" class="nav-link <?= $currentPage === 'students.php' ? 'active' : '' ?>" data-tooltip="Students">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                <span>Students</span>
            </a>
            <a href="courses.php" class="nav-link <?= $currentPage === 'courses.php' ? 'active' : '' ?>" data-tooltip="Courses">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
                <span>Courses</span>
            </a>
            <a href="fees.php" class="nav-link <?= $currentPage === 'fees.php' ? 'active' : '' ?>" data-tooltip="Fees Management">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <span>Fees Management</span>
            </a>
            <a href="hall_tickets.php" class="nav-link <?= $currentPage === 'hall_tickets.php' ? 'active' : '' ?>" data-tooltip="Hall Tickets">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="15" rx="2" ry="2"/><polyline points="17 2 12 7 7 2"/></svg>
                <span>Hall Tickets</span>
            </a>
            <a href="exam_schedules.php" class="nav-link <?= $currentPage === 'exam_schedules.php' ? 'active' : '' ?>" data-tooltip="Exam Schedules">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                <span>Exam Schedules</span>
            </a>
            <a href="exam_results.php" class="nav-link <?= $currentPage === 'exam_results.php' ? 'active' : '' ?>" data-tooltip="Exam Results">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                <span>Exam Results</span>
            </a>
            <a href="student_marks.php" class="nav-link <?= $currentPage === 'student_marks.php' ? 'active' : '' ?>" data-tooltip="Student Marks">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <span>Student Marks</span>
            </a>

            <a href="completion_certificate.php" class="nav-link <?= $currentPage === 'completion_certificate.php' ? 'active' : '' ?>" data-tooltip="Completion Certificate">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                <span>Completion Certificate</span>
            </a>
            <a href="my_auth_certificate.php" class="nav-link <?= $currentPage === 'my_auth_certificate.php' ? 'active' : '' ?>" data-tooltip="Auth Certificate">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89L17 22l-5-3-5 3 1.523-9.11"/></svg>
                <span>Auth Certificate</span>
            </a>

            <a href="duplicate_certificates.php" class="nav-link <?= $currentPage === 'duplicate_certificates.php' ? 'active' : '' ?>" data-tooltip="Duplicate Certificate">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                <span>Duplicate Certificate</span>
            </a>

            <a href="my_schemes.php" class="nav-link <?= $currentPage === 'my_schemes.php' ? 'active' : '' ?>" data-tooltip="My Schemes">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12V22H4V12"/><path d="M22 7H2v5h20V7z"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg>
                <span>My Schemes</span>
            </a>
            <a href="documents.php" class="nav-link <?= $currentPage === 'documents.php' ? 'active' : '' ?>" data-tooltip="Downloads">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span>Downloads</span>
            </a>
            <a href="dispatches.php" class="nav-link <?= $currentPage === 'dispatches.php' ? 'active' : '' ?>" data-tooltip="Dispatches">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3"/><path d="m15 9 6-6"/><path d="M3 21h18"/></svg>
                <span>Dispatches</span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-title">Payments</div>
            <a href="pay_share.php" class="nav-link <?= $currentPage === 'pay_share.php' ? 'active' : '' ?>" data-tooltip="Report to GYANAM">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>
                <span>Report to GYANAM</span>
            </a>
            <a href="notifications.php" class="nav-link <?= $currentPage === 'notifications.php' ? 'active' : '' ?>" data-tooltip="Notifications">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                <span>Notifications</span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-title">Reports</div>
            <a href="analytics.php" class="nav-link <?= $currentPage === 'analytics.php' ? 'active' : '' ?>" data-tooltip="Analytics">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                <span>Analytics</span>
            </a>
            <a href="report_receipt.php" class="nav-link <?= $currentPage === 'report_receipt.php' ? 'active' : '' ?>" data-tooltip="Report Receipt">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                <span>Report Receipt</span>
            </a>
        </div>
    </nav>

    <div class="sidebar-footer">
        <button class="sidebar-collapse-btn" id="sidebarCollapseBtn" title="Collapse sidebar">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="11 17 6 12 11 7"/><polyline points="18 17 13 12 18 7"/></svg>
            <span class="collapse-text">Collapse</span>
        </button>
        <span class="sidebar-version">Developed By Sameer & Yuvraj</span>
    </div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<style>
/* ATC brand — zoom-safe, no card chrome around logo */
.sidebar-brand.atc-sidebar-brand {
    padding: 1.15rem 1rem 1.1rem;
    gap: 0.75rem;
    align-items: center;
    flex-wrap: nowrap;
    background: transparent;
    min-height: 4.25rem;
    box-sizing: border-box;
    overflow: visible;
}
.atc-sidebar-brand .brand-info {
    flex: 1 1 auto;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 0.28rem;
}
.atc-sidebar-brand .atc-brand-logo {
    width: auto !important;
    height: auto !important;
    max-width: 88px;
    max-height: 52px;
    border: 0 !important;
    border-radius: 0 !important;
    background: transparent !important;
    box-shadow: none !important;
    outline: none;
    display: flex;
    align-items: center;
    justify-content: flex-start;
    overflow: visible !important;
    flex-shrink: 0;
    transform: none !important;
}
.atc-sidebar-brand .atc-brand-logo img {
    width: auto !important;
    height: auto !important;
    max-width: 88px;
    max-height: 52px;
    object-fit: contain;
    padding: 0 !important;
    margin: 0;
    display: block;
    background: transparent !important;
    border: 0 !important;
    border-radius: 0 !important;
    box-shadow: none !important;
}
.atc-sidebar-brand:hover .atc-brand-logo {
    transform: none !important;
}
.atc-sidebar-brand .atc-brand-logo.is-fallback {
    width: 42px !important;
    height: 42px !important;
    max-width: 42px;
    border-radius: 10px !important;
    overflow: hidden !important;
}
.atc-sidebar-brand .atc-logo-fallback {
    display: none;
    width: 42px;
    height: 42px;
    align-items: center;
    justify-content: center;
    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .02em;
    color: #fff;
    -webkit-text-fill-color: #fff;
    background: linear-gradient(135deg, #4361ee, #7c3aed);
    border-radius: 10px;
}
.atc-sidebar-brand .atc-brand-logo.is-fallback .atc-logo-fallback {
    display: flex;
}
.atc-sidebar-brand .brand-info h2 {
    font-size: clamp(0.82rem, 1.6vw, 0.98rem);
    max-width: none;
    width: 100%;
    white-space: normal;
    overflow: visible;
    text-overflow: unset;
    line-height: 1.25;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.atc-brand-meta {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem 0.4rem;
}
.sidebar-brand.atc-sidebar-brand .brand-info .atc-brand-role {
    font-size: 0.65rem;
    color: var(--text-muted);
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    line-height: 1.2;
}
.sidebar-brand.atc-sidebar-brand .brand-info .atc-code-pill {
    display: inline-flex;
    align-items: center;
    margin-left: 0;
    background: linear-gradient(135deg, #4361ee, #7c3aed);
    color: #ffffff !important;
    -webkit-text-fill-color: #ffffff !important;
    font-size: 0.65rem;
    font-weight: 800;
    padding: 0.18rem 0.5rem;
    border-radius: 99px;
    letter-spacing: 0.02em;
    text-transform: none;
    line-height: 1.15;
    white-space: nowrap;
    flex-shrink: 0;
}
.sidebar.collapsed .atc-sidebar-brand {
    justify-content: center;
    padding: 1rem 0.5rem;
}
.sidebar.collapsed .atc-sidebar-brand .brand-info {
    display: none;
}
.sidebar.collapsed .atc-sidebar-brand .atc-brand-logo img {
    max-width: 40px;
    max-height: 40px;
}

/* Zoom / narrow sidebar: keep brand readable */
@media (max-width: 1100px) {
    .sidebar-brand.atc-sidebar-brand {
        padding: 1rem 0.85rem;
    }
    .atc-sidebar-brand .atc-brand-logo img {
        max-width: 72px;
        max-height: 44px;
    }
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var activeLink = document.querySelector('.sidebar-nav .nav-link.active');
    if (activeLink) activeLink.scrollIntoView({ block: 'center', behavior: 'instant' });
    var collapseBtn = document.getElementById('sidebarCollapseBtn');
    var sidebar = document.getElementById('sidebar');
    if (collapseBtn && sidebar) {
        if (localStorage.getItem('sidebar_collapsed') === '1') sidebar.classList.add('collapsed');
        collapseBtn.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed') ? '1' : '0');
        });
    }
    var hamburger = document.getElementById('hamburgerBtn');
    var overlay = document.getElementById('sidebarOverlay');
    if (hamburger && sidebar) {
        hamburger.addEventListener('click', function() { sidebar.classList.toggle('open'); if (overlay) overlay.classList.toggle('active'); });
    }
    if (overlay && sidebar) {
        overlay.addEventListener('click', function() { sidebar.classList.remove('open'); overlay.classList.remove('active'); });
    }
});
</script>
