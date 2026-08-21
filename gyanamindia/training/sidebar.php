<?php
$currentPage = basename($_SERVER['PHP_SELF']);
$userName = sanitize(getUserName() ?? 'Training');
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">
            <img src="../assets/logo.png" alt="Gyanam India">
        </div>
        <div class="brand-info">
            <span class="brand-name">Gyanam</span>
            <span class="brand-sub">Training Portal</span>
        </div>
    </div>
    <nav class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-section-title">Navigation</div>
            <a href="index.php" class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>" data-tooltip="My Videos">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                <span>My Videos</span>
            </a>
        </div>
        <div class="nav-section">
            <a href="../logout.php" class="nav-link" data-tooltip="Logout">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                <span>Logout</span>
            </a>
        </div>
    </nav>
</aside>
