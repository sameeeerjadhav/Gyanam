<?php
/**
 * Notification Helper Functions — Gyanam Portal
 */

/**
 * Build SQL visibility clause for notifications.
 * Role matching is done in PHP to avoid MySQL collation mismatches on bound params.
 *
 * @return string WHERE fragment (includes one ? for Specific target_id)
 */
function buildNotificationVisibilityWhere(string $role): string {
    $parts = ["n.target_type = 'All'"];
    if ($role === 'DLC Office') {
        $parts[] = "n.target_type = 'DLC'";
    } elseif ($role === 'ATC CENTER') {
        $parts[] = "n.target_type = 'ATC'";
    }
    $parts[] = "(n.target_type = 'Specific' AND n.target_id = ?)";
    return '(' . implode(' OR ', $parts) . ')';
}

/**
 * Get unread notification count for the current user.
 * Cached in session for 90s to avoid hitting DB on every page.
 */
function getUnreadNotificationCount(PDO $pdo, int $userId, string $role, ?int $dlcId = null, ?int $atcId = null): int {
    $cacheKey = 'notif_unread_' . $userId;
    $cacheAt  = 'notif_unread_at_' . $userId;
    if (isset($_SESSION[$cacheKey], $_SESSION[$cacheAt]) && (time() - (int)$_SESSION[$cacheAt]) < 90) {
        return (int)$_SESSION[$cacheKey];
    }

    // LEFT JOIN is cheaper than NOT IN (subquery)
    $visibility = buildNotificationVisibilityWhere($role);
    $sql = "SELECT COUNT(*) FROM notifications n
            LEFT JOIN notification_reads nr ON nr.notification_id = n.id AND nr.user_id = ?
            WHERE nr.id IS NULL
            AND {$visibility}
            AND n.sender_id != ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $userId, $userId]);
    $count = (int)$stmt->fetchColumn();
    $_SESSION[$cacheKey] = $count;
    $_SESSION[$cacheAt]  = time();
    return $count;
}

/**
 * Get notifications for the current user.
 */
function getNotificationsForUser(PDO $pdo, int $userId, string $role, ?int $dlcId = null, ?int $atcId = null, int $limit = 50): array {
    $visibility = buildNotificationVisibilityWhere($role);
    $sql = "SELECT n.*, u.name AS sender_name, u.role AS sender_role,
                   (CASE WHEN nr.id IS NOT NULL THEN 1 ELSE 0 END) AS is_read
            FROM notifications n
            JOIN users u ON n.sender_id = u.id
            LEFT JOIN notification_reads nr ON nr.notification_id = n.id AND nr.user_id = ?
            WHERE {$visibility}
            AND n.sender_id != ?
            ORDER BY n.created_at DESC
            LIMIT ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $userId, $userId, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Mark a notification as read for a user.
 */
function markNotificationRead(PDO $pdo, int $notificationId, int $userId): void {
    $stmt = $pdo->prepare("INSERT IGNORE INTO notification_reads (notification_id, user_id) VALUES (?, ?)");
    $stmt->execute([$notificationId, $userId]);
    unset($_SESSION['notif_unread_' . $userId], $_SESSION['notif_unread_at_' . $userId]);
}

/**
 * Mark all notifications as read for a user.
 */
function markAllNotificationsRead(PDO $pdo, int $userId, string $role): void {
    $visibility = buildNotificationVisibilityWhere($role);
    $sql = "INSERT IGNORE INTO notification_reads (notification_id, user_id)
            SELECT n.id, ? FROM notifications n
            LEFT JOIN notification_reads nr ON nr.notification_id = n.id AND nr.user_id = ?
            WHERE nr.id IS NULL
            AND {$visibility}
            AND n.sender_id != ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $userId, $userId, $userId]);
    unset($_SESSION['notif_unread_' . $userId], $_SESSION['notif_unread_at_' . $userId]);
}

/**
 * Get sent notifications for admin.
 */
function getSentNotifications(PDO $pdo, int $senderId, int $limit = 50): array {
    $sql = "SELECT n.*, 
                   CASE n.target_type
                       WHEN 'Specific' THEN (SELECT name FROM users WHERE id = n.target_id)
                       ELSE NULL
                   END AS target_name
            FROM notifications n
            WHERE n.sender_id = ?
            ORDER BY n.created_at DESC
            LIMIT ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$senderId, $limit]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Format time ago string.
 */
function timeAgo(string $datetime): string {
    $now = time();
    $diff = $now - strtotime($datetime);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . 'm ago';
    if ($diff < 86400) return floor($diff / 3600) . 'h ago';
    if ($diff < 604800) return floor($diff / 86400) . 'd ago';
    return date('d M Y', strtotime($datetime));
}

/**
 * Check and create birthday notifications for today
 */
function checkBirthdayNotifications(PDO $pdo): void {
    try {
        // Check if birthday notifications already created today
        $stmt = $pdo->query("
            SELECT COUNT(*) FROM notifications 
            WHERE title LIKE '%Birthday Today%' 
            AND DATE(created_at) = CURDATE()
        ");
        $alreadyCreated = $stmt->fetchColumn();
        
        if ($alreadyCreated > 0) {
            return; // Already created today
        }
        
        // Get today's birthdays
        $stmt = $pdo->query("
            SELECT * FROM birthdays 
            WHERE MONTH(birth_date) = MONTH(CURDATE()) 
            AND DAY(birth_date) = DAY(CURDATE())
            AND status = 'Active'
        ");
        $birthdays = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($birthdays)) {
            return; // No birthdays today
        }
        
        // Get all active users
        $stmt = $pdo->query("SELECT id FROM users WHERE status = 'Active'");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($birthdays as $birthday) {
            $birthDate = new DateTime($birthday['birth_date']);
            $today = new DateTime();
            $age = $today->diff($birthDate)->y;
            
            $message = "Birthday Greetings: {$birthday['person_name']}";
            if ($age > 0) {
                $message .= " is turning {$age} today.";
            }
            $message .= " Wishing them great health and happiness from Team Gyanam India.";
            if ($birthday['mobile']) {
                $message .= " Contact: {$birthday['mobile']}.";
            }
            if ($birthday['description']) {
                $message .= " " . $birthday['description'];
            }
            
            // Create notification for all users
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, created_at)
                VALUES (?, ?, ?, 'info', NOW())
            ");
            
            foreach ($users as $user) {
                $stmt->execute([
                    $user['id'],
                    "Birthday Today — " . $birthday['person_name'],
                    $message
                ]);
            }
        }
    } catch (Exception $e) {
        // Silently fail - don't break the application
        error_log("Birthday notification error: " . $e->getMessage());
    }
}
