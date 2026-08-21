<?php
/**
 * Gyanam Portal — Authentication Handler
 * Handles POST login, CSRF validation, rate limiting, and secure session setup.
 */
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

startSecureSession();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/index.php');
}

// CSRF validation
$csrfToken = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($csrfToken)) {
    $_SESSION['login_error'] = 'Invalid request. Please try again.';
    redirect('/index.php');
}

// Rate limiting
if (isLoginLocked()) {
    $_SESSION['login_error'] = 'Too many failed attempts. Please wait 60 seconds.';
    redirect('/index.php');
}

// Sanitize input
$username = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');
$role     = trim($_POST['role'] ?? '');

// Validate input
$validRoles = ['Admin', 'DLC Office', 'ATC CENTER', 'Training'];
if (empty($username) || empty($password) || !in_array($role, $validRoles, true)) {
    $_SESSION['login_error'] = 'Please fill in all fields and select a role.';
    redirect('/index.php');
}

try {
    $pdo = getDBConnection();
    // Try with profile_photo first, fall back without it if column doesn't exist
    try {
        $stmt = $pdo->prepare('SELECT id, username, password, role, name, email, mobile, profile_photo, dlc_id, atc_id FROM users WHERE username = ? AND role = ? LIMIT 1');
        $stmt->execute([$username, $role]);
        $user = $stmt->fetch();
    } catch (PDOException $e) {
        // profile_photo column might not exist yet
        $stmt = $pdo->prepare('SELECT id, username, password, role, name, email, mobile, dlc_id, atc_id FROM users WHERE username = ? AND role = ? LIMIT 1');
        $stmt->execute([$username, $role]);
        $user = $stmt->fetch();
    }

    if (!$user) {
        recordFailedAttempt();
        $_SESSION['login_error'] = 'Invalid username, password, or role.';
        redirect('/index.php');
    }

    // Password verification — supports both bcrypt hashed and plaintext (legacy)
    $passwordValid = false;
    if (password_get_info($user['password'])['algo'] !== null && password_get_info($user['password'])['algo'] !== 0) {
        // Bcrypt hashed password
        $passwordValid = password_verify($password, $user['password']);
    } else {
        // Legacy plaintext comparison (for existing seeded data)
        $passwordValid = ($password === $user['password']);
    }

    if (!$passwordValid) {
        recordFailedAttempt();
        $_SESSION['login_error'] = 'Invalid username, password, or role.';
        redirect('/index.php');
    }

    // Success — regenerate session ID to prevent fixation
    session_regenerate_id(true);
    clearLoginAttempts();

    // Clear CSRF token so a new one is generated
    unset($_SESSION['csrf_token']);

    // Store user data in session
    $_SESSION['user_id']       = $user['id'];
    $_SESSION['username']      = $user['username'];
    $_SESSION['user_role']     = $user['role'];
    $_SESSION['user_name']     = $user['name'] ?? $user['username'];
    $_SESSION['user_email']    = $user['email'];
    $_SESSION['user_mobile']   = $user['mobile'];
    $_SESSION['user_photo']    = $user['profile_photo'] ?? null;
    $_SESSION['dlc_id']        = $user['dlc_id'];
    $_SESSION['atc_id']        = $user['atc_id'];
    $_SESSION['login_time']    = time();

    // Redirect to appropriate dashboard
    redirect(getDashboardURL($user['role']));

} catch (PDOException $e) {
    error_log('Login Error: ' . $e->getMessage());
    $_SESSION['login_error'] = 'A system error occurred. Please try again.';
    redirect('/index.php');
}
