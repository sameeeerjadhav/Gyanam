<?php
/**
 * Authentication & Session Helpers — Gyanam Portal
 */

function startSecureSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Strict');
        session_start();
    }
}

function isLoggedIn(): bool {
    startSecureSession();
    return isset($_SESSION['user_id'], $_SESSION['user_role']);
}

function getUserId(): ?int {
    return $_SESSION['user_id'] ?? null;
}

function getUserRole(): ?string {
    return $_SESSION['user_role'] ?? null;
}

function getUserName(): ?string {
    return $_SESSION['user_name'] ?? null;
}

function getLoginUsername(): ?string {
    return $_SESSION['username'] ?? null;
}

/**
 * Guard: require user to be logged in with one of the allowed roles.
 * Redirects to login page with error if not authorized.
 */
function requireLogin(array $allowedRoles = []): void {
    startSecureSession();
    if (!isLoggedIn()) {
        $_SESSION['login_error'] = 'Please log in to continue.';
        header('Location: /index.php');
        exit;
    }
    if (!empty($allowedRoles) && !in_array($_SESSION['user_role'], $allowedRoles, true)) {
        $_SESSION['login_error'] = 'Access denied. Insufficient permissions.';
        session_destroy();
        header('Location: /index.php');
        exit;
    }
}

/**
 * Generate a CSRF token and store in session.
 */
function generateCSRFToken(): string {
    startSecureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate submitted CSRF token.
 */
function validateCSRFToken(string $token): bool {
    startSecureSession();
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Rate-limit login attempts (session-based).
 * Returns true if locked out.
 */
function isLoginLocked(): bool {
    startSecureSession();
    if (isset($_SESSION['login_lockout']) && time() < $_SESSION['login_lockout']) {
        return true;
    }
    if (isset($_SESSION['login_lockout']) && time() >= $_SESSION['login_lockout']) {
        unset($_SESSION['login_attempts'], $_SESSION['login_lockout']);
    }
    return false;
}

function recordFailedAttempt(): void {
    startSecureSession();
    $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
    if ($_SESSION['login_attempts'] >= 5) {
        $_SESSION['login_lockout'] = time() + 60; // 60-second lockout
    }
}

function clearLoginAttempts(): void {
    startSecureSession();
    unset($_SESSION['login_attempts'], $_SESSION['login_lockout']);
}

/**
 * Map DB role to dashboard URL.
 */
function getDashboardURL(string $role): string {
    $map = [
        'Admin'      => '/admin/',
        'DLC Office' => '/dlc/',
        'ATC CENTER' => '/atc/',
        'Training'   => '/training/',
    ];
    return $map[$role] ?? '/index.php';
}

/**
 * Map DB role to display name.
 */
function getRoleDisplayName(string $role): string {
    $map = [
        'Admin'      => 'Head Office',
        'DLC Office' => 'DLC Login',
        'ATC CENTER' => 'ATC Login',
        'Training'   => 'Training Login',
    ];
    return $map[$role] ?? $role;
}
