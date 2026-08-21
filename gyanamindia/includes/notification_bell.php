<?php
/**
 * Notification Bell Icon — Header Partial
 * Include after auth.php, functions.php, and notifications.php
 */
$_notifPdo = getDBConnection();
$_notifUserId = getUserId();
$_notifRole = getUserRole();
$_notifDlcId = $_SESSION['dlc_id'] ?? null;
$_notifAtcId = $_SESSION['atc_id'] ?? null;
try {
    $_unreadCount = getUnreadNotificationCount($_notifPdo, $_notifUserId, $_notifRole, $_notifDlcId, $_notifAtcId);
} catch (Exception $e) {
    $_unreadCount = 0;
}

$_notifPageMap = [
    'Admin'      => '/admin/notifications.php',
    'DLC Office' => '/dlc/notifications.php',
    'ATC CENTER' => '/atc/notifications.php',
];
$_notifPageURL = $_notifPageMap[$_notifRole] ?? '#';
?>
<a href="<?= $_notifPageURL ?>" class="notif-bell" title="Notifications">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    <?php if ($_unreadCount > 0): ?>
        <span class="notif-badge"><?= $_unreadCount > 99 ? '99+' : $_unreadCount ?></span>
    <?php endif; ?>
</a>
