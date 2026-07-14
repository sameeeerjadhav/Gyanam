<?php
/**
 * Gyanam Portal — Admin Notifications Page
 * Send notifications (broadcast + targeted) & view sent history
 */
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/notifications.php';

requireLogin(['Admin']);

$pdo = getDBConnection();
$userId = getUserId();

$success = $_SESSION['page_success'] ?? '';
$error   = $_SESSION['page_error'] ?? '';
unset($_SESSION['page_success'], $_SESSION['page_error']);

// Handle send
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'send_notification') {
        $title      = trim($_POST['title'] ?? '');
        $message    = trim($_POST['message'] ?? '');
        $targetType = $_POST['target_type'] ?? 'All';
        $targetId   = !empty($_POST['target_id']) ? (int)$_POST['target_id'] : null;

        if (empty($title) || empty($message)) {
            $_SESSION['page_error'] = 'Title and message are required.';
            redirect('/admin/notifications.php');
        }

        if ($targetType === 'Specific' && !$targetId) {
            $_SESSION['page_error'] = 'Please select a recipient.';
            redirect('/admin/notifications.php');
        }

        $stmt = $pdo->prepare("INSERT INTO notifications (sender_id, title, message, target_type, target_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $title, $message, $targetType, $targetId]);

        $_SESSION['page_success'] = 'Notification sent successfully!';
        redirect('/admin/notifications.php');
    }

    if ($action === 'delete_notification') {
        $nId = (int)($_POST['notif_id'] ?? 0);
        $pdo->prepare("DELETE FROM notifications WHERE id = ? AND sender_id = ?")->execute([$nId, $userId]);
        $_SESSION['page_success'] = 'Notification deleted.';
        redirect('/admin/notifications.php');
    }
}

// Fetch sent notifications
$sentNotifs = getSentNotifications($pdo, $userId);

// Fetch DLC and ATC users for targeted sending
$dlcUsers = $pdo->query("SELECT id, name, username FROM users WHERE role = 'DLC Office' AND status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$atcUsers = $pdo->query("SELECT id, name, username FROM users WHERE role = 'ATC CENTER' AND status = 'Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications — Super Admin | Gyanam India</title>
    <link rel="stylesheet" href="../assets/css/global.css">
    <link rel="stylesheet" href="../assets/css/dashboard.css">
    <link rel="stylesheet" href="../assets/css/profile.css">
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
                    <p>Send messages to DLC offices and ATC centers</p>
                </div>
            </div>
            <div class="header-right">
                <?php include __DIR__ . '/../includes/notification_bell.php'; ?>
                <?php include __DIR__ . '/../includes/profile_dropdown.php'; ?>
            </div>
        </header>

        <div class="page-content notif-page">

            <?php if ($success): ?>
                <div class="alert-success">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                    <?= sanitize($success) ?>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert-danger">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    <?= sanitize($error) ?>
                </div>
            <?php endif; ?>

            <!-- Compose Notification -->
            <form method="POST" class="notif-compose">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    Send Notification
                </h3>
                <input type="hidden" name="action" value="send_notification">

                <div class="compose-grid">
                    <div class="form-field full-width">
                        <label>Send To</label>
                        <div class="target-select-group" id="targetGroup">
                            <label class="target-option selected" data-value="All">
                                <input type="radio" name="target_type" value="All" checked> 📢 All Users
                            </label>
                            <label class="target-option" data-value="DLC">
                                <input type="radio" name="target_type" value="DLC"> 🏢 All DLC Offices
                            </label>
                            <label class="target-option" data-value="ATC">
                                <input type="radio" name="target_type" value="ATC"> 🎓 All ATC Centers
                            </label>
                            <label class="target-option" data-value="Specific">
                                <input type="radio" name="target_type" value="Specific"> 👤 Specific User
                            </label>
                        </div>
                    </div>

                    <div class="form-field full-width" id="specificUserField" style="display:none;">
                        <label>Select Recipient</label>
                        <select name="target_id" id="targetIdSelect">
                            <option value="">— Choose a user —</option>
                            <optgroup label="DLC Offices">
                                <?php foreach ($dlcUsers as $u): ?>
                                    <option value="<?= $u['id'] ?>"><?= sanitize($u['name'] ?: $u['username']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <optgroup label="ATC Centers">
                                <?php foreach ($atcUsers as $u): ?>
                                    <option value="<?= $u['id'] ?>"><?= sanitize($u['name'] ?: $u['username']) ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                    </div>

                    <div class="form-field full-width">
                        <label>Title *</label>
                        <input type="text" name="title" required placeholder="Notification title">
                    </div>
                    <div class="form-field full-width">
                        <label>Message *</label>
                        <textarea name="message" required placeholder="Write your notification message..."></textarea>
                    </div>
                </div>

                <div class="compose-actions">
                    <button type="submit" class="btn-primary">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        Send Notification
                    </button>
                </div>
            </form>

            <!-- Sent History -->
            <div class="notif-list-header">
                <h3>
                    Sent History
                    <span class="badge-count"><?= count($sentNotifs) ?></span>
                </h3>
            </div>

            <?php if (empty($sentNotifs)): ?>
                <div class="notif-empty">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
                    <p>No notifications sent yet.</p>
                </div>
            <?php else: ?>
                <div class="notif-list">
                    <?php foreach ($sentNotifs as $n): ?>
                        <div class="notif-item">
                            <div class="notif-icon <?= $n['target_type'] === 'All' ? 'broadcast' : '' ?>">
                                <?php if ($n['target_type'] === 'All'): ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="11 5 6 9 2 9 2 15 6 15 11 19 11 5"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/></svg>
                                <?php else: ?>
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
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
                                    <span class="notif-target-badge <?= strtolower($n['target_type']) ?>">
                                        <?php
                                        if ($n['target_type'] === 'All') echo '📢 All Users';
                                        elseif ($n['target_type'] === 'DLC') echo '🏢 All DLC';
                                        elseif ($n['target_type'] === 'ATC') echo '🎓 All ATC';
                                        elseif ($n['target_type'] === 'Specific') echo '👤 ' . sanitize($n['target_name'] ?? 'User');
                                        ?>
                                    </span>
                                </div>
                            </div>
                            <form method="POST" onsubmit="return confirm('Delete this notification?')">
                                <input type="hidden" name="action" value="delete_notification">
                                <input type="hidden" name="notif_id" value="<?= $n['id'] ?>">
                                <button type="submit" class="btn-icon danger" title="Delete">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<script src="../assets/js/dashboard.js"></script>
<script>
// Target type selector
document.querySelectorAll('#targetGroup .target-option').forEach(opt => {
    opt.addEventListener('click', () => {
        document.querySelectorAll('#targetGroup .target-option').forEach(o => o.classList.remove('selected'));
        opt.classList.add('selected');
        opt.querySelector('input').checked = true;
        const val = opt.dataset.value;
        document.getElementById('specificUserField').style.display = val === 'Specific' ? '' : 'none';
    });
});
</script>
</body>
</html>
