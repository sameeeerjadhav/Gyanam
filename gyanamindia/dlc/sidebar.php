<?php
/**
 * DLC Office Sidebar Partial
 */
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">
            <img src="/assets/logo.png" alt="Gyanam India">
        </div>
        <div class="brand-info">
            <h2>Gyanam India</h2>
            <span>DLC Office Panel</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-section-title">Main</div>
            <a href="/dlc/" class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>" data-tooltip="Dashboard">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                <span>Dashboard</span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-title">Management</div>
            <a href="/dlc/atc_centers.php" class="nav-link <?= $currentPage === 'atc_centers.php' ? 'active' : '' ?>" data-tooltip="ATC Centers">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c0 2 2 3 6 3s6-1 6-3v-5"/></svg>
                <span>ATC Centers</span>
            </a>
            <a href="/dlc/documents.php" class="nav-link <?= $currentPage === 'documents.php' ? 'active' : '' ?>" data-tooltip="Documents">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span>Documents</span>
            </a>
            <a href="/dlc/dispatches.php" class="nav-link <?= $currentPage === 'dispatches.php' ? 'active' : '' ?>" data-tooltip="Dispatches">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5"/><path d="M8 3H3v5"/><path d="M12 22v-8.3a4 4 0 0 0-1.172-2.872L3 3"/><path d="m15 9 6-6"/><path d="M3 21h18"/></svg>
                <span>Dispatches</span>
            </a>
            <a href="/dlc/bank_details.php" class="nav-link <?= $currentPage === 'bank_details.php' ? 'active' : '' ?>" data-tooltip="Bank Details">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 14h.01M10 14h.01M14 14h.01"/></svg>
                <span>Bank Details</span>
            </a>
            <a href="/dlc/share_earnings.php" class="nav-link <?= $currentPage === 'share_earnings.php' ? 'active' : '' ?>" data-tooltip="Share Earnings">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                <span>Share Earnings</span>
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-title">Reports</div>
            <a href="/dlc/analytics.php" class="nav-link <?= $currentPage === 'analytics.php' ? 'active' : '' ?>" data-tooltip="Analytics">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                <span>Analytics</span>
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
