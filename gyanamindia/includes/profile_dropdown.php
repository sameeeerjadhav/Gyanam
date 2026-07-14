<?php
/**
 * Profile Dropdown Partial — Header Right Section
 */
$_userName = sanitize(getUserName());
$_userRole = sanitize(getRoleDisplayName(getUserRole()));
$_userInitial = strtoupper(substr(getUserName(), 0, 1));
$_profileURLMap = [
    'Admin'      => '/admin/profile.php',
    'DLC Office' => '/dlc/profile.php',
    'ATC CENTER' => '/atc/profile.php',
];
$_profileURL = $_profileURLMap[getUserRole()] ?? '#';
?>
<div class="profile-dropdown" id="profileDropdown">
    <button class="profile-trigger" id="profileTrigger" type="button" aria-label="User menu">
        <span class="trigger-avatar"><?= $_userInitial ?></span>
        <span class="trigger-info">
            <span class="trigger-name"><?= $_userName ?></span>
            <span class="trigger-role"><?= $_userRole ?></span>
        </span>
        <span class="trigger-chevron">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
        </span>
    </button>

    <div class="profile-menu">
        <div class="profile-menu-header">
            <span class="menu-avatar"><?= $_userInitial ?></span>
            <div class="menu-user-info">
                <div class="menu-name"><?= $_userName ?></div>
                <div class="menu-role"><?= $_userRole ?></div>
            </div>
        </div>
        <div class="profile-menu-body">
            <a href="<?= $_profileURL ?>" class="profile-menu-item">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                My Profile
            </a>
            <div class="profile-menu-divider"></div>
            <a href="/logout.php" class="profile-menu-item danger" id="logoutBtn">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Log Out
            </a>
        </div>
    </div>
</div>
