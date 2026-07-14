<?php
/**
 * Gyanam Portal — ATC Notifications (View Received)
 */
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['ATC CENTER']);

$pdo = getDBConnection();
$userId = getUserId();
$role = getUserRole();

if (isset($_GET['mark_all'])) {
    markAllNotificationsRead($pdo, $userId, $role);
    redirect('/atc/notifications.php');
}

if (isset($_GET['read'])) {
    markNotificationRead($pdo, (int)$_GET['read'], $userId);
    redirect('/atc/notifications.php');
}

$notifications = getNotificationsForUser($pdo, $userId, $role);
$unreadCount = getUnreadNotificationCount($pdo, $userId, $role);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications — ATC Center | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/profile.css">
    <link rel="stylesheet" href="../assets/css/management.css">
    <link rel="stylesheet" href="../assets/css/notifications.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>📚</text></svg>">
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
                    <h2>Notifications</h2>
                    <p>Messages from Super Admin</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content notif-page">
            <div class="notif-list-header">
                <h3>
                    All Notifications
                    <?php if ($unreadCount > 0): ?>
                        <span class="badge-count"><?= $unreadCount ?> unread</span>
                    <?php endif; ?>
                </h3>
                <?php if ($unreadCount > 0): ?>
                    <a href="?mark_all=1" class="btn-mark-all">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        Mark all read
                    </a>
                <?php endif; ?>
            </div>

            <?php if (empty($notifications)): ?>
                <div class="notif-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <p>No notifications yet.</p>
                </div>
            <?php else: ?>
                <div class="notif-list">
                    <?php foreach ($notifications as $n): ?>
                        <a href="?read=<?= $n['id'] ?>" class="notif-item <?= !$n['is_read'] ? 'unread' : '' ?>" style="text-decoration:none;color:inherit;">
                            <div class="notif-icon <?= $n['target_type'] === 'All' ? 'broadcast' : '' ?>">
                                <?php if ($n['target_type'] === 'All'): ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                                <?php else: ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                                <?php endif; ?>
                            </div>
                            <div class="notif-body">
                                <div class="notif-title"><?= sanitize($n['title']) ?></div>
                                <div class="notif-text"><?= nl2br(sanitize($n['message'])) ?></div>
                                <div class="notif-meta">
                                    <span>
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                        <?= timeAgo($n['created_at']) ?>
                                    </span>
                                    <span>From: <?= sanitize($n['sender_name']) ?></span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<script src="../assets/js/dashboard.js"></script>
</body>
</html>
