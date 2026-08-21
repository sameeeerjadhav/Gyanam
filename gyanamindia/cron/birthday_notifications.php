<?php
/**
 * Birthday Notifications Cron Job
 * Run this daily to send birthday notifications
 * 
 * Setup: Add to crontab to run daily at 6 AM
 * 0 6 * * * php /path/to/Gyanam/cron/birthday_notifications.php
 */

require_once __DIR__ . '/../config/db.php';

try {
    $pdo = getDBConnection();
    
    // Get today's birthdays
    $stmt = $pdo->query("
        SELECT * FROM birthdays 
        WHERE MONTH(birth_date) = MONTH(CURDATE()) 
        AND DAY(birth_date) = DAY(CURDATE())
        AND status = 'Active'
    ");
    $birthdays = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($birthdays)) {
        echo "No birthdays today.\n";
        exit;
    }
    
    // Get all users to send notifications
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
        
        echo "Birthday notification sent for {$birthday['person_name']}\n";
    }
    
    echo "Total birthdays processed: " . count($birthdays) . "\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
