<?php
/**
 * Gyanam Portal — Logout
 * Destroy session, clear cookies, redirect to login.
 */
require_once __DIR__ . '/includes/auth.php';

startSecureSession();

// Destroy all session data
$_SESSION = [];

// Delete session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}

session_destroy();

// Redirect to login
header('Location: /index.php');
exit;
